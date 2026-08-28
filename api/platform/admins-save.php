<?php
/**
 * admins-save.php — invite a platform teammate, resend an invite,
 * suspend or reactivate. Mirrors users-save.php's action-dispatch
 * shape and its "no plaintext password, ever" invite pattern.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

$ctx   = platformRequireAuth($pdo, $CONFIG);
$admin = $ctx['admin'];

$in     = body();
$action = (string) ($in['action'] ?? '');

function issueInvite(PDO $pdo, int $adminId, string $baseDomain): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO platform_password_resets (token_hash, admin_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
    )->execute([hash('sha256', $token), $adminId]);

    // Platform vhost host, not a tenant subdomain — falls back to
    // base_domain if this ever runs somewhere HTTP_HOST is unset (a
    // CLI-triggered path, none of which exists yet, but cheap to guard).
    $host = $_SERVER['HTTP_HOST'] ?? $baseDomain;
    return 'https://' . $host . '/platform/reset.html?token=' . $token;
}


/* ══ Invite a new platform teammate ═══════════════════════════════ */

if ($action === 'create') {
    $name  = trim((string) ($in['name'] ?? ''));
    $email = strtolower(trim((string) ($in['email'] ?? '')));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'A name and a valid email address are required.'], 400);
    }

    $dupe = $pdo->prepare("SELECT id FROM platform_admins WHERE email = ?");
    $dupe->execute([$email]);
    if ($dupe->fetch()) {
        respond(['error' => 'A platform admin with that email already exists.'], 409);
    }

    // Same "created unusable, invite link sets the first password" rule
    // as every account this codebase creates — see users-save.php and
    // scripts/provision-tenant.php.
    $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $pdo->prepare(
        "INSERT INTO platform_admins (name, email, password_hash, status) VALUES (?,?,?, 'active')"
    )->execute([$name, $email, $unusable]);
    $newId = (int) $pdo->lastInsertId();

    $link = issueInvite($pdo, $newId, $CONFIG['multi_tenant']['base_domain'] ?? '');
    sendAppMail($CONFIG, $email, 'Your Datafort platform account',
        "You have been invited to administer the Datafort platform.\n\n" .
        "Set your password here (link valid for 7 days):\n$link\n", [],
        datafortActionEmail('Platform invitation',
            'You have been invited to administer the Datafort platform.',
            'Accept invitation', $link, 'This one-time link expires in 7 days.'));

    platformAudit($pdo, $admin, 'platform_admin_create', $email, "Invited \"$name\"");

    respond(['ok' => true, 'id' => $newId, 'inviteLink' => $link]);
}


/* ══ Existing admin ════════════════════════════════════════════════ */

$targetId = (int) ($in['id'] ?? 0);
if ($targetId <= 0) {
    respond(['error' => 'Admin id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_admins WHERE id = ? LIMIT 1");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    respond(['error' => 'Admin not found'], 404);
}

switch ($action) {

    case 'resend_invite':
        // Old tokens are simply left to expire on their own schedule —
        // no need to invalidate them, since a stale one is no more
        // dangerous than a stale tenant invite link is today.
        $link = issueInvite($pdo, $targetId, $CONFIG['multi_tenant']['base_domain'] ?? '');
        sendAppMail($CONFIG, $target['email'], 'Your Datafort platform invite',
            "Set your Datafort platform password here (link valid for 7 days):\n$link\n", [],
            datafortActionEmail('Your invitation is ready',
                'A fresh Datafort platform invitation has been issued for you.',
                'Set your password', $link, 'This one-time link expires in 7 days.'));
        platformAudit($pdo, $admin, 'platform_admin_create', $target['email'], 'Invite link resent');
        respond(['ok' => true, 'inviteLink' => $link]);

    case 'suspend':
        if ($targetId === (int) $admin['id']) {
            respond(['error' => 'You cannot suspend your own account. Ask another platform admin to do it.'], 400);
        }
        $pdo->prepare("UPDATE platform_admins SET status = 'suspended' WHERE id = ?")->execute([$targetId]);
        $pdo->prepare("UPDATE platform_admin_sessions SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL")
            ->execute([$targetId]);
        platformAudit($pdo, $admin, 'platform_admin_suspend', $target['email'], 'Suspended — sessions killed');
        break;

    case 'reactivate':
        $pdo->prepare("UPDATE platform_admins SET status = 'active' WHERE id = ?")->execute([$targetId]);
        platformAudit($pdo, $admin, 'platform_admin_reactivate', $target['email'], 'Reactivated');
        break;

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
