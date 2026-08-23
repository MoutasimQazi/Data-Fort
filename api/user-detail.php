<?php
/**
 * user-detail.php — everything about one user, in one request.
 *
 * Answers the questions an admin actually asks when they click a rep:
 * who are they, what did they do today, DID THEY FINISH YESTERDAY'S
 * LEADS, what have they been doing lately, and what are they signed in
 * from.
 *
 * The yesterday figure is the one worth understanding. A lead counts as
 * WORKED if its status moved off 'new', or it was contacted after it was
 * assigned. Having merely been revealed is NOT enough — looking up a
 * phone number and never calling it is exactly the pattern this product
 * exists to notice, so counting a reveal as work would hide the thing
 * the page is for.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$tid = $ctx['user']['tenant_id'];

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    respond(['error' => 'User id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$userId, $tid]);
$u = $stmt->fetch();

if (!$u) {
    respond(['error' => 'User not found'], 404);
}


/**
 * One day's assigned batch and how much of it was actually worked.
 *
 * $dayExpr is a literal SQL date expression, never user input — the two
 * call sites below are the only ones.
 */
function batch(PDO $pdo, int $tid, int $userId, string $dayExpr): array
{
    $q = $pdo->prepare(
        "SELECT
           COUNT(*) AS assigned,
           SUM(l.status <> 'new'
               OR (l.last_contacted IS NOT NULL AND l.last_contacted >= l.assigned_at)) AS worked,
           SUM(l.status = 'won')  AS won,
           SUM(l.status = 'lost') AS lost
         FROM leads l
         WHERE l.tenant_id = ? AND l.owner_id = ?
           AND DATE(l.assigned_at) = " . $dayExpr
    );
    $q->execute([$tid, $userId]);
    $r = $q->fetch() ?: [];

    $assigned = (int) ($r['assigned'] ?? 0);
    $worked   = (int) ($r['worked'] ?? 0);

    return [
        'assigned' => $assigned,
        'worked'   => $worked,
        'pending'  => max(0, $assigned - $worked),
        'won'      => (int) ($r['won'] ?? 0),
        'lost'     => (int) ($r['lost'] ?? 0),
        /* null, not 0, when nothing was assigned. "0% done" and "nothing
         * to do" are different states and must not look alike — one is a
         * problem and the other is a quiet day. */
        'percent'  => $assigned > 0 ? (int) round(($worked / $assigned) * 100) : null,
    ];
}

$today     = batch($pdo, $tid, $userId, 'CURDATE()');
$yesterday = batch($pdo, $tid, $userId, 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)');


/* ══ Reveals ═══════════════════════════════════════════════════════ */

$rev = $pdo->prepare(
    "SELECT
       SUM(reveal_date = CURDATE())                            AS today,
       SUM(reveal_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY))  AS yesterday,
       COUNT(*)                                                AS total
     FROM lead_reveals WHERE tenant_id = ? AND user_id = ?"
);
$rev->execute([$tid, $userId]);
$revealCounts = $rev->fetch() ?: [];


/* ══ Everything they hold ══════════════════════════════════════════ */

$tot = $pdo->prepare(
    "SELECT COUNT(*) AS held,
            SUM(status = 'new')     AS untouched,
            SUM(status = 'working') AS working,
            SUM(status = 'won')     AS won,
            SUM(status = 'lost')    AS lost
     FROM leads WHERE tenant_id = ? AND owner_id = ?"
);
$tot->execute([$tid, $userId]);
$totals = $tot->fetch() ?: [];


/* ══ 14 days ═══════════════════════════════════════════════════════ */

$trend = [];
for ($d = 13; $d >= 0; $d--) {
    $day = date('Y-m-d', strtotime("-$d days"));
    $trend[$day] = ['date' => $day, 'assigned' => 0, 'worked' => 0, 'reveals' => 0];
}

