<?php
/**
 * http.php — the handful of helpers that don't know what a tenant is.
 *
 * Extracted out of db.php so api/platform/_boot.php can share them
 * without a second, silently-drifting copy. Both tenant endpoints
 * (via db.php) and platform endpoints (via platform/_boot.php) need
 * the same response headers and the same request-shape helpers; only
 * how each resolves a database connection differs.
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
