<?php
/**
 * lead-email.php — the relay.
 *
 * This is how "block email exfiltration" is actually delivered. Not by
 * trying to stop a rep opening Gmail — that is unenforceable — but by
 * never giving the browser the address in the first place. The rep
 * writes the message here; the server looks the recipient up, sends it,
 * and logs it. The rep never possesses the address, so there is nothing
 * to paste anywhere else.
 *
 * Consequence worth being clear about: replies come back to the relay
 * inbox, not to the rep's personal mailbox. That is a real workflow
 * cost, and it is the price of the address never leaving the server. A
 * per-lead reply alias (lead-4231@…) forwarding into the app is the
 * proper fix and is not built yet.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG);
$user = $ctx['user'];

$in      = body();
$ref     = trim((string) ($in['lead'] ?? ''));
$subject = trim((string) ($in['subject'] ?? ''));
$message = trim((string) ($in['body'] ?? ''));

if ($subject === '' || $message === '') {
    respond(['error' => 'Subject and message are both required.'], 400);
}
if (strlen($subject) > 200) {
    respond(['error' => 'Subject is too long.'], 400);
}

$sql = "SELECT * FROM leads WHERE tenant_id = ? AND ref = ?";
$params = [$user['tenant_id'], $ref];
if ($user['role'] !== 'admin') {
    $sql .= " AND owner_id = ?";
    $params[] = $user['id'];
}

$stmt = $pdo->prepare($sql . " LIMIT 1");
$stmt->execute($params);
$lead = $stmt->fetch();

if (!$lead) {
    respond(['error' => 'Lead not available'], 404);
}
if (empty($lead['email'])) {
    respond(['error' => 'No email address on record for this lead.'], 404);
}

/* Header injection guard. Subject goes into a mail header, so a newline
 * in it would let a rep append Bcc: and quietly copy every message to
 * themselves — which would turn the relay into the exfiltration channel
 * it exists to close. */
$subject = str_replace(["\r", "\n", "%0a", "%0d"], '', $subject);

$mail = $CONFIG['mail'];

$headers = [
    'Reply-To' => $mail['reply_to'],
    // Names the sender for the recipient without exposing the rep's own
    // mailbox, and gives the audit log something to correlate against.
    'X-Datafort-Sender' => $user['id'],
    'X-Datafort-Lead' => $lead['ref'],
];

$signature = "\n\n--\n" . $user['name'] . "\n" . $mail['from_name'];

$leadHtml = datafortEmailHtml($subject,
    '<div style="color:#44444D;font-size:15px;line-height:1.75">' . nl2br(mailHtml($message)) . '</div>' .
    '<div style="margin-top:28px;padding-top:18px;border-top:1px solid #EEEEF0;color:#6B6B75;font-size:13px;line-height:1.6">' .
    mailHtml($user['name']) . '<br><strong style="color:#16181B">' . mailHtml($mail['from_name']) . '</strong></div>',
    'MESSAGE FROM DATAFORT');
$sent = sendAppMail($CONFIG, $lead['email'], $subject, $message . $signature, $headers, $leadHtml);

/* The audit row records that a message went to this LEAD — never the
 * address itself. An audit log containing every recipient address is a
 * second copy of the contact list, sitting in the one table nobody is
 * allowed to delete. */
audit($pdo, $user['tenant_id'], $user, 'email', $ref,
    'Relay email sent — ' . substr($subject, 0, 120), $ctx['device']);

if (!$sent) {
    error_log('[datafort] relay send failed for lead ' . $ref);
    respond(['error' => 'The message could not be sent. Try again shortly.'], 502);
}

$pdo->prepare("UPDATE leads SET last_contacted = NOW() WHERE id = ?")->execute([$lead['id']]);

respond(['ok' => true]);
