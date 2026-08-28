<?php
/**
 * tenants-list.php — the enterprises grid.
 *
 * Deliberately does NOT join into any tenant's own database for counts
 * (leads, users, devices) — this panel's whole trust story is that the
 * platform operator cannot see a customer's data, and that has to be
 * true of the list view too, not just the endpoints that could reach
 * further. Everything here comes from platform_tenants alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$ctx = platformRequireAuth($pdo, $CONFIG);

$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');

$where  = ['1=1'];
$params = [];

if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(name LIKE ? OR subdomain_slug LIKE ? OR contact_email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$stmt = $pdo->prepare(
    "SELECT t.*, p.name AS plan_name, p.max_reps AS plan_max_reps
       FROM platform_tenants t
       LEFT JOIN platform_plans p ON p.id = t.plan_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY t.name"
);
$stmt->execute($params);

respond([
    'tenants' => array_map(function (array $t): array {
        return [
            'id'           => (int) $t['id'],
            'name'         => $t['name'],
            'slug'         => $t['subdomain_slug'],
            'status'       => $t['status'],
            // The linked catalog plan's name wins when set; the
            // free-text fallback (a custom/negotiated deal) otherwise.
            'plan'         => $t['plan_name'] ?? $t['plan'],
            'planId'       => $t['plan_id'] ? (int) $t['plan_id'] : null,
            'planMaxReps'  => $t['plan_max_reps'] !== null ? (int) $t['plan_max_reps'] : null,
            'contactName'  => $t['contact_name'],
            'contactEmail' => $t['contact_email'],
            'createdAt'    => $t['created_at'],
            'provisioning' => [
                'dbCreated'    => $t['db_provisioned_at'],
                'adminSeeded'  => $t['admin_seeded_at'],
                'caScaffolded' => $t['ca_scaffolded_at'],
                'vhostLive'    => $t['vhost_live_at'],
            ],
        ];
    }, $stmt->fetchAll()),
]);
