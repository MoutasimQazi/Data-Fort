<?php
/**
 * leads-save.php — mark a sales inquiry contacted/closed, or delete it.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

$ctx   = platformRequireAuth($pdo, $CONFIG);
$admin = $ctx['admin'];

$in     = body();
$action = (string) ($in['action'] ?? '');
$id     = (int) ($in['id'] ?? 0);

if ($id <= 0) {
    respond(['error' => 'Lead id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_leads WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    respond(['error' => 'Lead not found'], 404);
}

switch ($action) {

    case 'contacted':
    case 'closed':
    case 'new':
        $pdo->prepare("UPDATE platform_leads SET status = ? WHERE id = ?")->execute([$action, $id]);
        platformAudit($pdo, $admin, 'lead_status', $target['email'], "Marked $action");
        break;

    case 'delete':
        $pdo->prepare("DELETE FROM platform_leads WHERE id = ?")->execute([$id]);
        platformAudit($pdo, $admin, 'lead_delete', $target['email'], 'Deleted');
        break;

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
