<?php
/**
 * dashboard.php — everything index.html needs, in one request.
 *
 * One endpoint rather than six because the dashboard is the first thing
 * an admin loads and six round trips on a cPanel host is a visibly slow
 * page. All the queries are indexed and bounded.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$_SERVER['HTTP_X_DATAFORT_QUIET'] = '1';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$tid = $ctx['user']['tenant_id'];


/* ══ Headline counts ═══════════════════════════════════════════════ */

$counts = $pdo->prepare(
    "SELECT
       COUNT(*)                                    AS total,
       SUM(owner_id IS NOT NULL)                   AS assigned,
       SUM(status = 'new')                         AS s_new,
       SUM(status = 'working')                     AS s_working,
       SUM(status = 'won')                         AS s_won,
       SUM(status = 'lost')                        AS s_lost
     FROM leads WHERE tenant_id = ?"
);
$counts->execute([$tid]);
$c = $counts->fetch() ?: [];

/* EVERYONE, not just reps.
 *
 * Admin reveals now land in lead_reveals like anyone else's. Charting
 * only reps would mean the dashboard's "reveals today" quietly excluded
 * the one account with unrestricted access to every lead — which is the
 * account an investigation would most want to see. */
$reps = $pdo->prepare(
    "SELECT u.id, u.name, u.role, u.daily_quota, u.status,
            (SELECT COUNT(*) FROM lead_reveals r
              WHERE r.user_id = u.id AND r.reveal_date = CURDATE()) AS used_today,
            /* When they last revealed something today. The two derived
             * alerts below are anchored to this rather than to the
             * request time — see the note there. */
            (SELECT MAX(r.at) FROM lead_reveals r
              WHERE r.user_id = u.id AND r.reveal_date = CURDATE()) AS last_reveal_at
     FROM users u
     WHERE u.tenant_id = ?
     ORDER BY used_today DESC"
);
$reps->execute([$tid]);
$repRows = $reps->fetchAll();


/* ══ Return by source ══════════════════════════════════════════════
 *
 * Revenue is attributed as the sum of source_cost on won leads is NOT
 * revenue — there is no revenue column in the schema yet. Rather than
 * invent one, this reports what the data actually supports: spend,
 * lead count, and won count per source. The dashboard shows cost per
 * won lead instead of a fabricated ROI multiple.
 */
$sources = $pdo->prepare(
    "SELECT s.name AS source,
            s.cost_total AS cost,
            COUNT(l.id) AS leads,
            SUM(l.status = 'won') AS won,
            s.source_destroyed_at
     FROM lead_sources s
     LEFT JOIN leads l ON l.source_id = s.id
     WHERE s.tenant_id = ?
     GROUP BY s.id
     ORDER BY s.cost_total DESC"
);
$sources->execute([$tid]);
$sourceRows = $sources->fetchAll();


/* ══ 14-day trend ══════════════════════════════════════════════════ */

$trend = [];
for ($d = 13; $d >= 0; $d--) {
    $trend[date('Y-m-d', strtotime("-$d days"))] = ['date' => date('Y-m-d', strtotime("-$d days")),
                                                    'reveals' => 0, 'contacted' => 0];
}

$rev = $pdo->prepare(
    "SELECT reveal_date AS d, COUNT(*) AS n FROM lead_reveals
     WHERE tenant_id = ? AND reveal_date > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY reveal_date"
);
$rev->execute([$tid]);
foreach ($rev->fetchAll() as $r) {
    if (isset($trend[$r['d']])) $trend[$r['d']]['reveals'] = (int) $r['n'];
}

$con = $pdo->prepare(
    "SELECT DATE(at) AS d, COUNT(*) AS n FROM audit_log
     WHERE tenant_id = ? AND action IN ('status','view','email')
       AND at > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY DATE(at)"
);
$con->execute([$tid]);
foreach ($con->fetchAll() as $r) {
    if (isset($trend[$r['d']])) $trend[$r['d']]['contacted'] = (int) $r['n'];
}


/* ══ Anomalies ═════════════════════════════════════════════════════
 *
 * Derived at read time from what actually happened, rather than stored
 * as alerts. An alert row can go stale; a query over the last 24 hours
 * is always current. */

$burst = (int) ($ctx['tenant']['burst_alert_limit'] ?? 15);
$anomalies = [];

