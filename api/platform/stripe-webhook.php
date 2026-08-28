<?php
/**
 * stripe-webhook.php — Stripe calls this, never a browser.
 *
 * Reads the raw request body itself, before anything else touches it —
 * http.php's shared body() helper JSON-decodes and discards the exact
 * bytes the signature check needs, so this file deliberately does not
 * use it. Verify first, decode second, and only ever decode the same
 * string that was verified.
 *
 * Register this URL in the Stripe Dashboard -> Developers -> Webhooks,
 * restricted to the checkout.session.completed event, and paste the
 * "Signing secret" it gives you into config.php's stripe.webhook_secret
 * — that secret is NOT the same thing as the API secret key.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/stripe-client.php';

requireMethod('POST');

$stripe = $CONFIG['stripe'] ?? [];
if (empty($stripe['enabled'])) {
    respond(['error' => 'Not configured'], 503);
}

$rawBody   = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$verify = stripeVerifyWebhookSignature($rawBody, $sigHeader, $stripe['webhook_secret'] ?? '');
if (!$verify['ok']) {
    // 400, not 200 — per Stripe's own retry semantics, this is what
    // lets a REAL delivery retry automatically and succeed once a
    // misconfiguration (e.g. a rotated webhook secret not yet updated
    // here) is fixed. A forged POST has no legitimate retry to protect
    // either way, since Stripe never sent it.
    error_log('[datafort-platform] stripe webhook rejected: ' . $verify['reason']);
    respond(['error' => 'Invalid signature'], 400);
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    respond(['error' => 'Invalid payload'], 400);
}

// Guards against a live event ever being accepted while test keys are
// configured here, or vice versa, if the two are ever mismatched.
if ((bool) ($event['livemode'] ?? false) !== (bool) ($stripe['live'] ?? false)) {
    error_log('[datafort-platform] stripe webhook livemode mismatch — ignored');
    respond(['ok' => true]);
}

// Always 200 on an event type we don't act on — Stripe treats a
// non-2xx response as "retry for up to 3 days, then auto-disable the
// endpoint," which is the wrong behaviour for something we're
// intentionally ignoring, not failing to process. The Dashboard's own
// endpoint config should already be restricted to just this event
// type; this check is defensive in case that config ever drifts.
if (($event['type'] ?? '') !== 'checkout.session.completed') {
    respond(['ok' => true]);
}

$session = $event['data']['object'] ?? [];
$metadata = $session['metadata'] ?? [];
$planId = isset($metadata['plan_id']) && $metadata['plan_id'] !== '' ? (int) $metadata['plan_id'] : null;

// Everything needed is already on the webhook payload — no second
// outbound call back to Stripe from inside here, which would risk
// missing the few-second window Stripe expects a response within.
$stmt = $pdo->prepare(
    "INSERT INTO platform_orders
       (stripe_session_id, stripe_event_id, stripe_customer_id, stripe_subscription_id,
        plan_id, plan_name_snapshot, customer_email, amount_total, currency, payment_status, livemode)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       stripe_event_id = VALUES(stripe_event_id),
       payment_status  = VALUES(payment_status),
       updated_at      = CURRENT_TIMESTAMP"
);
$stmt->execute([
    $session['id'] ?? '',
    $event['id'] ?? null,
    $session['customer'] ?? null,
    $session['subscription'] ?? null,
    $planId,
    $metadata['plan_name'] ?? null,
    $session['customer_details']['email'] ?? $session['customer_email'] ?? null,
    isset($session['amount_total']) ? (int) $session['amount_total'] : null,
    $session['currency'] ?? null,
    $session['payment_status'] ?? null,
    (int) (bool) ($event['livemode'] ?? false),
]);

// affected rows is 1 for a fresh INSERT, 2 for a row that hit the
// ON DUPLICATE KEY UPDATE branch (MySQL's own convention for this
// statement) — only notify on the former, so a redelivered webhook
// doesn't re-notify the admin for the same order.
if ($stmt->rowCount() === 1) {
    $notifyTo = $CONFIG['mail']['reply_to'] ?? null;
    if ($notifyTo) {
        $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? 'unknown';
        $amount = isset($session['amount_total']) ? number_format($session['amount_total'] / 100, 2) : '?';
        @mail(
            $notifyTo,
            'Datafort: new paid order — ' . ($metadata['plan_name'] ?? 'unknown plan'),
            "Plan: " . ($metadata['plan_name'] ?? 'unknown') . "\n" .
            "Customer: $email\n" .
            "Amount: " . ($session['currency'] ?? '') . " $amount\n" .
            "Stripe session: " . ($session['id'] ?? '') . "\n\n" .
            "Provision this tenant from the Orders tab in the platform panel.\n",
            'From: ' . ($CONFIG['mail']['from_name'] ?? 'Datafort') . ' <' . ($CONFIG['mail']['from_email'] ?? 'noreply@localhost') . '>'
        );
    }
}

respond(['ok' => true]);
