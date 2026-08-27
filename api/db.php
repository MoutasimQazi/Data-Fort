<?php
/**
 * db.php — bootstrap required by every endpoint.
 *
 * Loads config, opens the PDO connection, sets response headers, and
 * provides the small helpers the endpoints share.
 */

declare(strict_types=1);

// Response headers + the tenant-agnostic helpers (body/respond/deny/
// clientIp/requireDesktop/requireMethod) — shared with api/platform/*
// via the same file, so they can't quietly drift into two copies.
require_once __DIR__ . '/http.php';

// ── Config ──
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server not configured. Copy api/config.sample.php to api/config.php.']);
    exit;
}
$CONFIG = require $configPath;

// ── Connection ──
//
// Which database this is depends on which tenant the request is for.
// Single-tenant deployments (multi_tenant.enabled unset or false, which
// is every deployment that predates this) get $CONFIG['db'] back
// unchanged — see tenant-resolver.php's header for the full story.
require_once __DIR__ . '/tenant-resolver.php';
$dbConfig = resolveTenantDatabase($CONFIG);

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4",
        $dbConfig['user'],
        $dbConfig['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
            PDO::ATTR_TIMEOUT            => 10,
        ]
    );
} catch (PDOException $e) {
    error_log('[datafort] db connect failed: ' . $e->getMessage());
    http_response_code(500);

    /* The real MySQL message names the user, the host and the exact
     * refusal — "Access denied for user 'x'@'localhost'" — which is
     * precisely what you need during setup and precisely what you do
     * not want leaking to the internet afterwards.
     *
     * So it is gated on config.debug, which ships false. Turn it on to
     * get set up, turn it off before this instance holds a real lead. */
    echo json_encode([
        'error' => empty($CONFIG['debug'])
            ? 'Database connection failed'
            : 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit;
}


/* ══ Helpers ═══════════════════════════════════════════════════════ */
//
// body()/respond()/deny()/clientIp()/requireDesktop()/requireMethod()
// now live in http.php (required above) — shared with api/platform/*.
// Everything below is specific to a tenant's own database.

/**
 * The one tenant row in the database $pdo is already connected to.
 *
 * Not filtered by slug: $pdo now points at ONE tenant's own database
 * (single-tenant deployments always did; multi-tenant ones are routed
 * there by resolveTenantDatabase() before this ever runs), and that
 * database holds exactly one row in its local tenants table — so
 * "the tenant" is simply the only one there is to find.
 */
function currentTenant(PDO $pdo, array $config): array
{
    $tenant = $pdo->query("SELECT * FROM tenants LIMIT 1")->fetch();

    if (!$tenant) {
        respond(['error' => 'Tenant not provisioned'], 500);
    }
    return $tenant;
}

/**
 * Masks a phone number for display. The full value never leaves the
 * server through any endpoint except lead-reveal.php.
 */
function maskPhone(?string $value): string
{
    if (!$value) return '';
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) < 5) return '****';
    return substr($value, 0, max(0, strlen($value) - 6)) . '****' . substr($digits, -2);
}

/** Masks an email address the same way. */
function maskEmail(?string $value): string
{
    if (!$value) return '';
    $at = strpos($value, '@');
    if ($at === false || $at < 1) return '****';
    return substr($value, 0, min(2, $at)) . '****' . substr($value, $at);
}

/**
 * Appends to the audit log. Never updates, never deletes.
 * Failure to write is logged but does not fail the request — losing one
 * audit row is bad, losing the user's work as well is worse.
 */
function audit(PDO $pdo, int $tenantId, ?array $user, string $action, ?string $subject = null, ?string $detail = null, ?array $device = null): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log
             (tenant_id, actor_id, actor_name, action, subject, detail,
              device_id, device_code, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $tenantId,
            $user['id'] ?? null,
            $user['name'] ?? null,
            $action,
            $subject !== null ? substr($subject, 0, 120) : null,
            $detail !== null ? substr($detail, 0, 500) : null,
            $device['id'] ?? null,
            $device['device_code'] ?? null,
            clientIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[datafort] audit write failed: ' . $e->getMessage());
    }
}

