<?php
/**
 * audit-list.php — the platform-level audit trail. Same read-only,
 * no-export discipline as api/audit-list.php, applied to actions
 * taken on the customer registry rather than inside any one tenant.
 * Optional ?tenant= filters to one customer's slice.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$ctx = platformRequireAuth($pdo, $CONFIG);

$q      = trim((string) ($_GET['q'] ?? ''));
$action = (string) ($_GET['action'] ?? '');
$tenant = (int) ($_GET['tenant'] ?? 0);
$limit  = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$where  = ['1=1'];
$params = [];

if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if ($tenant > 0) {
    $where[] = 'tenant_id = ?';
    $params[] = $tenant;
}
if ($q !== '') {
    $where[] = '(actor_name LIKE ? OR subject LIKE ? OR ip LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$count = $pdo->prepare("SELECT COUNT(*) FROM platform_audit_log WHERE $whereSql");
$count->execute($params);

$stmt = $pdo->prepare(
    "SELECT a.*, t.subdomain_slug FROM platform_audit_log a
     LEFT JOIN platform_tenants t ON t.id = a.tenant_id
     WHERE $whereSql ORDER BY a.at DESC LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);

respond([
    'total'   => (int) $count->fetchColumn(),
    'entries' => array_map(function (array $a): array {
        return [
            'id'      => 'pa-' . $a['id'],
            'action'  => $a['action'],
            'actor'   => $a['actor_name'],
            'tenant'  => $a['subdomain_slug'],
            'subject' => $a['subject'],
            'text'    => $a['detail'],
            'ip'      => $a['ip'],
            'at'      => $a['at'],
        ];
    }, $stmt->fetchAll()),
]);
