<?php
/**
 * auth-login.php — Level 2 sign-in, behind Level 1 device auth.
 *
 * The device has already been checked by the time the password is
 * looked at. That ordering is the point of the whole design: a stolen
 * password is useless without the laptop, and a stolen laptop is
 * useless without the password.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

// Refuse phones before a password is even read. A rep on a handset
// should be told why, not left guessing at a rejected credential.
requireDesktop();

$in       = body();
$email    = strtolower(trim((string) ($in['email'] ?? '')));
$password = (string) ($in['password'] ?? '');
$trust    = !empty($in['trust_device']);
$fp       = isset($in['device_fp']) ? (string) $in['device_fp'] : null;

if ($email === '' || $password === '') {
    respond(['message' => 'Email or password is incorrect.'], 401);
}

$tenant = currentTenant($pdo, $CONFIG);

/* ── Level 1: device ──
 * Runs before the password is even hashed. An unauthorised laptop never
 * gets to make a password guess, which also means it never contributes
 * to the throttle counters for a real employee's account. */
$mode  = $tenant['device_enforcement'] ?? $CONFIG['device_enforcement_fallback'];
$check = verifyDevice($pdo, (int) $tenant['id'], $mode);

if (!$check['ok']) {
    respond([
        'message'      => $check['message'],
        'device_error' => $check['reason'],
    ], 403);
}
$device = $check['device'];

/* ── Throttle ── */
if (throttled($pdo, $CONFIG, $email)) {
    respond([
        'message' => 'Too many attempts. Try again shortly — your administrator has been notified.',
    ], 429);
}

/* ── Level 2: credentials ── */
$stmt = $pdo->prepare(
    "SELECT * FROM users WHERE tenant_id = ? AND email = ? LIMIT 1"
);
$stmt->execute([(int) $tenant['id'], $email]);
$user = $stmt->fetch();

/* Same failure path and roughly the same cost whether the account
 * exists or not. password_verify against a dummy hash keeps the timing
 * from revealing which emails are real — otherwise this endpoint
 * becomes a way to enumerate staff at the target company. */
$hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
$ok   = password_verify($password, $hash) && $user !== false;

if (!$ok) {
    recordAttempt($pdo, $email, false);
    respond(['message' => 'Email or password is incorrect.'], 401);
}

if ($user['status'] === 'suspended') {
    recordAttempt($pdo, $email, false);
    audit($pdo, (int) $tenant['id'], $user, 'blocked', 'login', 'Suspended account attempted sign-in', $device);
    respond(['message' => 'This account is locked. Contact your administrator.'], 423);
}

/* ── Device/employee pairing ──
 * A registered laptop assigned to someone else is refused. This is what
 * makes "which employee was on which machine" answerable rather than a
 * guess, and it stops one rep signing in on another rep's laptop to
 * blur the audit trail. */
if ($device && !empty($device['employee_id']) &&
    (int) $device['employee_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
    audit($pdo, (int) $tenant['id'], $user, 'blocked', $device['device_code'],
        'Signed in on a laptop assigned to another employee', $device);
    respond([
        'message'      => 'This laptop is assigned to a different employee.',
        'device_error' => 'device_wrong_employee',
    ], 403);
}

/* Rehash if the cost factor has moved on since this password was set. */
if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
}

recordAttempt($pdo, $email, true);
startSession($pdo, $CONFIG, $user, $device, $trust, $fp);

audit($pdo, (int) $tenant['id'], $user, 'login',
    $device['device_code'] ?? 'no-device',
    'Signed in' . ($device ? ' from ' . $device['device_code'] : ' (device check ' . $mode . ')'),
    $device);

respond([
    'ok'       => true,
    'redirect' => $user['role'] === 'admin' ? 'index.html' : 'my-leads.html',
    'user'     => [
        'id'     => (int) $user['id'],
        'name'   => $user['name'],
        'role'   => $user['role'],
        'tenant' => $tenant['name'],
    ],
    'device'   => $device ? [
        'code'    => $device['device_code'],
        'expires' => $device['expires_at'],
    ] : null,
]);
