<?php
/**
 * checkout-start.php — pricing.html's "Subscribe" button lands here.
 *
 * Public, like public-plans.php and leads-capture.php — a prospect
 * clicking Subscribe has no platform session. Looks up the plan's
 * Stripe Price id, asks Stripe to create a real Checkout Session, and
 * hands back the URL to redirect to. Nothing here decides whether the
 * payment succeeds — that's stripe-webhook.php's job, once Stripe
 * calls back.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/stripe-client.php';

requireMethod('POST');

$stripe = $CONFIG['stripe'] ?? [];
if (empty($stripe['enabled']) || ($stripe['secret_key'] ?? '') === 'CHANGE_ME') {
    respond(['error' => 'Purchasing is not available right now.'], 503);
}

$in = body();
$planId = (int) ($in['planId'] ?? 0);
if ($planId <= 0) {
    respond(['error' => 'Plan is required.'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_plans WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$planId]);
$plan = $stmt->fetch();

// Same generic message whether the plan doesn't exist, is retired, or
// simply has no price configured — this endpoint never confirms or
// denies which plan ids are real.
if (!$plan || empty($plan['stripe_price_id'])) {
    respond(['error' => 'That plan is not available for self-serve purchase.'], 400);
}

// Built from the request's own Host, never from anything in the POST
// body — accepting a client-supplied return URL here would make this
// endpoint an open-redirect-via-Stripe primitive.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$successUrl = "https://{$host}/pricing.html?checkout=success&session_id={CHECKOUT_SESSION_ID}";
$cancelUrl  = "https://{$host}/pricing.html?checkout=cancelled";

$result = stripeCreateCheckoutSession(
    $stripe['secret_key'], $plan['stripe_price_id'], (int) $plan['id'], $plan['name'],
    $successUrl, $cancelUrl
);

if (!$result['ok']) {
    respond(['error' => 'Could not start checkout. Try again shortly.'], 502);
}

respond(['ok' => true, 'url' => $result['url']]);
