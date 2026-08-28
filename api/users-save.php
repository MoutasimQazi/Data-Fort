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
require_once __DIR__ . '/mailer.php';

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

    sendAppMail($CONFIG, $email, 'Your Datafort account',
        "You have been given access to Datafort.\n\n" .
        "Set your password here (link valid for 7 days):\n$link\n\n" .
        "You will only be able to sign in from a company laptop.\n");

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

    case 'target':
        /* Daily lead TARGET, not the reveal quota. Two different numbers
         * doing two different jobs:
         *
         *   target  how many leads land in their queue each day  (workload)
         *   quota   how many contacts they may unmask each day   (exposure)
         *
         * A rep can hold 200 leads and still be capped at 25 reveals.
         * Conflating them either starves them of work or hands over the
         * whole book. */
        $wantTarget = (int) ($in['target'] ?? -1);
        if ($wantTarget < 0 || $wantTarget > 500) {
            respond(['error' => 'Daily lead target must be between 0 and 500.'], 400);
        }

        $pdo->prepare("UPDATE users SET daily_lead_target = ? WHERE id = ?")
            ->execute([$wantTarget, $targetId]);

        audit($pdo, $tid, $user, 'user', $target['email'],
            'Daily lead target ' . ($target['daily_lead_target'] ?? 0) . ' -> ' . $wantTarget,
            $ctx['device']);
        break;

    case 'assign_daily':
        /* Hands this rep their day's leads out of the unassigned pool.
         *
         * Tops UP to the target rather than adding the target on top of
         * what is already there. Otherwise clicking twice doubles the
         * batch, and an admin who cannot remember whether they already
         * pressed it will press it again. */
        if ($target['role'] !== 'rep') {
            respond(['error' => 'Only sales reps are given a daily book of leads.'], 400);
        }
        if ($target['status'] === 'suspended') {
            respond(['error' => 'That rep is suspended. Restore the account first.'], 409);
        }

        $want = (int) ($in['count'] ?? $target['daily_lead_target'] ?? 0);
        if ($want <= 0 || $want > 500) {
            respond(['error' => 'Choose between 1 and 500 leads.'], 400);
        }

        $already = $pdo->prepare(
            "SELECT COUNT(*) FROM leads
             WHERE tenant_id = ? AND owner_id = ? AND DATE(assigned_at) = CURDATE()"
        );
        $already->execute([$tid, $targetId]);
        $have = (int) $already->fetchColumn();

        $need = max(0, $want - $have);

        if ($need === 0) {
            respond([
                'ok' => true, 'assigned' => 0,
                'message' => $target['name'] . ' already has ' . $have .
                             ' leads assigned today - nothing to top up.',
            ]);
        }

        /* Oldest first. A purchased list decays: the longer a lead sits
         * unassigned the less it is worth. Handing out the freshest ones
         * first would quietly guarantee the bottom of the list is never
         * worked at all. */
        $ids = [];
        $pdo->beginTransaction();
        try {
            $pick = $pdo->prepare(
                "SELECT id FROM leads
                 WHERE tenant_id = ? AND owner_id IS NULL
                 ORDER BY acquired_date ASC, id ASC
                 LIMIT " . (int) $need . "
                 FOR UPDATE"
            );
            $pick->execute([$tid]);
            $ids = $pick->fetchAll(PDO::FETCH_COLUMN);

            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare(
                    "UPDATE leads SET owner_id = ?, assigned_at = NOW() WHERE id IN ($ph)"
                )->execute(array_merge([$targetId], $ids));
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[datafort] daily assign failed: ' . $e->getMessage());
            respond(['error' => 'Assignment failed. Nothing was changed.'], 500);
        }

        $gave = count($ids);

        /* Top the decoys up in the same breath. A book that grew without
         * them is a book with a gap in its attribution. */
        try {
            require_once __DIR__ . '/honeytoken.php';
            seedHoneytokens($pdo, $tid, $targetId,
                (int) ($ctx['tenant']['honeytokens_per_rep'] ?? 0), $user);
        } catch (Throwable $e) {
            error_log('[datafort] honeytoken top-up failed: ' . $e->getMessage());
        }

        audit($pdo, $tid, $user, 'assign', $target['email'],
            $gave . ' leads assigned for today (target ' . $want . ', already had ' . $have . ')',
            $ctx['device']);

        respond([
            'ok'        => true,
            'assigned'  => $gave,
            'shortfall' => max(0, $need - $gave),
            'message'   => $gave . ' leads assigned to ' . $target['name'] . '.' .
                ($gave < $need
                    ? ' The unassigned pool ran out - ' . ($need - $gave) .
                      ' short. Import more leads.'
                    : ''),
        ]);

    case 'set_password':
        /* An administrator setting somebody else's password.
         *
         * Deliberately does NOT ask for the target's current password -
         * the entire point is that they have lost access to it. That
         * makes this a real account-takeover primitive, so: admin only,
         * audited by name, and every session the target had is
         * destroyed. A password change that left their sessions alive
         * would be the ideal way to hide inside somebody's account.
         *
         * This endpoint does not notify the user. Tell them yourself -
         * a password that changes with no warning reads as a breach. */
        $newPw = (string) ($in['password'] ?? '');

        if (strlen($newPw) < 12
            || !preg_match('/[a-z]/', $newPw)
            || !preg_match('/[A-Z]/', $newPw)
            || !preg_match('/\d/', $newPw)
            || !preg_match('/[^\w\s]/', $newPw)) {
            respond([
                'error' => 'Password must be at least 12 characters with upper and lower case, a number and a symbol.',
            ], 400);
        }

        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($newPw, PASSWORD_DEFAULT), $targetId]);

        $killed = $pdo->prepare(
            "UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL"
        );
        $killed->execute([$targetId]);

        /* Clear the throttle, or they are locked out by their own
         * earlier failed attempts with the old password. */
        $pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$target['email']]);

        audit($pdo, $tid, $user, 'user', $target['email'],
            'Password set by administrator - ' . $killed->rowCount() . ' sessions revoked',
            $ctx['device']);

        respond([
            'ok'      => true,
            'message' => 'Password set for ' . $target['name'] . '. ' .
                         $killed->rowCount() . ' active session(s) ended. ' .
                         'Tell them the new password yourself.',
        ]);

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
