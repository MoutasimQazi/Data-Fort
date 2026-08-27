<?php
/**
 * admins-list.php — the platform team grid: everyone who can sign in
 * to this panel. NOT tenant admins — those live inside each customer's
 * own database and this panel never connects to one. See devices-list.php
 * for the equivalent laptop-cert register.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$ctx = platformRequireAuth($pdo, $CONFIG);

$stmt = $pdo->prepare(
    "SELECT a.*,
            (SELECT COUNT(*) FROM platform_admin_sessions s
              WHERE s.admin_id = a.id AND s.revoked_at IS NULL AND s.expires_at > NOW()) AS live_sessions,
            EXISTS(SELECT 1 FROM platform_password_resets r
                    WHERE r.admin_id = a.id AND r.used_at IS NULL AND r.expires_at > NOW()) AS pending_invite
     FROM platform_admins a
     ORDER BY a.name"
);
$stmt->execute();

respond([
    'admins' => array_map(function (array $a) use ($ctx): array {
        return [
            'id'            => (int) $a['id'],
            'name'          => $a['name'],
            'email'         => $a['email'],
            'status'        => $a['status'],
            'lastSeenAt'    => $a['last_seen_at'],
            'createdAt'     => $a['created_at'],
            'liveSessions'  => (int) $a['live_sessions'],
            'pendingInvite' => (bool) $a['pending_invite'],
            'isYou'         => (int) $a['id'] === (int) $ctx['admin']['id'],
        ];
    }, $stmt->fetchAll()),
]);
