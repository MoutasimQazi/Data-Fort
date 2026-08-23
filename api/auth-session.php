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

/* Counted for everyone, admins included — lead-reveal.php now writes a
 * ledger row for every reveal regardless of role, so reporting 0 here
 * would make this endpoint disagree with the table an investigator
 * reads. An uncapped admin shows a real count against a limit of 0. */
$used = revealsToday($pdo, $user['tenant_id'], $user['id']);

respond([
    'id'     => 'u-' . $user['id'],
    'userId' => $user['id'],
    'name'   => $user['name'],
    'role'   => $user['role'],
    'tenant' => $ctx['tenant']['name'],
    'quota'  => [
        'limit' => $user['quota'],
        'used'  => $used,
        // -1 means uncapped: an admin on daily_quota = 0. For a rep the
        // same 0 means blocked, which is why the role has to be checked
        // rather than the number alone.
        'left'  => ($user['role'] === 'admin' && (int) $user['quota'] === 0)
                       ? -1
                       : max(0, $user['quota'] - $used),
    ],
    'device' => $ctx['device'] ? [
        'code'    => $ctx['device']['device_code'],
        'serial'  => $ctx['device']['certificate_serial'],
        'expires' => $ctx['device']['expires_at'],
    ] : null,
    'deviceMode' => $ctx['tenant']['device_enforcement'],
]);
