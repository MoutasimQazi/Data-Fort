<?php
/**
 * auth.php — Level 2: which employee is this?
 *
 * Sessions are rows in the database, not PHP's native session files.
 * Two reasons that matters here: an admin needs to be able to kill a
 * specific session from the device page, and every session is bound to
 * the certificate serial that created it.
 *
 * That binding is the part worth understanding. A session cookie lifted
 * off a company laptop and pasted into a browser on a personal machine
 * will fail — not because the cookie is invalid, but because the TLS
 * handshake on the personal machine presents no certificate, so the
 * serial does not match the one recorded when the session was created.
 * The cookie alone is worthless off the device.
 */

declare(strict_types=1);

require_once __DIR__ . '/device.php';

/** Cryptographically random session id. */
function newSessionId(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Creates a session and sets the cookie.
 */
function startSession(PDO $pdo, array $config, array $user, ?array $device, bool $trustDevice, ?string $deviceFp): string
{
    $id = newSessionId();
    $lifetime = $trustDevice
        ? $config['session']['trusted_days'] * 86400
        : $config['session']['lifetime'];

    $stmt = $pdo->prepare(
        "INSERT INTO sessions
         (id, tenant_id, user_id, device_id, device_serial, ip, user_agent, device_fp, expires_at)
         VALUES (?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $stmt->execute([
        $id,
        (int) $user['tenant_id'],
        (int) $user['id'],
        $device['id'] ?? null,
        $device ? $device['certificate_serial'] : null,
        clientIp(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $deviceFp ? substr($deviceFp, 0, 32) : null,
        $lifetime,
    ]);

    setcookie($config['session']['cookie'], $id, [
        'expires'  => time() + $lifetime,
        'path'     => '/',
        'secure'   => (bool) $config['session']['secure'],
        'httponly' => true,      // JS can never read it, so XSS cannot steal it
        'samesite' => 'Strict',  // never sent on a cross-site request
    ]);

    return $id;
}

/** Ends the current session. */
function endSession(PDO $pdo, array $config): void
{
    $id = $_COOKIE[$config['session']['cookie']] ?? '';
    if ($id !== '') {
        $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE id = ?")->execute([$id]);
    }
    setcookie($config['session']['cookie'], '', [
        'expires' => time() - 3600, 'path' => '/',
        'secure' => (bool) $config['session']['secure'], 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

/**
 * The guard every protected endpoint calls first.
 *
 * Order matters and is not arbitrary:
 *   1. Device  — is this a company laptop?
 *   2. Session — is there a live session?
 *   3. Binding — was that session created on THIS laptop?
 *   4. User    — is the account still active?
 *
 * Device first, because if the answer is no there is nothing else worth
 * checking and no reason to touch the users table at all.
 *
 * Returns ['user' => [...], 'device' => [...]|null, 'tenant' => [...]].
 */
function requireAuth(PDO $pdo, array $config, string $role = 'any'): array
{
    /* Phones and tablets, before anything else. One call here covers
     * every protected endpoint, so nobody has to remember to add it —
     * a check you must remember is a check that gets forgotten on the
     * next endpoint somebody writes. */
    requireDesktop();

    $tenant = currentTenant($pdo, $config);

    // ── 1. Device ──
    $mode = $tenant['device_enforcement'] ?? $config['device_enforcement_fallback'];
    $check = verifyDevice($pdo, (int) $tenant['id'], $mode);

    if (!$check['ok']) {
        respond([
            'error'       => $check['message'],
            'device_error' => $check['reason'],
        ], 403);
    }
    $device = $check['device'];

    // ── 2. Session ──
    $sid = $_COOKIE[$config['session']['cookie']] ?? '';
    if ($sid === '') {
        respond(['error' => 'Not signed in', 'auth_error' => 'no_session'], 401);
    }

    $stmt = $pdo->prepare(
        "SELECT s.*, u.name, u.email, u.role, u.status AS user_status,
                u.daily_quota, u.tenant_id AS user_tenant
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$sid]);
    $session = $stmt->fetch();

    if (!$session) {
        respond(['error' => 'Session expired', 'auth_error' => 'expired'], 401);
    }

    // ── 3. Session/device binding ──
    if ($mode === 'enforce' && !empty($session['device_serial'])) {
        $currentSerial = $device['certificate_serial'] ?? null;
        if ($currentSerial !== $session['device_serial']) {
            /* A session presented from a different laptop than the one
             * that created it. Either a cookie was copied or a user
             * swapped machines mid-session. Kill it and make them sign
             * in again on the machine they are actually holding. */
            $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE id = ?")->execute([$sid]);
            audit($pdo, (int) $tenant['id'],
                ['id' => $session['user_id'], 'name' => $session['name']],
                'blocked', 'session', 'Session presented from a different device — revoked', $device);
            respond(['error' => 'Session is bound to another device', 'auth_error' => 'device_mismatch'], 401);
        }
    }

    // ── 4. Account state ──
    if ($session['user_status'] === 'suspended') {
        respond(['error' => 'This account is suspended', 'auth_error' => 'suspended'], 403);
    }
    if ((int) $session['user_tenant'] !== (int) $tenant['id']) {
        // Should be impossible; means data corruption or a crafted cookie.
        respond(['error' => 'Session invalid'], 401);
    }

    // ── Role ──
    if ($role === 'admin' && $session['role'] !== 'admin') {
        respond(['error' => 'Administrator access required'], 403);
    }

    // Keep the session and the user warm, cheaply.
    $pdo->prepare("UPDATE sessions SET last_seen_at = NOW() WHERE id = ?")->execute([$sid]);
    $pdo->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?")->execute([$session['user_id']]);

    return [
        'user' => [
            'id'        => (int) $session['user_id'],
            'tenant_id' => (int) $tenant['id'],
            'name'      => $session['name'],
            'email'     => $session['email'],
            'role'      => $session['role'],
            'quota'     => (int) $session['daily_quota'],
        ],
        'device'  => $device,
        'tenant'  => $tenant,
        'session' => $sid,
    ];
}

/**
 * How many reveals this user has spent today.
 *
 * Counted from the ledger every time rather than kept in a column. A
 * counter can be wrong; a COUNT(*) over lead_reveals cannot drift, and
 * it is the same number the audit log would show an investigator.
 */
function revealsToday(PDO $pdo, int $tenantId, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lead_reveals
         WHERE tenant_id = ? AND user_id = ? AND reveal_date = CURDATE()"
    );
    $stmt->execute([$tenantId, $userId]);
    return (int) $stmt->fetchColumn();
}

/** Login throttle — per email and per IP, whichever trips first. */
function throttled(PDO $pdo, array $config, string $email): bool
{
    $window = (int) $config['throttle']['window'];
    $max    = (int) $config['throttle']['max_attempts'];

    $stmt = $pdo->prepare(
        "SELECT
            SUM(email = ?) AS by_email,
            SUM(ip = ?)    AS by_ip
         FROM login_attempts
         WHERE ok = 0 AND at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $stmt->execute([$email, clientIp(), $window]);
    $row = $stmt->fetch() ?: ['by_email' => 0, 'by_ip' => 0];

    return ((int) $row['by_email'] >= $max) || ((int) $row['by_ip'] >= $max * 3);
}

function recordAttempt(PDO $pdo, string $email, bool $ok): void
{
    $pdo->prepare("INSERT INTO login_attempts (email, ip, ok) VALUES (?,?,?)")
        ->execute([$email, clientIp(), $ok ? 1 : 0]);
}