// Quota exhausted
foreach ($repRows as $r) {
    if ((int) $r['daily_quota'] > 0 && (int) $r['used_today'] >= (int) $r['daily_quota']) {
        $anomalies[] = [
            'level' => 'red', 'user' => $r['name'],
            'text'  => 'Daily reveal quota spent — ' . $r['used_today'] . ' of ' . $r['daily_quota'] . '.',
            /* The moment they actually hit the cap, NOT the moment this
             * page was loaded. date('c') here meant the timestamp was
             * regenerated on every request, so it was always newer than
             * alerts_seen_at and the badge could never be cleared — a
             * permanently red 3 that trains people to ignore it.
             *
             * It also mixed PHP-local time into a comparison against
             * MySQL NOW(), which is wrong again if the two clocks differ. */
            'at'    => $r['last_reveal_at'],
        ];
    }
}

// Reveals far outpacing work done
foreach ($repRows as $r) {
    $work = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE tenant_id = ? AND actor_id = ? AND action IN ('status','email')
           AND at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $work->execute([$tid, $r['id']]);
    $done = (int) $work->fetchColumn();

    if ((int) $r['used_today'] >= 10 && $done * 4 < (int) $r['used_today']) {
        $anomalies[] = [
            'level' => 'amber', 'user' => $r['name'],
            'text'  => $r['used_today'] . ' reveals today against ' . $done .
                       ' status updates — viewing far more than working.',
            // Same reasoning as above: a real event time, not request time.
            'at'    => $r['last_reveal_at'],
        ];
    }
}

// Client-side security signals worth surfacing
$sec = $pdo->prepare(
    "SELECT s.type, s.detail, s.at, u.name
     FROM security_events s LEFT JOIN users u ON u.id = s.user_id
     WHERE s.tenant_id = ?
       AND s.type IN ('devtools_opened','watermark_removed','honeytoken_revealed')
       AND s.at > DATE_SUB(NOW(), INTERVAL 1 DAY)
     ORDER BY s.at DESC LIMIT 10"
);
$sec->execute([$tid]);
$SEC_TEXT = [
    'devtools_opened'     => 'Opened browser developer tools on a lead page.',
    'watermark_removed'   => 'Screen watermark was removed from the page.',
    'honeytoken_revealed' => 'Revealed a seeded decoy record.',
];
foreach ($sec->fetchAll() as $s) {
    $anomalies[] = [
        'level' => $s['type'] === 'honeytoken_revealed' ? 'red' : 'amber',
        'user'  => $s['name'] ?: 'Unknown user',
        'text'  => $SEC_TEXT[$s['type']] ?? $s['type'],
        'at'    => $s['at'],
    ];
}

// Device denials
$den = $pdo->prepare(
    "SELECT device_code, reason, ip, at FROM device_auth_log
     WHERE outcome = 'denied' AND at > DATE_SUB(NOW(), INTERVAL 1 DAY)
     ORDER BY at DESC LIMIT 10"
);
$den->execute();
foreach ($den->fetchAll() as $d) {
    $anomalies[] = [
        'level' => 'red',
        'user'  => $d['device_code'] ?: 'Unknown device',
        'text'  => 'Device authentication denied (' . $d['reason'] . ') from ' . $d['ip'] . '.',
        'at'    => $d['at'],
    ];
}

// Imports whose source spreadsheet was never confirmed destroyed —
// requirements section 6. Quiet but important.
$live = $pdo->prepare(
    "SELECT name, file_name, created_at FROM lead_sources
     WHERE tenant_id = ? AND source_destroyed_at IS NULL AND file_name IS NOT NULL
     ORDER BY created_at DESC LIMIT 5"
);
$live->execute([$tid]);
foreach ($live->fetchAll() as $s) {
    $anomalies[] = [
        'level' => 'amber', 'user' => 'Import',
        'text'  => 'Source spreadsheet for "' . $s['name'] .
                   '" was never confirmed destroyed — an unprotected copy may still exist.',
        'at'    => $s['created_at'],
    ];
}

/* Cast before strtotime: an anomaly with no timestamp would otherwise
 * raise a deprecation on PHP 8.1+ and sort unpredictably. Both derived
 * alerts guarantee a value today, but a future one might not. */
usort($anomalies, function ($a, $b) {
    return strtotime((string) ($b['at'] ?? '')) <=> strtotime((string) ($a['at'] ?? ''));
});

/* ── Collapse repeats ──
 *
 * In 'log' mode every page load from an unenrolled browser writes a
 * device denial, so the feed fills with the same line over and over.
 * Seven identical rows do not carry seven times the information; they
 * bury the one row that is different.
 *
 * Grouped on (level, subject, text). The newest timestamp wins and a
 * count rides along, so "x7" is visible without seven rows of it. */
