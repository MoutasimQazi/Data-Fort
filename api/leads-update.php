<?php
/**
 * leads-update.php — a rep working their lead: status, note, call logged.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG);
$user = $ctx['user'];

$in     = body();
$ref    = trim((string) ($in['lead'] ?? ''));
$status = (string) ($in['status'] ?? '');
$note   = trim((string) ($in['note'] ?? ''));
$logCall = !empty($in['logCall']);

// Ownership in the WHERE clause, same as lead-reveal.php. A rep cannot
// update a lead that is not theirs, and cannot learn whether it exists.
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
    audit($pdo, $user['tenant_id'], $user, 'blocked', $ref, 'Update denied — not owned or not found', $ctx['device']);
    respond(['error' => 'Lead not available'], 404);
}

$sets = [];
$vals = [];

if (in_array($status, ['new', 'working', 'won', 'lost'], true) && $status !== $lead['status']) {
    $sets[] = 'status = ?';
    $vals[] = $status;
}

if ($logCall) {
    $sets[] = 'last_contacted = NOW()';
}

if ($note !== '') {
    /* Notes append rather than replace. A rep should not be able to
     * quietly erase what they wrote three weeks ago, and the history is
     * often the only context the next person has. */
    $sets[] = "notes = CONCAT(COALESCE(notes,''), ?)";
    $vals[] = "\n[" . date('Y-m-d H:i') . ' ' . $user['name'] . "] " . substr($note, 0, 2000);
}

if (!$sets) {
    respond(['ok' => true, 'changed' => false]);
}

$vals[] = $lead['id'];
$pdo->prepare("UPDATE leads SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

if ($status && $status !== $lead['status']) {
    audit($pdo, $user['tenant_id'], $user, 'status', $ref,
        $lead['status'] . ' → ' . $status . ($note !== '' ? ' — ' . substr($note, 0, 200) : ''),
        $ctx['device']);
}
if ($logCall) {
    audit($pdo, $user['tenant_id'], $user, 'view', $ref, 'Call logged', $ctx['device']);
}

respond(['ok' => true, 'changed' => true]);
