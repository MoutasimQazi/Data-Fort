<?php
/**
 * orders-save.php — mark a paid order provisioned, once the admin has
 * actually set the tenant up via tenant.html. Nothing here touches
 * platform_tenants — this is a checklist, not automation, on purpose
 * (see the plan's "explicitly out of scope" note on auto-provisioning).
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
    respond(['error' => 'Order id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_orders WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    respond(['error' => 'Order not found'], 404);
}

switch ($action) {

    case 'provisioned':
    case 'paid':
        $pdo->prepare("UPDATE platform_orders SET status = ? WHERE id = ?")->execute([$action, $id]);
        platformAudit($pdo, $admin, 'order_status', $target['customer_email'] ?? $target['stripe_session_id'], "Marked $action");
        break;

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
