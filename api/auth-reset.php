<?php
/**
 * auth-reset.php — consume a reset token and set the new password.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$in       = body();
$token    = (string) ($in['token'] ?? '');
$password = (string) ($in['password'] ?? '');

if ($token === '' || $password === '') {
    respond(['error' => 'Invalid request'], 400);
}

/* Policy is enforced here as well as in the browser. reset.html shows a
 * strength meter, but a meter is a courtesy — this is the rule. */
if (strlen($password) < 12
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^\w\s]/', $password)) {
    respond(['error' => 'Password must be at least 12 characters with upper and lower case, a number and a symbol.'], 400);
}

$hash = hash('sha256', $token);

$stmt = $pdo->prepare(
    "SELECT r.*, u.tenant_id, u.name, u.email
     FROM password_resets r
     JOIN users u ON u.id = r.user_id
     WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
     LIMIT 1"
);
$stmt->execute([$hash]);
$reset = $stmt->fetch();

if (!$reset) {
    // Expired, already used, or never existed — one message for all three.
    respond(['error' => 'This reset link is no longer valid. Request a new one.'], 400);
}

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);

    // Single use.
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?")
        ->execute([$hash]);

    /* Every other session for this user dies. If the reset happened
     * because someone else had the password, leaving their session alive
     * makes the whole exercise pointless. */
    $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
        ->execute([(int) $reset['user_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[datafort] password reset failed: ' . $e->getMessage());
    respond(['error' => 'Could not update the password. Try again.'], 500);
}

audit($pdo, (int) $reset['tenant_id'],
    ['id' => (int) $reset['user_id'], 'name' => $reset['name']],
    'login', $reset['email'], 'Password changed — all sessions revoked');

respond(['ok' => true]);
