<?php
/**
 * auth-change-password.php — a signed-in user changes their own password.
 *
 * Until this existed the only route was the forgot-password email, which
 * meant anyone wanting to rotate a password had to trigger a reset and
 * wait for mail to arrive. On a product where the password is one of the
 * two things standing between a stranger and the lead list, changing it
 * should not require a detour through an inbox.
 *
 * Requires the CURRENT password. Without that, a borrowed unlocked
 * laptop is a permanent account takeover: walk up, change the password,
 * and the real owner is locked out of their own leads.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG);
$user = $ctx['user'];

$in      = body();
$current = (string) ($in['current'] ?? '');
$next    = (string) ($in['password'] ?? '');

if ($current === '' || $next === '') {
    respond(['error' => 'Enter your current password and the new one.'], 400);
}

/* Same policy the reset flow enforces. Stated in one place per file
 * rather than shared, because a helper that silently relaxes in one
 * caller is worse than the duplication. */
if (strlen($next) < 12
    || !preg_match('/[a-z]/', $next)
    || !preg_match('/[A-Z]/', $next)
    || !preg_match('/\d/', $next)
    || !preg_match('/[^\w\s]/', $next)) {
    respond([
        'error' => 'New password must be at least 12 characters with upper and lower case, a number and a symbol.',
    ], 400);
}

if ($current === $next) {
    respond(['error' => 'The new password is the same as the current one.'], 400);
}

$stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($current, $row['password_hash'])) {
    /* Recorded. Someone repeatedly guessing the current password from
     * inside a live session is either a borrowed laptop or a hijacked
     * cookie, and both are worth an admin seeing. */
    recordAttempt($pdo, $user['email'], false);
    audit($pdo, $user['tenant_id'], $user, 'blocked', $user['email'],
        'Password change refused — current password incorrect', $ctx['device']);

    respond(['error' => 'Your current password is not correct.'], 401);
}

$pdo->beginTransaction();

try {
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);

    /* Every OTHER session dies; this one survives so the user is not
     * bounced to the login page immediately after succeeding. If the
     * reason for the change is that someone else had the password,
     * leaving their session alive would make the whole exercise
     * pointless. */
    $pdo->prepare(
        "UPDATE sessions SET revoked_at = NOW()
         WHERE user_id = ? AND id <> ? AND revoked_at IS NULL"
    )->execute([$user['id'], $ctx['session']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[datafort] password change failed: ' . $e->getMessage());
    respond(['error' => 'Could not change the password. Try again.'], 500);
}

audit($pdo, $user['tenant_id'], $user, 'user', $user['email'],
    'Password changed — other sessions revoked', $ctx['device']);

respond(['ok' => true]);
