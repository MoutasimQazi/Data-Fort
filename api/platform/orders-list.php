<?php
/**
 * orders-list.php — paid Stripe Checkout sessions, recorded by
 * stripe-webhook.php. Not the same thing as "provisioned" — that's a
 * manual step the admin marks once they've actually set the tenant up
 * via tenant.html, matched by the order's customer email/plan.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

platformRequireAuth($pdo, $CONFIG);

$status = (string) ($_GET['status'] ?? '');
$where  = ['1=1'];
$params = [];
if ($status !== '') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}

$stmt = $pdo->prepare(
    "SELECT o.*, p.name AS plan_current_name
       FROM platform_orders o
       LEFT JOIN platform_plans p ON p.id = o.plan_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY o.created_at DESC"
);
$stmt->execute($params);

respond([
    'orders' => array_map(function (array $o): array {
        return [
            'id'             => (int) $o['id'],
            'planName'       => $o['plan_current_name'] ?: $o['plan_name_snapshot'],
            'customerEmail'  => $o['customer_email'],
            'amountTotal'    => $o['amount_total'] !== null ? (int) $o['amount_total'] : null,
            'currency'       => $o['currency'],
            'paymentStatus'  => $o['payment_status'],
            'livemode'       => (bool) $o['livemode'],
            'status'         => $o['status'],
            'stripeSessionId' => $o['stripe_session_id'],
            'createdAt'      => $o['created_at'],
        ];
    }, $stmt->fetchAll()),
]);
