<?php
/**
 * auth-session.php — who am I, and on what device.
 * Called by app.js on every page load; feeds the watermark and the
 * quota meter, so it must be cheap.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Suppresses a device_auth_log row per page load — see device.php.
$_SERVER['HTTP_X_DATAFORT_QUIET'] = '1';

$ctx = requireAuth($pdo, $CONFIG);
$user = $ctx['user'];

$used = $user['role'] === 'admin' ? 0 : revealsToday($pdo, $user['tenant_id'], $user['id']);

respond([
    'id'     => 'u-' . $user['id'],
    'userId' => $user['id'],
    'name'   => $user['name'],
    'role'   => $user['role'],
    'tenant' => $ctx['tenant']['name'],
    'quota'  => [
        'limit' => $user['quota'],
        'used'  => $used,
        'left'  => max(0, $user['quota'] - $used),
    ],
    'device' => $ctx['device'] ? [
        'code'    => $ctx['device']['device_code'],
        'serial'  => $ctx['device']['certificate_serial'],
        'expires' => $ctx['device']['expires_at'],
    ] : null,
    'deviceMode' => $ctx['tenant']['device_enforcement'],
]);
