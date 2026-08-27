<?php
/**
 * platform/session.php — who am I. Called by platform-app.js on every
 * page load, same role as api/auth-session.php for the tenant side.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$_SERVER['HTTP_X_DATAFORT_QUIET'] = '1';

$ctx   = platformRequireAuth($pdo, $CONFIG);
$admin = $ctx['admin'];

respond([
    'id'    => 'p-' . $admin['id'],
    'name'  => $admin['name'],
    'email' => $admin['email'],
    'device' => $ctx['device'] ? [
        'code'    => $ctx['device']['device_code'],
        'expires' => $ctx['device']['expires_at'],
    ] : null,
]);
