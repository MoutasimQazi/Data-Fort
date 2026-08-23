<?php
/**
 * db.php — bootstrap required by every endpoint.
 *
 * Loads config, opens the PDO connection, sets response headers, and
 * provides the small helpers the endpoints share.
 */

declare(strict_types=1);

// ── Response headers ──
//
// Deliberately NOT 'Access-Control-Allow-Origin: *'. Datafort is served
// from one origin and its API is only ever called by its own pages. A
// wildcard CORS header on an app holding purchased lead data would let
// any website on the internet read it with the user's cookies attached.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Config ──
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server not configured. Copy api/config.sample.php to api/config.php.']);
    exit;
}
$CONFIG = require $configPath;

// ── Connection ──
try {
    $pdo = new PDO(
        "mysql:host={$CONFIG['db']['host']};port={$CONFIG['db']['port']};dbname={$CONFIG['db']['name']};charset=utf8mb4",
        $CONFIG['db']['user'],
        $CONFIG['db']['pass'],
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

/** JSON body of a POST as an array. */
function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

/** Send a JSON response and stop. */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/**
 * Refuse and stop, logging the real reason server-side.
 *
 * NOT CURRENTLY CALLED. Every endpoint reaches for respond() with a 4xx
 * instead, which loses the server-side log line. Kept because the split
 * it encodes is the right one and endpoints should migrate to it — but
 * a helper nothing uses is a helper nobody maintains, so if it is still
 * unused at the next pass, delete it.
 *
 * Messages returned to the client are deliberately vague about WHY
 * something failed wherever the reason would tell an attacker
 * something — which accounts exist, which lead IDs are real. The
 * specific reason goes to the log, not the browser.
 */
function deny(string $message, int $status = 403, string $logReason = ''): void
{
    if ($logReason !== '') {
        error_log('[datafort] denied (' . $status . '): ' . $logReason);
    }
    respond(['error' => $message], $status);
}

/** Current tenant row, from the slug pinned in config. */
function currentTenant(PDO $pdo, array $config): array
{
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
    $stmt->execute([$config['tenant_slug']]);
    $tenant = $stmt->fetch();

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

/** Client IP, as far as it can be trusted on this hosting. */
function clientIp(): string
{
    // X-Forwarded-For is NOT consulted: on a direct Apache vhost it is
    // attacker-controlled, and trusting it would let anyone forge the
    // IP in the audit log. If a real proxy is introduced, add it here
    // with an explicit allowlist of proxy addresses.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
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

/**
 * Refuses phones and tablets.
 *
 * Datafort's containment model assumes a company laptop. A phone cannot
 * practically hold the mTLS client certificate, no browser-side
 * deterrent in guard.js works there, and it is the one device an
 * employer cannot wipe or reclaim.
 *
 * desktop-only.js blocks this in the browser, before any markup paints.
 * This is the second half: without it, "request desktop site" or a
 * spoofed User-Agent would let a phone talk straight to the API and
 * skip the page entirely.
 *
 * ── BE CLEAR ABOUT WHAT THIS IS ──
 *
 * A User-Agent is attacker-controlled. Anyone who wants to get past
 * this can, with one header. It is POLICY enforcement, not a security
 * control — it stops the rep who idly opens Datafort on the bus, not
 * the one deliberately exfiltrating.
 *
 * The control that actually holds is the client certificate: with
 * device enforcement on, a phone is refused because it has none,
 * whatever it claims to be.
 */
function requireDesktop(): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    // An empty User-Agent is curl, a script, or something hiding. Not a
    // phone, and not this function's problem — auth handles those.
    if ($ua === '') return;

    if (preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Windows Phone|Mobile|Tablet|Silk|Kindle/i', $ua)) {
        respond([
            'error'        => 'Datafort is not available on phones or tablets. ' .
                              'Sign in from your company laptop.',
            'mobile_blocked' => true,
        ], 403);
    }
}

/** Only allow a given HTTP method. */
function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        respond(['error' => 'Method not allowed'], 405);
    }
}
