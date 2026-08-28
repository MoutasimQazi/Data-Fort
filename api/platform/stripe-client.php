<?php
/**
 * stripe-client.php — the whole Stripe integration in one library file.
 *
 * No SDK. This project has zero Composer/vendor dependencies and this
 * is the first outbound API call and the first webhook it has ever
 * had — a small hand-rolled cURL client and manual HMAC-SHA256 webhook
 * verification is a smaller footprint than adding the first external
 * dependency for two functions' worth of work.
 *
 * Blocked from direct web access by api/.htaccess's <FilesMatch> list,
 * same as db.php/auth.php/device.php/crypto.php — a library, not a route.
 */

declare(strict_types=1);

/**
 * Verifies a Stripe webhook signature by hand, per Stripe's own
 * documented algorithm — see https://stripe.com/docs/webhooks#verify-manually
 *
 * The Stripe-Signature header looks like "t=1614556800,v1=abc...". A
 * second v1 can appear during webhook-secret rotation in the Stripe
 * Dashboard, so every v1 present is checked, not just the first.
 *
 * Returns ['ok' => bool, 'reason' => string|null] rather than throwing —
 * the caller decides the HTTP status, this only decides pass/fail.
 */
function stripeVerifyWebhookSignature(string $rawBody, string $sigHeader, string $webhookSecret, int $tolerance = 300): array
{
    if ($webhookSecret === '' || $webhookSecret === 'CHANGE_ME') {
        return ['ok' => false, 'reason' => 'webhook_secret not configured'];
    }
    if ($sigHeader === '') {
        return ['ok' => false, 'reason' => 'missing Stripe-Signature header'];
    }

    $timestamp = null;
    $v1s = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = array_pad(explode('=', trim($part), 2), 2, null);
        if ($kv[0] === 't') $timestamp = $kv[1];
        if ($kv[0] === 'v1' && $kv[1] !== null) $v1s[] = $kv[1];
    }

    if ($timestamp === null || $v1s === []) {
        return ['ok' => false, 'reason' => 'malformed Stripe-Signature header'];
    }
    if (!ctype_digit($timestamp)) {
        return ['ok' => false, 'reason' => 'non-numeric timestamp'];
    }
    // Replay protection. The signature alone never expires — this is
    // what stops a captured payload being reprocessed long after the
    // fact if it ever leaked.
    if (abs(time() - (int) $timestamp) > $tolerance) {
        return ['ok' => false, 'reason' => 'timestamp outside tolerance (possible replay)'];
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);

    foreach ($v1s as $candidate) {
        // hash_equals(), never ===  — a plain string compare leaks
        // timing information an attacker can use to guess the correct
        // signature one byte at a time.
        if (hash_equals($expected, $candidate)) {
            return ['ok' => true, 'reason' => null];
        }
    }
    return ['ok' => false, 'reason' => 'signature mismatch'];
}

/**
 * Creates a Stripe Checkout Session for one price, subscription mode.
 * Returns ['ok' => true, 'url' => ..., 'session_id' => ...] or ['ok' => false].
 *
 * Stripe's API takes form-encoded params (application/x-www-form-urlencoded),
 * NOT JSON, authenticated via HTTP Basic auth with the secret key as the
 * username and an empty password. Nested params use PHP-style bracket
 * notation, which http_build_query() already produces correctly from a
 * nested associative array — no manual flattening needed. The one thing
 * that matters: CURLOPT_POSTFIELDS must be given the encoded STRING, not
 * the raw array — cURL sends multipart/form-data for an array, which
 * Stripe's API does not parse for this endpoint and silently rejects.
 */
function stripeCreateCheckoutSession(
    string $secretKey, string $priceId, int $planId, string $planName,
    string $successUrl, string $cancelUrl
): array {
    $params = [
        'mode'        => 'subscription',
        'success_url' => $successUrl,   // {CHECKOUT_SESSION_ID} literal is Stripe's own substitution syntax
        'cancel_url'  => $cancelUrl,
        'line_items'  => [
            0 => ['price' => $priceId, 'quantity' => 1],
        ],
        'metadata'    => [
            'plan_id'   => (string) $planId,
            'plan_name' => $planName,   // snapshot — survives the plan being edited/deleted later
        ],
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params, '', '&'),
        CURLOPT_USERPWD        => $secretKey . ':',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,   // never relax this to "fix" a local TLS error — see file header
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],   // pinned, not left to drift with the dashboard default
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        error_log('[datafort-platform] stripe transport error: ' . $err);
        return ['ok' => false];
    }
    $data = json_decode($body, true);
    if ($code !== 200 || !is_array($data) || !isset($data['url'])) {
        error_log('[datafort-platform] stripe API error (' . $code . '): ' . $body);
        return ['ok' => false];
    }
    return ['ok' => true, 'url' => $data['url'], 'session_id' => $data['id']];
}
