<?php
/**
 * audit-list.php — read the append-only log. Admin only.
 *
 * Read is the ONLY verb. There is no audit-update.php and no
 * audit-delete.php, and there must never be one: a log an administrator
 * can prune is not evidence.
 *
 * There is also no export. An export of this table is a complete record
 * of every contact ever unmasked, which is the exact artefact the
 * product exists to prevent — handing it over as a CSV would undo the
 * whole design in one download.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$tid = $ctx['user']['tenant_id'];

$q      = trim((string) ($_GET['q'] ?? ''));
$action = (string) ($_GET['action'] ?? '');
$actor  = (int) ($_GET['actor'] ?? 0);
$limit  = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$where  = ['tenant_id = ?'];
$params = [$tid];

if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if ($actor > 0) {
    $where[] = 'actor_id = ?';
    $params[] = $actor;
}
if ($q !== '') {
    $where[] = '(actor_name LIKE ? OR subject LIKE ? OR ip LIKE ? OR device_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$count = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE $whereSql");
$count->execute($params);

$stmt = $pdo->prepare(
    "SELECT * FROM audit_log WHERE $whereSql ORDER BY at DESC LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);

respond([
    'total'   => (int) $count->fetchColumn(),
    'entries' => array_map(function (array $a): array {
        return [
            'id'      => 'a-' . $a['id'],
            'action'  => $a['action'],
            'actor'   => $a['actor_name'],
            'actorId' => $a['actor_id'] ? 'u-' . $a['actor_id'] : null,
            'subject' => $a['subject'],
            'text'    => $a['detail'],
            'device'  => $a['device_code'],
            'ip'      => $a['ip'],
            'at'      => $a['at'],
        ];
    }, $stmt->fetchAll()),
]);
