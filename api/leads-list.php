<?php
/**
 * leads-list.php — the lead table, always masked.
 *
 * This endpoint NEVER returns a full phone number or email address, for
 * anyone, including administrators. lead-reveal.php is the only door
 * that opens, and it charges quota to do it. If a masking bug ever ships,
 * it will ship here — so the masking happens in one place, at the very
 * end, applied to every row without exception.
 *
 * Reps get their own leads. Admins get the whole tenant. Nobody gets
 * another tenant, because tenant_id is in the WHERE clause and there is
 * no code path that builds this query without it.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$_SERVER['HTTP_X_DATAFORT_QUIET'] = '1';

$ctx  = requireAuth($pdo, $CONFIG);
$user = $ctx['user'];

$q       = trim((string) ($_GET['q'] ?? ''));
$status  = (string) ($_GET['status'] ?? '');
$owner   = (string) ($_GET['owner'] ?? '');
$source  = (string) ($_GET['source'] ?? '');
$limit   = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
$offset  = max(0, (int) ($_GET['offset'] ?? 0));

$where  = ['l.tenant_id = ?'];
$params = [$user['tenant_id']];

/* Scope. A rep is pinned to their own rows here and cannot widen it —
 * the owner filter below is only honoured for admins. */
if ($user['role'] !== 'admin') {
    $where[] = 'l.owner_id = ?';
    $params[] = $user['id'];
} elseif ($owner === '__none') {
    $where[] = 'l.owner_id IS NULL';
} elseif ($owner !== '') {
    $where[] = 'l.owner_id = ?';
    $params[] = (int) $owner;
}

if (in_array($status, ['new', 'working', 'won', 'lost'], true)) {
    $where[] = 'l.status = ?';
    $params[] = $status;
}

if ($source !== '') {
    $where[] = 's.name = ?';
    $params[] = $source;
}

if ($q !== '') {
    /* Searching only the non-sensitive columns. Allowing a search across
     * the phone column would turn this endpoint into an oracle: type a
     * number, see whether it matches, and read contact data one guess at
     * a time without ever spending a reveal. */
    $where[] = '(l.name LIKE ? OR l.company LIKE ? OR l.ref LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM leads l LEFT JOIN lead_sources s ON s.id = l.source_id WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT l.*, s.name AS source_name, u.name AS owner_name
     FROM leads l
     LEFT JOIN lead_sources s ON s.id = l.source_id
     LEFT JOIN users u ON u.id = l.owner_id
     WHERE $whereSql
     ORDER BY l.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* Which fields has this user already paid to see? Used only to render
 * the eye icon as already-spent — the value itself still has to be
 * fetched from lead-reveal.php. */
$paid = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $r = $pdo->prepare(
        "SELECT lead_id, field FROM lead_reveals
         WHERE tenant_id = ? AND user_id = ? AND lead_id IN ($ph)"
    );
    $r->execute(array_merge([$user['tenant_id'], $user['id']], $ids));
    foreach ($r->fetchAll() as $row) {
        $paid[$row['lead_id']][] = $row['field'];
    }
}

$isAdmin = $user['role'] === 'admin';

respond([
    'total'  => $total,
    'offset' => $offset,
    'leads'  => array_map(function (array $l) use ($paid, $isAdmin): array {
        return [
            'id'          => $l['ref'],
            'name'        => $l['name'],
            'company'     => $l['company'],
            'designation' => $l['designation'],

            // Masked. Always. For everyone.
            'phoneMasked' => maskPhone($l['phone']),
            'emailMasked' => maskEmail($l['email']),
            'revealed'    => $paid[$l['id']] ?? [],

            'city'        => $l['city'],
            'industry'    => $l['industry'],
            'companySize' => $l['company_size'],
            'source'      => $l['source_name'],
            'sourceCost'  => (float) $l['source_cost'],
            'status'      => $l['status'],
            'ownerId'     => $l['owner_id'] ? 'u-' . $l['owner_id'] : null,
            'ownerName'   => $l['owner_name'],
            'acquiredDate'  => $l['acquired_date'],
            'lastContacted' => $l['last_contacted'],

            /* The decoy flag goes to admins only. A rep who can tell a
             * seeded record from a real one just avoids the seeded ones,
             * and the attribution trick stops working. */
            'honeytoken'  => $isAdmin ? (bool) $l['honeytoken'] : false,
        ];
    }, $rows),
]);
