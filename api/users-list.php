<?php
/**
 * users-list.php — accounts, quotas and today's usage. Admin only.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$tid = $ctx['user']['tenant_id'];

/* Usage is counted from the reveal ledger, not from a column on users.
 * Same number the audit log would give an investigator, and it cannot
 * drift out of step with reality. */
$stmt = $pdo->prepare(
    "SELECT u.*,
            (SELECT COUNT(*) FROM leads l WHERE l.owner_id = u.id) AS assigned,
            (SELECT COUNT(*) FROM lead_reveals r
              WHERE r.user_id = u.id AND r.reveal_date = CURDATE()) AS used_today,
            (SELECT COUNT(*) FROM company_devices d
              WHERE d.employee_id = u.id AND d.status = 'active') AS devices
     FROM users u
     WHERE u.tenant_id = ?
     ORDER BY FIELD(u.role,'admin','rep'), u.name"
);
$stmt->execute([$tid]);

respond([
    'users' => array_map(function (array $u): array {
        return [
            'id'        => 'u-' . $u['id'],
            'userId'    => (int) $u['id'],
            'name'      => $u['name'],
            'email'     => $u['email'],
            'role'      => $u['role'],
            'quota'     => (int) $u['daily_quota'],
            'usedToday' => (int) $u['used_today'],
            'assigned'  => (int) $u['assigned'],
            'devices'   => (int) $u['devices'],
            'status'    => $u['status'],
            'lastSeen'  => $u['last_seen_at'],
        ];
    }, $stmt->fetchAll()),
]);
