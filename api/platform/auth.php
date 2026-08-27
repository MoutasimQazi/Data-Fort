<?php
/**
 * platform/auth.php — session lifecycle for the platform panel.
 *
 * Structurally api/auth.php minus the tenant dimension: same
 * device-then-session-then-binding order, same reasoning for why
 * (see api/auth.php's header comment — it applies here unchanged,
 * just with "the platform owner's laptop" standing in for "a company
 * laptop"). Two separate tables, two separate cookies, two separate
 * functions — a platform session must never be satisfiable by
 * anything that touches a tenant's own `sessions` table.
 */

declare(strict_types=1);

require_once __DIR__ . '/device.php';

function newPlatformSessionId(): string
{
    return bin2hex(random_bytes(32));
}

function startPlatformSession(PDO $pdo, array $config, array $admin, ?array $device, bool $trustDevice, ?string $deviceFp): string
{
    $id = newPlatformSessionId();
    $pc = $config['multi_tenant']['platform_session'] ?? ['cookie' => 'df_platform_session', 'lifetime' => 28800, 'trusted_days' => 14, 'secure' => true];
    $lifetime = $trustDevice ? $pc['trusted_days'] * 86400 : $pc['lifetime'];

    $stmt = $pdo->prepare(
        "INSERT INTO platform_admin_sessions
         (id, admin_id, device_id, device_serial, ip, user_agent, device_fp, expires_at)
         VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $stmt->execute([
        $id, (int) $admin['id'],
        $device['id'] ?? null,
        $device ? $device['certificate_serial'] : null,
        clientIp(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $deviceFp ? substr($deviceFp, 0, 32) : null,
        $lifetime,
    ]);

    setcookie($pc['cookie'], $id, [
        'expires' => time() + $lifetime, 'path' => '/',
        'secure' => (bool) $pc['secure'], 'httponly' => true, 'samesite' => 'Strict',
    ]);

    return $id;
}

function endPlatformSession(PDO $pdo, array $config): void
{
    $pc = $config['multi_tenant']['platform_session'] ?? ['cookie' => 'df_platform_session', 'secure' => true];
    $id = $_COOKIE[$pc['cookie']] ?? '';
    if ($id !== '') {
        $pdo->prepare("UPDATE platform_admin_sessions SET revoked_at = NOW() WHERE id = ?")->execute([$id]);
    }
    setcookie($pc['cookie'], '', [
        'expires' => time() - 3600, 'path' => '/',
        'secure' => (bool) $pc['secure'], 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

/**
 * The guard every api/platform/*.php endpoint calls first.
 *
 * Same order as requireAuth(): device, then session, then binding,
 * then account state — see api/auth.php for why the order matters.
 * There is no role parameter — every platform_admins row is equally
 * privileged; access to THIS panel is the privilege boundary, not a
 * role within it.
 */
function platformRequireAuth(PDO $pdo, array $config): array
{
    requireDesktop();

    $mode = $config['multi_tenant']['platform_device_enforcement'] ?? 'log';
    $check = verifyPlatformDevice($pdo, $mode);
    if (!$check['ok']) {
        respond(['error' => $check['message'], 'device_error' => $check['reason']], 403);
    }
    $device = $check['device'];

    $pc = $config['multi_tenant']['platform_session'] ?? ['cookie' => 'df_platform_session'];
    $sid = $_COOKIE[$pc['cookie']] ?? '';
    if ($sid === '') {
        respond(['error' => 'Not signed in', 'auth_error' => 'no_session'], 401);
    }

    $stmt = $pdo->prepare(
        "SELECT s.*, a.name, a.email, a.status AS admin_status
         FROM platform_admin_sessions s
         JOIN platform_admins a ON a.id = s.admin_id
         WHERE s.id = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$sid]);
    $session = $stmt->fetch();

    if (!$session) {
        respond(['error' => 'Session expired', 'auth_error' => 'expired'], 401);
    }

    if ($mode === 'enforce' && !empty($session['device_serial'])) {
        $currentSerial = $device['certificate_serial'] ?? null;
        if ($currentSerial !== $session['device_serial']) {
            $pdo->prepare("UPDATE platform_admin_sessions SET revoked_at = NOW() WHERE id = ?")->execute([$sid]);
            platformAudit($pdo, ['id' => $session['admin_id'], 'name' => $session['name']],
                'blocked', 'session', 'Session presented from a different device — revoked');
            respond(['error' => 'Session is bound to another device', 'auth_error' => 'device_mismatch'], 401);
        }
    }

    if ($session['admin_status'] === 'suspended') {
        respond(['error' => 'This account is suspended'], 403);
    }

    $pdo->prepare("UPDATE platform_admin_sessions SET last_seen_at = NOW() WHERE id = ?")->execute([$sid]);
    $pdo->prepare("UPDATE platform_admins SET last_seen_at = NOW() WHERE id = ?")->execute([$session['admin_id']]);

    return [
        'admin'   => ['id' => (int) $session['admin_id'], 'name' => $session['name'], 'email' => $session['email']],
        'device'  => $device,
        'session' => $sid,
    ];
}

/** Login throttle, same shape as throttled()/recordAttempt() in api/auth.php. */
function platformThrottled(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare(
        "SELECT SUM(email = ?) AS by_email, SUM(ip = ?) AS by_ip
         FROM platform_login_attempts
         WHERE ok = 0 AND at > DATE_SUB(NOW(), INTERVAL 900 SECOND)"
    );
    $stmt->execute([$email, clientIp()]);
    $row = $stmt->fetch() ?: ['by_email' => 0, 'by_ip' => 0];
    return ((int) $row['by_email'] >= 6) || ((int) $row['by_ip'] >= 18);
}

function platformRecordAttempt(PDO $pdo, string $email, bool $ok): void
{
    $pdo->prepare("INSERT INTO platform_login_attempts (email, ip, ok) VALUES (?,?,?)")
        ->execute([$email, clientIp(), $ok ? 1 : 0]);
}

/** Append-only, same discipline as audit() in api/db.php. */
function platformAudit(PDO $pdo, ?array $admin, string $action, ?string $subject = null, ?string $detail = null, ?int $tenantId = null): void
{
    try {
        $pdo->prepare(
            "INSERT INTO platform_audit_log (tenant_id, actor_id, actor_name, action, subject, detail, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $tenantId,
            $admin['id'] ?? null,
            $admin['name'] ?? null,
            $action,
            $subject !== null ? substr($subject, 0, 120) : null,
            $detail !== null ? substr($detail, 0, 500) : null,
            clientIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[datafort-platform] audit write failed: ' . $e->getMessage());
    }
}
