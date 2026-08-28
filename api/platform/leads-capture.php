<?php
/**
 * leads-capture.php — the pricing page's "Talk to us" form lands here.
 *
 * The other deliberately unauthenticated endpoint under api/platform/,
 * alongside public-plans.php. Always stores to platform_leads first —
 * an inquiry that only exists as an attempted email is one lost
 * silently if that email never arrives, and this domain's mail
 * deliverability is explicitly unresolved (SERVER-REQUIREMENTS.md
 * section 5). The email notification is a best-effort nudge on top,
 * never the record of truth.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

$in = body();

// Honeypot: a field no human sees or fills (hidden off-screen in the
// form's CSS), but a scripted submission usually does. Filled = drop
// it silently rather than tell an automated sender it was rejected.
if (trim((string) ($in['website'] ?? '')) !== '') {
    respond(['ok' => true]);
}

$name    = trim((string) ($in['name'] ?? ''));
$email   = strtolower(trim((string) ($in['email'] ?? '')));
$company = trim((string) ($in['company'] ?? '')) ?: null;
$plan    = trim((string) ($in['planInterest'] ?? '')) ?: null;
$message = trim((string) ($in['message'] ?? '')) ?: null;

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['error' => 'A name and a valid email address are required.'], 400);
}

$pdo->prepare(
    "INSERT INTO platform_leads (name, email, company, plan_interest, message, ip)
     VALUES (?,?,?,?,?,?)"
)->execute([
    substr($name, 0, 160), $email, $company ? substr($company, 0, 160) : null,
    $plan ? substr($plan, 0, 80) : null, $message, clientIp(),
]);

$notifyTo = $CONFIG['mail']['reply_to'] ?? null;
if ($notifyTo) {
    @mail(
        $notifyTo,
        'Datafort pricing inquiry: ' . $name,
        "Name: $name\nEmail: $email\n" .
        ($company ? "Company: $company\n" : '') .
        ($plan ? "Plan: $plan\n" : '') .
        ($message ? "\nMessage:\n$message\n" : ''),
        'From: ' . ($CONFIG['mail']['from_name'] ?? 'Datafort') . ' <' . ($CONFIG['mail']['from_email'] ?? 'noreply@localhost') . '>'
    );
}

respond(['ok' => true]);
