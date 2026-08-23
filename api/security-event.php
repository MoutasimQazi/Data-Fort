<?php
/**
 * security-event.php — batched signals from guard.js.
 *
 * Everything arriving here is CLIENT-REPORTED and therefore untrusted.
 * A rep who disables JavaScript sends nothing at all; one who wants to
 * be noisy can send thousands of fabricated events. So this data is an
 * indicator, never a record — it belongs in security_events, not in
 * audit_log, and no decision should be made on it alone.
 *
 * What it is genuinely good for: "this account tried to copy 40 times
 * this morning" is a real pattern worth an admin's attention, and the
 * absence of events from a session that revealed 30 contacts is itself
 * interesting.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$_SERVER['HTTP_X_DATAFORT_QUIET'] = '1';

$ctx  = requireAuth($pdo, $CONFIG);
$user = $ctx['user'];

$in     = body();
$events = $in['events'] ?? [];

if (!is_array($events)) {
    respond(['ok' => true]);
}

// Hard cap. sendBeacon batches are small; anything larger is either a
// bug or someone trying to fill the table.
$events = array_slice($events, 0, 50);

/* Types a CLIENT is allowed to report.
 *
 * 'contact_revealed' is deliberately NOT here. lead-reveal.php writes
 * that row itself, and the burst limiter counts it — so accepting one
 * from the browser would let a client forge its own rate-limit history.
 * Anything the server records about itself must not also be writable by
 * the thing being measured. */
$allowed = [
    'clipboard_blocked', 'contextmenu_blocked', 'drag_blocked', 'key_blocked',
    'devtools_shortcut', 'devtools_opened', 'printscreen_pressed',
    'watermark_removed', 'lead_access_denied', 'source_file_destroyed',
];

$stmt = $pdo->prepare(
    "INSERT INTO security_events (tenant_id, user_id, type, detail, page, ip)
     VALUES (?,?,?,?,?,?)"
);

$written = 0;
foreach ($events as $e) {
    if (!is_array($e)) continue;

    $type = (string) ($e['type'] ?? '');
    // Unknown types are dropped rather than stored, so the table cannot
    // be used as free text storage by anything that finds this endpoint.
    if (!in_array($type, $allowed, true)) continue;

    $stmt->execute([
        $user['tenant_id'],
        $user['id'],
        $type,
        isset($e['detail']) ? substr((string) $e['detail'], 0, 255) : null,
        isset($e['page'])   ? substr((string) $e['page'], 0, 190)   : null,
        clientIp(),
    ]);
    $written++;
}

/* A few of these are serious enough to be promoted into the real audit
 * log, where they sit alongside the reveals an investigator is reading.
 * The rest stay as indicators. */
$promote = ['watermark_removed', 'devtools_opened', 'source_file_destroyed'];

foreach ($events as $e) {
    $type = is_array($e) ? (string) ($e['type'] ?? '') : '';
    if (in_array($type, $promote, true)) {
        audit($pdo, $user['tenant_id'], $user, 'blocked', $type,
            isset($e['detail']) ? substr((string) $e['detail'], 0, 200) : null,
            $ctx['device']);
    }
}

respond(['ok' => true, 'written' => $written]);
