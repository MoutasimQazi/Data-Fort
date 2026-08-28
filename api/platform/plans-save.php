<?php
/**
 * plans-save.php — create, edit, retire or delete a pricing plan.
 *
 * "Retire" (is_active=0) vs "delete" are different actions on purpose:
 * a plan already assigned to a tenant can't be deleted (the FK from
 * platform_tenants.plan_id would either block it or silently null out
 * that tenant's plan) without that being a deliberate choice, so
 * retiring — hide from new assignment, keep existing tenants explicable
 * — is the safe default and delete is only offered when nothing points
 * at the plan.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

$ctx   = platformRequireAuth($pdo, $CONFIG);
$admin = $ctx['admin'];

$in     = body();
$action = (string) ($in['action'] ?? '');

function readPlanInput(array $in): array
{
    $name  = trim((string) ($in['name'] ?? ''));
    $price = trim((string) ($in['priceLabel'] ?? ''));
    $maxRepsRaw = $in['maxReps'] ?? null;
    $maxReps = ($maxRepsRaw === null || $maxRepsRaw === '') ? null : max(0, (int) $maxRepsRaw);
    $features = trim((string) ($in['features'] ?? ''));
    $sortOrder = max(0, (int) ($in['sortOrder'] ?? 0));

    if ($name === '' || $price === '') {
        respond(['error' => 'Plan name and a price label are both required.'], 400);
    }

    return [$name, $price, $maxReps, $features !== '' ? $features : null, $sortOrder];
}


/* ══ Create ════════════════════════════════════════════════════════ */

if ($action === 'create') {
    [$name, $price, $maxReps, $features, $sortOrder] = readPlanInput($in);

    $dupe = $pdo->prepare("SELECT id FROM platform_plans WHERE name = ?");
    $dupe->execute([$name]);
    if ($dupe->fetch()) {
        respond(['error' => 'A plan with that name already exists.'], 409);
    }

    $pdo->prepare(
        "INSERT INTO platform_plans (name, price_label, max_reps, features, sort_order, is_active)
         VALUES (?,?,?,?,?, 1)"
    )->execute([$name, $price, $maxReps, $features, $sortOrder]);

    $newId = (int) $pdo->lastInsertId();
    platformAudit($pdo, $admin, 'plan_create', $name,
        'Created — ' . ($maxReps === null ? 'unlimited reps' : "up to $maxReps reps"));

    respond(['ok' => true, 'id' => $newId]);
}


/* ══ Existing plan ═════════════════════════════════════════════════ */

$targetId = (int) ($in['id'] ?? 0);
if ($targetId <= 0) {
    respond(['error' => 'Plan id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_plans WHERE id = ? LIMIT 1");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    respond(['error' => 'Plan not found'], 404);
}

switch ($action) {

    case 'update':
        [$name, $price, $maxReps, $features, $sortOrder] = readPlanInput($in);

        $dupe = $pdo->prepare("SELECT id FROM platform_plans WHERE name = ? AND id != ?");
        $dupe->execute([$name, $targetId]);
        if ($dupe->fetch()) {
            respond(['error' => 'Another plan already uses that name.'], 409);
        }

        $pdo->prepare(
            "UPDATE platform_plans SET name=?, price_label=?, max_reps=?, features=?, sort_order=? WHERE id=?"
        )->execute([$name, $price, $maxReps, $features, $sortOrder, $targetId]);

        platformAudit($pdo, $admin, 'plan_update', $name, 'Edited', null);
        break;

    case 'retire':
    case 'restore':
        $pdo->prepare("UPDATE platform_plans SET is_active = ? WHERE id = ?")
            ->execute([$action === 'restore' ? 1 : 0, $targetId]);
        platformAudit($pdo, $admin, 'plan_' . $action, $target['name'],
            $action === 'retire' ? 'Hidden from new assignment' : 'Reopened for new assignment', null);
        break;

    case 'delete':
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM platform_tenants WHERE plan_id = ?");
        $inUse->execute([$targetId]);
        if ((int) $inUse->fetchColumn() > 0) {
            respond(['error' => 'This plan is assigned to at least one tenant. Retire it instead of deleting it.'], 409);
        }
        $pdo->prepare("DELETE FROM platform_plans WHERE id = ?")->execute([$targetId]);
        platformAudit($pdo, $admin, 'plan_delete', $target['name'], 'Deleted — no tenant was on it', null);
        break;

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
