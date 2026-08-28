<?php
/**
 * tenant-detail.php — one enterprise, in one request.
 *
 * Same "no reach into the tenant's own database" rule as
 * tenants-list.php: db_host/db_name are shown (useful when SSHing in
 * to run the provisioning script), db_pass_enc never is.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$ctx = platformRequireAuth($pdo, $CONFIG);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    respond(['error' => 'Tenant id required'], 400);
}

$stmt = $pdo->prepare(
    "SELECT t.*, p.name AS plan_name, p.max_reps AS plan_max_reps
       FROM platform_tenants t
       LEFT JOIN platform_plans p ON p.id = t.plan_id
      WHERE t.id = ? LIMIT 1"
);
$stmt->execute([$id]);
$t = $stmt->fetch();

if (!$t) {
    respond(['error' => 'Tenant not found'], 404);
}

respond([
    'id'           => (int) $t['id'],
    'name'         => $t['name'],
    'slug'         => $t['subdomain_slug'],
    'status'       => $t['status'],
    'plan'         => $t['plan'],
    'planId'       => $t['plan_id'] ? (int) $t['plan_id'] : null,
    'planName'     => $t['plan_name'],
    'planMaxReps'  => $t['plan_max_reps'] !== null ? (int) $t['plan_max_reps'] : null,
    'contactName'  => $t['contact_name'],
    'contactEmail' => $t['contact_email'],
    'database'     => [
        'host' => $t['db_host'],
        'name' => $t['db_name'] ?: null,
        'user' => $t['db_user'] ?: null,
    ],
    'caName'       => $t['ca_name'],
    'provisioning' => [
        'dbCreated'    => $t['db_provisioned_at'],
        'adminSeeded'  => $t['admin_seeded_at'],
        'caScaffolded' => $t['ca_scaffolded_at'],
        'vhostLive'    => $t['vhost_live_at'],
    ],
    'createdAt'    => $t['created_at'],
    'updatedAt'    => $t['updated_at'],
]);