$grouped = [];
foreach ($anomalies as $a) {
    $key = $a['level'] . '|' . $a['user'] . '|' . $a['text'];

    if (isset($grouped[$key])) {
        $grouped[$key]['count']++;
        continue;                       // already sorted newest-first
    }
    $a['count'] = 1;
    $grouped[$key] = $a;
}
$anomalies = array_slice(array_values($grouped), 0, 12);

/* ── Unread count ──
 *
 * The badge counts anomalies NEWER than the last time this admin looked
 * at the dashboard. Read the old mark first, then move it — so this
 * response still shows the number, and the next one shows zero.
 *
 * Deliberately per-user: two admins should not clear each other's badge.
 */
$seenAt = $pdo->prepare("SELECT alerts_seen_at FROM users WHERE id = ?");
$seenAt->execute([$ctx['user']['id']]);
$lastSeen = $seenAt->fetchColumn();

$unread = 0;
foreach ($anomalies as $a) {
    $at = strtotime((string) ($a['at'] ?? ''));

    // An undated alert cannot be shown to be new, so it is not counted.
    // Better to under-report than to leave a badge that never clears.
    if ($at === false) continue;

    if (!$lastSeen || $at > strtotime((string) $lastSeen)) {
        $unread++;
    }
}

// Mark them read now that they have been sent to a screen.
$pdo->prepare("UPDATE users SET alerts_seen_at = NOW() WHERE id = ?")
    ->execute([$ctx['user']['id']]);


/* ══ Response ══════════════════════════════════════════════════════ */

/* Two different numbers, kept apart deliberately.
 *
 * quotaTotal is the sum of the DAILY CAPS, which only capped users
 * contribute to. revealsToday is every reveal by anyone.
 *
 * Adding an uncapped admin's reveals to the numerator while their zero
 * cap contributes nothing to the denominator would produce "52 of 40",
 * which reads as a broken meter rather than as what it is. So the
 * admin figure is reported separately and the front end shows it as its
 * own line. */
$revealsToday  = 0;   // capped users only — the number the meter is about
$adminReveals  = 0;   // uncapped users, reported separately
$quotaTotal    = 0;

foreach ($repRows as $r) {
    $capped = (int) $r['daily_quota'] > 0;

    if ($capped) {
        $revealsToday += (int) $r['used_today'];
        $quotaTotal   += (int) $r['daily_quota'];
    } else {
        $adminReveals += (int) $r['used_today'];
    }
}

// "Active reps" must mean reps. repRows now carries admins too.
$onlyReps = array_filter($repRows, function ($r) { return $r['role'] === 'rep'; });

respond([
    'tenant' => $ctx['tenant']['name'],
    'totals' => [
        'leads'        => (int) ($c['total'] ?? 0),
        'assigned'     => (int) ($c['assigned'] ?? 0),
        'unassigned'   => (int) ($c['total'] ?? 0) - (int) ($c['assigned'] ?? 0),
        'revealsToday'  => $revealsToday,
        'quotaTotal'    => $quotaTotal,
        // Uncapped reveals, almost always an administrator. Surfaced on
        // its own rather than folded into the meter above.
        'adminReveals'  => $adminReveals,
        'activeReps'   => count(array_filter($onlyReps, function ($r) { return $r['status'] === 'active'; })),
        'otherReps'    => count(array_filter($onlyReps, function ($r) { return $r['status'] !== 'active'; })),
        'dataSpend'    => (float) array_sum(array_column($sourceRows, 'cost')),
    ],
    'byStatus' => [
        'new'     => (int) ($c['s_new'] ?? 0),
        'working' => (int) ($c['s_working'] ?? 0),
        'won'     => (int) ($c['s_won'] ?? 0),
        'lost'    => (int) ($c['s_lost'] ?? 0),
    ],
    'reps' => array_map(function ($r) {
        return [
            'name'      => $r['name'],
            'usedToday' => (int) $r['used_today'],
            'quota'     => (int) $r['daily_quota'],
        ];
    }, $repRows),
    'sources' => array_map(function ($s) {
        $won = (int) $s['won'];
        return [
            'source'     => $s['source'],
            'cost'       => (float) $s['cost'],
            'leads'      => (int) $s['leads'],
            'won'        => $won,
            // Cost per won lead. Lower is better, which is the opposite
            // direction to most bar charts — the front end labels it so.
            'costPerWon' => $won > 0 ? round((float) $s['cost'] / $won, 2) : null,
        ];
    }, $sourceRows),
    'trend'     => array_values($trend),
    'anomalies'      => $anomalies,
    'unreadAlerts'   => $unread,
]);
