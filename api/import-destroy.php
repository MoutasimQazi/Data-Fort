<?php
/**
 * import-destroy.php — record that the original spreadsheet is gone.
 *
 * Closes the gap in requirements section 6. Datafort can protect its own
 * copy perfectly and still be pointless if the source .xlsx is sitting
 * on a laptop where anyone can forward it.
 *
 * This records a CLAIM, not a fact — nobody can verify from a web
 * server that a file was deleted from someone's machine. What it gives
 * you is an attributable, timestamped statement from a named
 * administrator, and a list of imports where no such statement exists.
 * The dashboard surfaces the second list.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG, 'admin');
$user = $ctx['user'];
$tid  = $user['tenant_id'];

$in       = body();
$sourceId = (int) ($in['sourceId'] ?? 0);

if ($sourceId <= 0) {
    respond(['error' => 'Source id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM lead_sources WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$sourceId, $tid]);
$source = $stmt->fetch();

if (!$source) {
    respond(['error' => 'Import not found'], 404);
}

if ($source['source_destroyed_at'] !== null) {
    respond(['ok' => true, 'alreadyRecorded' => true]);
}

$pdo->prepare(
    "UPDATE lead_sources SET source_destroyed_at = NOW(), source_destroyed_by = ? WHERE id = ?"
)->execute([$user['id'], $sourceId]);

audit($pdo, $tid, $user, 'import', $source['file_name'] ?: $source['name'],
    'Confirmed the source spreadsheet was destroyed', $ctx['device']);

respond(['ok' => true]);
