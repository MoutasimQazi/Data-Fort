<?php
/**
 * auth-forgot.php — request a reset link.
 *
 * Responds identically whether or not the account exists. Anything else
 * turns this endpoint into a way to test which email addresses are real
 * at the target company, which is a staff list for whoever is planning
 * to phish them.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

requireMethod('POST');

$in    = body();
$email = strtolower(trim((string) ($in['email'] ?? '')));

// The one response this endpoint ever gives.
$sameAnswer = ['ok' => true];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond($sameAnswer);
}

$tenant = currentTenant($pdo, $CONFIG);

/* Device check applies here too. Without it, someone with a stolen
 * password could still trigger reset emails from a personal machine —
 * noisy, and a decent way to panic an employee into clicking something. */
$mode  = $tenant['device_enforcement'] ?? $CONFIG['device_enforcement_fallback'];
$check = verifyDevice($pdo, (int) $tenant['id'], $mode);
if (!$check['ok']) {
    respond($sameAnswer);   // still the same answer — do not leak the reason
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE tenant_id = ? AND email = ? LIMIT 1");
$stmt->execute([(int) $tenant['id'], $email]);
$user = $stmt->fetch();

if ($user && $user['status'] !== 'suspended') {
    $token = bin2hex(random_bytes(32));

    /* Only the hash is stored. A leaked database therefore yields no
     * usable reset links — the same reasoning as not storing passwords. */
    $pdo->prepare(
        "INSERT INTO password_resets (token_hash, user_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
    )->execute([hash('sha256', $token), (int) $user['id']]);

    $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/reset.html?token=' . $token;

    sendAppMail($CONFIG,
        $email,
        'Reset your Datafort password',
        "A password reset was requested for your Datafort account.\n\n" .
        "$link\n\n" .
        "This link works once and expires in 30 minutes.\n" .
        "If you did not request it, tell your administrator — the request has been logged.\n"
    );

    audit($pdo, (int) $tenant['id'], $user, 'login', $email, 'Password reset requested', $check['device']);
}

/* Timing. Without this the endpoint answers noticeably faster for an
 * address that does not exist, which leaks exactly what the identical
 * message was written to hide. Crude but effective; a queue would be
 * better. */
usleep(random_int(180000, 320000));

respond($sameAnswer);
