<?php
/**
 * users-list.php — the team grid. Admin only.
 *
 * Carries enough for the overview to answer "is anyone behind?" without
 * the admin opening a single user. The per-user detail lives in
 * user-detail.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$tid = $ctx['user']['tenant_id'];

/* Usage is counted from the reveal ledger, not from a column on users.
 * Same number the audit log would give an investigator, and it cannot
 * drift out of step with reality.
 *
 * The yesterday figures use the same definition of "worked" as
 * user-detail.php: status moved off 'new', OR contacted after the lead
 * was assigned. A reveal alone is NOT work — looking up a number and
 * never calling it is the pattern this product exists to surface, so
 * counting it as done would hide exactly what the column is for.
 *
 * If the two definitions ever diverge, this page and the detail page
 * will quietly disagree about the same rep. Change them together. */
$stmt = $pdo->prepare(
    "SELECT u.*,
            (SELECT COUNT(*) FROM leads l
              WHERE l.owner_id = u.id) AS assigned,

            (SELECT COUNT(*) FROM lead_reveals r
              WHERE r.user_id = u.id AND r.reveal_date = CURDATE()) AS used_today,

            (SELECT COUNT(*) FROM company_devices d
              WHERE d.employee_id = u.id AND d.status = 'active') AS devices,

            (SELECT COUNT(*) FROM leads l
              WHERE l.owner_id = u.id
                AND DATE(l.assigned_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            ) AS y_assigned,

            (SELECT COUNT(*) FROM leads l
              WHERE l.owner_id = u.id
                AND DATE(l.assigned_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                AND (l.status <> 'new'
                     OR (l.last_contacted IS NOT NULL AND l.last_contacted >= l.assigned_at))
            ) AS y_worked

     FROM users u
     WHERE u.tenant_id = ?
     ORDER BY FIELD(u.role,'admin','rep'), u.name"
);
$stmt->execute([$tid]);

respond([
    'users' => array_map(function (array $u): array {
        $yAssigned = (int) $u['y_assigned'];
        $yWorked   = (int) $u['y_worked'];

        return [
            'id'        => 'u-' . $u['id'],
            'userId'    => (int) $u['id'],
            'name'      => $u['name'],
            'email'     => $u['email'],
            'role'      => $u['role'],
            'quota'     => (int) $u['daily_quota'],
            // Workload, not exposure. See migration 003.
            'dailyTarget' => (int) ($u['daily_lead_target'] ?? 0),
            'usedToday' => (int) $u['used_today'],
            'assigned'  => (int) $u['assigned'],
            'devices'   => (int) $u['devices'],
            'status'    => $u['status'],
            'lastSeen'  => $u['last_seen_at'],
            'yesterday' => [
                'assigned' => $yAssigned,
                'worked'   => $yWorked,
                'pending'  => max(0, $yAssigned - $yWorked),
                // null, not 0, when nothing was assigned — "nothing to do"
                // and "did none of it" must not look the same.
                'percent'  => $yAssigned > 0
                    ? (int) round(($yWorked / $yAssigned) * 100)
                    : null,
            ],
        ];
    }, $stmt->fetchAll()),
]);
