<?php
/**
 * platform/login.php — sign-in for the platform panel.
 *
 * Same two-level order as api/auth-login.php: device first, password
 * second — see that file's header for why. No role check afterwards;
 * every platform_admins row is equally privileged.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');
requireDesktop();

$in       = body();
$email    = strtolower(trim((string) ($in['email'] ?? '')));
$password = (string) ($in['password'] ?? '');
$trust    = !empty($in['trust_device']);
$fp       = isset($in['device_fp']) ? (string) $in['device_fp'] : null;

if ($email === '' || $password === '') {
    respond(['message' => 'Email or password is incorrect.'], 401);
}

$mode  = $CONFIG['multi_tenant']['platform_device_enforcement'] ?? 'log';
$check = verifyPlatformDevice($pdo, $mode);
if (!$check['ok']) {
    respond(['message' => $check['message'], 'device_error' => $check['reason']], 403);
}
$device = $check['device'];

if (platformThrottled($pdo, $email)) {
    respond(['message' => 'Too many attempts. Try again shortly.'], 429);
}

$stmt = $pdo->prepare("SELECT * FROM platform_admins WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$admin = $stmt->fetch();

// Same constant-time-ish shape as auth-login.php: verify against a
// dummy hash when the account doesn't exist, so the response timing
// doesn't reveal which emails are real.
$hash = $admin['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
$ok   = password_verify($password, $hash) && $admin !== false;

if (!$ok) {
    platformRecordAttempt($pdo, $email, false);
    respond(['message' => 'Email or password is incorrect.'], 401);
}

if ($admin['status'] === 'suspended') {
    platformRecordAttempt($pdo, $email, false);
    respond(['message' => 'This account is suspended.'], 423);
}

if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    $pdo->prepare("UPDATE platform_admins SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
}

platformRecordAttempt($pdo, $email, true);
startPlatformSession($pdo, $CONFIG, $admin, $device, $trust, $fp);

platformAudit($pdo, $admin, 'login', $email,
    'Signed in' . ($device ? ' from ' . $device['device_code'] : ' (device check ' . $mode . ')'));

respond([
    'ok'       => true,
    'redirect' => 'index.html',
    'admin'    => ['id' => (int) $admin['id'], 'name' => $admin['name']],
]);
