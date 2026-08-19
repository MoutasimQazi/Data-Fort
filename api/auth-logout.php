<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

/* Logout does NOT call requireAuth(): a user whose session has already
 * expired, or whose device was just revoked, must still be able to
 * clear their cookie. Refusing to log someone out because they are not
 * properly logged in is a small absurdity with real support cost. */
$config = $CONFIG;
$sid = $_COOKIE[$config['session']['cookie']] ?? '';

if ($sid !== '') {
    $stmt = $pdo->prepare(
        "SELECT s.user_id, s.tenant_id, u.name FROM sessions s
         JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([$sid]);
    if ($row = $stmt->fetch()) {
        audit($pdo, (int) $row['tenant_id'],
            ['id' => $row['user_id'], 'name' => $row['name']], 'login', null, 'Signed out');
    }
}

endSession($pdo, $config);
respond(['ok' => true]);
