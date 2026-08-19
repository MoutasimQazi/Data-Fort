<?php
/**
 * users-save.php — create a user, set a quota, suspend or restore.
 *
 * The quota field here is the single most consequential control in the
 * product, so every change to it is audited with the old and new value.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG, 'admin');
$user = $ctx['user'];
$tid  = $user['tenant_id'];

$in     = body();
$action = (string) ($in['action'] ?? '');


/* ══ Create ════════════════════════════════════════════════════════ */

if ($action === 'create') {
    $name  = trim((string) ($in['name'] ?? ''));
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $role  = ($in['role'] ?? 'rep') === 'admin' ? 'admin' : 'rep';
    $quota = max(0, min(500, (int) ($in['quota'] ?? 25)));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'A name and a valid email address are required.'], 400);
    }

    $dupe = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND email = ? LIMIT 1");
    $dupe->execute([$tid, $email]);
    if ($dupe->fetch()) {
        respond(['error' => 'A user with that email already exists.'], 409);
    }

    /* No password is set here. The account is created unusable and the
     * invite link sets the first password — so a newly created account
     * is never reachable with a default credential, and there is no
     * temporary password to be forwarded around in a chat message. */
    $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo->prepare(
        "INSERT INTO users (tenant_id, name, email, password_hash, role, daily_quota, status)
         VALUES (?,?,?,?,?,?, 'active')"
    )->execute([$tid, $name, $email, $unusable, $role, $role === 'admin' ? 0 : $quota]);

    $newId = (int) $pdo->lastInsertId();

    // Reuse the reset flow to deliver the invite.
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO password_resets (token_hash, user_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
    )->execute([hash('sha256', $token), $newId]);

    $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/reset.html?token=' . $token;

    @mail($email, 'Your Datafort account',
        "You have been given access to Datafort.\n\n" .
        "Set your password here (link valid for 7 days):\n$link\n\n" .
        "You will only be able to sign in from a company laptop.\n",
        'From: ' . $CONFIG['mail']['from_name'] . ' <' . $CONFIG['mail']['from_email'] . '>');

    audit($pdo, $tid, $user, 'user', $email,
        'Created ' . $role . ' account, quota ' . $quota . '/day', $ctx['device']);

    respond(['ok' => true, 'id' => 'u-' . $newId]);
}


/* ══ Existing user ═════════════════════════════════════════════════ */

$targetId = (int) ($in['userId'] ?? 0);
if ($targetId <= 0) {
    respond(['error' => 'User id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$targetId, $tid]);
$target = $stmt->fetch();

if (!$target) {
    respond(['error' => 'User not found'], 404);
}

switch ($action) {

    case 'quota':
        $quota = (int) ($in['quota'] ?? -1);
        if ($quota < 0 || $quota > 500) {
            respond(['error' => 'Quota must be between 0 and 500.'], 400);
        }

        $pdo->prepare("UPDATE users SET daily_quota = ? WHERE id = ?")->execute([$quota, $targetId]);

        audit($pdo, $tid, $user, 'user', $target['email'],
            'Daily quota ' . $target['daily_quota'] . ' → ' . $quota, $ctx['device']);
        break;

    case 'suspend':
    case 'restore':
        $status = $action === 'suspend' ? 'suspended' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $targetId]);

        if ($action === 'suspend') {
            // Sessions die now, not at expiry.
            $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
                ->execute([$targetId]);

            /* Assigned leads are deliberately LEFT in place. Silently
             * emptying a suspended rep's book destroys the picture of
             * what they had access to, which is exactly what an
             * investigation needs. Recalling them is a separate,
             * deliberate action on the Leads page. */
        }

        audit($pdo, $tid, $user, 'user', $target['email'],
            $action === 'suspend' ? 'Suspended — sessions killed, leads left assigned' : 'Restored',
            $ctx['device']);
        break;

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
