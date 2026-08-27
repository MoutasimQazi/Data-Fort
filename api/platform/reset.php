<?php
/**
 * reset.php — consume a platform invite/reset token and set a password.
 * Mirrors api/auth-reset.php exactly, targeting platform_admins /
 * platform_password_resets / platform_admin_sessions instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

$in       = body();
$token    = (string) ($in['token'] ?? '');
$password = (string) ($in['password'] ?? '');

if ($token === '' || $password === '') {
    respond(['error' => 'Invalid request'], 400);
}

if (strlen($password) < 12
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^\w\s]/', $password)) {
    respond(['error' => 'Password must be at least 12 characters with upper and lower case, a number and a symbol.'], 400);
}

$hash = hash('sha256', $token);

$stmt = $pdo->prepare(
    "SELECT r.*, a.name, a.email
     FROM platform_password_resets r
     JOIN platform_admins a ON a.id = r.admin_id
     WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
     LIMIT 1"
);
$stmt->execute([$hash]);
$reset = $stmt->fetch();

if (!$reset) {
    respond(['error' => 'This link is no longer valid. Ask another platform admin to resend it.'], 400);
}

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE platform_admins SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['admin_id']]);

    $pdo->prepare("UPDATE platform_password_resets SET used_at = NOW() WHERE token_hash = ?")
        ->execute([$hash]);

    $pdo->prepare("UPDATE platform_admin_sessions SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL")
        ->execute([(int) $reset['admin_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[datafort-platform] password reset failed: ' . $e->getMessage());
    respond(['error' => 'Could not save the password. Try again.'], 500);
}

platformAudit($pdo, ['id' => (int) $reset['admin_id'], 'name' => $reset['name']],
    'login', $reset['email'], 'Password set — all sessions revoked');

respond(['ok' => true]);