$a = $pdo->prepare(
    "SELECT DATE(assigned_at) AS d, COUNT(*) AS n, SUM(status <> 'new') AS w
     FROM leads
     WHERE tenant_id = ? AND owner_id = ?
       AND assigned_at > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY DATE(assigned_at)"
);
$a->execute([$tid, $userId]);
foreach ($a->fetchAll() as $r) {
    if (isset($trend[$r['d']])) {
        $trend[$r['d']]['assigned'] = (int) $r['n'];
        $trend[$r['d']]['worked']   = (int) $r['w'];
    }
}

$b = $pdo->prepare(
    "SELECT reveal_date AS d, COUNT(*) AS n FROM lead_reveals
     WHERE tenant_id = ? AND user_id = ?
       AND reveal_date > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY reveal_date"
);
$b->execute([$tid, $userId]);
foreach ($b->fetchAll() as $r) {
    if (isset($trend[$r['d']])) $trend[$r['d']]['reveals'] = (int) $r['n'];
}


/* ══ Activity log ══════════════════════════════════════════════════
 *
 * This user's slice of the audit log. Read-only, exactly like the log
 * itself — there is no path anywhere in this codebase that edits it. */

$log = $pdo->prepare(
    "SELECT action, subject, detail, device_code, ip, at
     FROM audit_log
     WHERE tenant_id = ? AND actor_id = ?
     ORDER BY at DESC LIMIT 100"
);
$log->execute([$tid, $userId]);


/* ══ Devices and live sessions ═════════════════════════════════════ */

$dev = $pdo->prepare(
    "SELECT device_code, certificate_serial, status, expires_at, last_seen_at
     FROM company_devices WHERE tenant_id = ? AND employee_id = ?
     ORDER BY device_code"
);
$dev->execute([$tid, $userId]);

$sess = $pdo->prepare(
    "SELECT id, ip, user_agent, device_serial, created_at, last_seen_at, expires_at
     FROM sessions
     WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
     ORDER BY last_seen_at DESC"
);
$sess->execute([$userId]);

$sessions = array_map(function (array $s) {
    return [
        /* A short hash, never the session id. The id IS the credential —
         * anyone who could read it off this page could set it as a
         * cookie and become that user. The hash is enough to tell two
         * sessions apart and to revoke one by reference. */
        'ref'        => substr(hash('sha256', $s['id']), 0, 8),
        'ip'         => $s['ip'],
        'userAgent'  => substr((string) $s['user_agent'], 0, 120),
        'device'     => $s['device_serial'],
        'createdAt'  => $s['created_at'],
        'lastSeenAt' => $s['last_seen_at'],
        'expiresAt'  => $s['expires_at'],
    ];
}, $sess->fetchAll());


/* How many unassigned leads are left. The "assign today's leads" button
 * needs to know whether the pool can cover the target. */
$pool = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND owner_id IS NULL");
$pool->execute([$tid]);


respond([
    'user' => [
        'id'          => (int) $u['id'],
        'name'        => $u['name'],
        'email'       => $u['email'],
        'role'        => $u['role'],
        'status'      => $u['status'],
        'quota'       => (int) $u['daily_quota'],
        'dailyTarget' => (int) ($u['daily_lead_target'] ?? 0),
        'lastSeen'    => $u['last_seen_at'],
        'createdAt'   => $u['created_at'],
    ],
    'today'     => $today,
    'yesterday' => $yesterday,
    'reveals'   => [
        'today'     => (int) ($revealCounts['today'] ?? 0),
        'yesterday' => (int) ($revealCounts['yesterday'] ?? 0),
        'total'     => (int) ($revealCounts['total'] ?? 0),
    ],
    'totals' => [
        'held'      => (int) ($totals['held'] ?? 0),
        'untouched' => (int) ($totals['untouched'] ?? 0),
        'working'   => (int) ($totals['working'] ?? 0),
        'won'       => (int) ($totals['won'] ?? 0),
        'lost'      => (int) ($totals['lost'] ?? 0),
    ],
    'trend'         => array_values($trend),
    'log'           => $log->fetchAll(),
    'devices'       => $dev->fetchAll(),
    'sessions'      => $sessions,
    'poolAvailable' => (int) $pool->fetchColumn(),
]);
