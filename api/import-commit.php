<?php
/**
 * import-commit.php — turn an uploaded spreadsheet into leads.
 *
 * Accepts CSV and XLSX. Excel is parsed by api/xlsx.php - ZipArchive
 * plus SimpleXML, no Composer. The older binary .xls is refused with an
 * instruction rather than a shrug.
 *
 * With preview=1 it parses an XLSX, returns the header row and a few
 * sample rows, and writes nothing - that is how the column mapper gets
 * its headers without the workbook ever being opened in the browser.
 *
 * The uploaded file is parsed and then DELETED. Datafort must not
 * accumulate copies of the very spreadsheets it exists to get rid of —
 * an uploads folder full of raw purchased lists would be a better
 * target than the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG, 'admin');
$user = $ctx['user'];
$tid  = $user['tenant_id'];

/* Upload failures need naming, not a shrug.
 *
 * A file larger than PHP's upload_max_filesize arrives as an EMPTY
 * $_FILES entry with an error code — the browser sent it, PHP threw it
 * away. Reporting that as "no file received" sends an admin off to
 * check their network while the real answer is a two-line php.ini
 * change. Shared cPanel hosting commonly caps this at 2-8 MB, which is
 * smaller than the lead lists this product exists to import. */
$uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;

if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
    respond([
        'error' => 'That file is larger than this server accepts (' .
                   ini_get('upload_max_filesize') . '). Raise upload_max_filesize ' .
                   'and post_max_size in php.ini, or split the file.',
    ], 413);
}

if ($uploadError === UPLOAD_ERR_PARTIAL) {
    respond(['error' => 'The upload was interrupted. Try again.'], 400);
}

if ($uploadError === UPLOAD_ERR_NO_TMP_DIR || $uploadError === UPLOAD_ERR_CANT_WRITE) {
    error_log('[datafort] upload failed, server temp dir: code ' . $uploadError);
    respond(['error' => 'The server could not store the upload. Contact your host.'], 500);
}

if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    /* An empty $_POST alongside an empty $_FILES is the signature of a
     * body that blew past post_max_size — PHP discards the entire
     * request and every superglobal comes back empty. */
    if (empty($_POST) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        respond([
            'error' => 'The upload exceeded the server post_max_size limit (' .
                       ini_get('post_max_size') . ') and was discarded before PHP saw it. ' .
                       'Raise it in php.ini, or split the file.',
        ], 413);
    }
    respond(['error' => 'No file received.'], 400);
}

$tmp      = $_FILES['file']['tmp_name'];
$fileName = substr((string) ($_FILES['file']['name'] ?? 'upload.csv'), 0, 255);

$isXlsx = (bool) preg_match('/\.xlsx$/i', $fileName);
$isCsv  = (bool) preg_match('/\.csv$/i', $fileName);

if (preg_match('/\.xls$/i', $fileName)) {
    /* The old binary .xls format is a completely different container
     * from .xlsx and reading it needs a real library. Excel converts it
     * in two clicks, which is a better trade than carrying that code. */
    @unlink($tmp);
    respond([
        'error' => 'The older .xls format is not supported. Open it in Excel and ' .
                   'use Save As to produce .xlsx or CSV.',
    ], 415);
}

if (!$isCsv && !$isXlsx) {
    @unlink($tmp);
    respond(['error' => 'Import accepts .csv and .xlsx files only.'], 415);
}

// 40 MB. A lead list far larger than this belongs in a queued job, not
// a web request that PHP will time out on halfway through.
if (filesize($tmp) > 40 * 1024 * 1024) {
    @unlink($tmp);
    respond(['error' => 'File is larger than 40 MB. Split it and import in parts.'], 413);
}

/* ── Preview pass ──
 *
 * An .xlsx is never opened in the admin's browser, so the column mapper
 * has no headers to work from until the server supplies them. This mode
 * parses the workbook, returns the header row plus a few sample rows,
 * and writes nothing.
 *
 * The samples are used to show the admin what each column contains. They
 * are NOT masked here, because at this point the file is still just a
 * file the admin already has on their own disk — masking begins once the
 * rows become leads. */
if (!empty($_POST['preview'])) {
    if (!preg_match('/\.xlsx$/i', $fileName)) {
        @unlink($tmp);
        respond(['error' => 'Preview is only used for .xlsx files.'], 400);
    }

    require_once __DIR__ . '/xlsx.php';

    try {
        $parsed = xlsxRead($tmp);
    } catch (RuntimeException $e) {
        @unlink($tmp);
        respond(['error' => $e->getMessage()], 415);
    } catch (Throwable $e) {
        @unlink($tmp);
        error_log('[datafort] xlsx preview failed: ' . $e->getMessage());
        respond(['error' => 'That workbook could not be read. Try exporting it as CSV.'], 415);
    }

    @unlink($tmp);   // nothing is kept from a preview

    respond([
        'ok'        => true,
        'headers'   => $parsed['headers'],
        'sample'    => array_slice($parsed['rows'], 0, 8),
        'totalRows' => count($parsed['rows']),
    ]);
}

$mapping   = json_decode((string) ($_POST['mapping'] ?? '[]'), true);
$sourceName = trim((string) ($_POST['sourceName'] ?? ''));
$sourceCost = (float) ($_POST['sourceCost'] ?? 0);

if (!is_array($mapping) || !$mapping) {
    @unlink($tmp);
    respond(['error' => 'No column mapping supplied.'], 400);
}

$allowed = ['name','company','designation','phone','alt_phone','email','city','state',
            'industry','company_size','website','linkedin','notes'];

// Column index => canonical field. Anything not in $allowed is dropped
// rather than trusted — the mapping arrives from the browser.
$cols = [];
foreach ($mapping as $idx => $field) {
    if (is_string($field) && in_array($field, $allowed, true)) {
        $cols[(int) $idx] = $field;
    }
}

if (!in_array('phone', $cols, true) && !in_array('email', $cols, true)) {
    @unlink($tmp);
    respond(['error' => 'Map at least one of Phone or Email — a lead with neither is unusable.'], 400);
}

/* ── One row source, two formats ──
 *
 * nextRow() returns the next data row as a flat array, or null at the
 * end. CSV stays a streaming read so a 100k-row file does not have to
 * fit in memory; XLSX is already fully parsed by then, because a zipped
 * XML workbook cannot be read a row at a time without a pull parser.
 *
 * Both skip the header — the browser mapped it by index already. */
$handle = null;
$xlsxRows = null;
$xlsxPos = 0;

if ($isCsv) {
    $handle = fopen($tmp, 'r');
    if (!$handle) {
        @unlink($tmp);
        respond(['error' => 'Could not read the uploaded file.'], 500);
    }
    fgetcsv($handle);   // header row
} else {
    require_once __DIR__ . '/xlsx.php';
    try {
        $parsed = xlsxRead($tmp);
        $xlsxRows = $parsed['rows'];   // xlsxRead has already removed the header
    } catch (RuntimeException $e) {
        @unlink($tmp);
        // xlsxRead's messages are written to be shown to an admin.
        respond(['error' => $e->getMessage()], 415);
    } catch (Throwable $e) {
        @unlink($tmp);
        error_log('[datafort] xlsx parse failed: ' . $e->getMessage());
        respond(['error' => 'That workbook could not be read. Try exporting it as CSV.'], 415);
    }
}

$nextRow = function () use (&$handle, &$xlsxRows, &$xlsxPos) {
    if ($handle !== null) {
        $row = fgetcsv($handle);
        return $row === false ? null : $row;
    }
    return $xlsxPos < count($xlsxRows) ? $xlsxRows[$xlsxPos++] : null;
};

$pdo->beginTransaction();

try {
    $src = $pdo->prepare(
        "INSERT INTO lead_sources (tenant_id, name, cost_total, imported_by, file_name)
         VALUES (?,?,?,?,?)"
    );
    $src->execute([
        $tid,
        $sourceName !== '' ? $sourceName : ('Import ' . date('Y-m-d H:i')),
        $sourceCost,
        $user['id'],
        $fileName,
    ]);
    $sourceId = (int) $pdo->lastInsertId();

    // Lead refs continue from wherever this tenant got to.
    $maxRef = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(ref, 3) AS UNSIGNED)), 4199) FROM leads WHERE tenant_id = ?"
    );
    $maxRef->execute([$tid]);
    $nextRef = (int) $maxRef->fetchColumn() + 1;

    /* INSERT IGNORE against the (tenant_id, dedup_key) unique index does
     * the deduplication in the database rather than in PHP. A 100k-row
     * file would otherwise mean holding every seen key in memory. */
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO leads
         (tenant_id, ref, name, company, designation, phone, alt_phone, email,
          city, state, industry, company_size, website, linkedin, notes,
          source_id, source_cost, acquired_date, dedup_key)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?)"
    );

    $read = 0; $written = 0; $skippedNoContact = 0;

    while (($row = $nextRow()) !== null) {
        $read++;
        if ($read > 200000) break;   // hard ceiling

        $rec = array_fill_keys($allowed, null);
        foreach ($cols as $idx => $field) {
            $rec[$field] = isset($row[$idx]) ? trim((string) $row[$idx]) : null;
        }

        // Dedup key: digits of the phone, else lowercased email.
        $digits = $rec['phone'] ? preg_replace('/\D/', '', $rec['phone']) : '';
        $key = $digits !== '' ? $digits : strtolower((string) $rec['email']);

        if ($key === '') { $skippedNoContact++; continue; }

        $ref = 'L-' . $nextRef;

        $ins->execute([
            $tid, $ref,
            $rec['name'], $rec['company'], $rec['designation'],
            $rec['phone'], $rec['alt_phone'], $rec['email'],
            $rec['city'], $rec['state'], $rec['industry'], $rec['company_size'],
            $rec['website'], $rec['linkedin'], $rec['notes'],
            $sourceId,
            0,
            substr($key, 0, 190),
        ]);

        if ($ins->rowCount() > 0) { $written++; $nextRef++; }
    }

    // Only the CSV path holds a handle; the XLSX path closed its archive
    // inside xlsxRead().
    if (is_resource($handle)) fclose($handle);

    // Per-lead cost, now that the usable count is known.
    if ($written > 0 && $sourceCost > 0) {
        $pdo->prepare("UPDATE leads SET source_cost = ? WHERE source_id = ?")
            ->execute([round($sourceCost / $written, 2), $sourceId]);
    }
    $pdo->prepare("UPDATE lead_sources SET lead_count = ? WHERE id = ?")
        ->execute([$written, $sourceId]);

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    if (is_resource($handle)) fclose($handle);
    @unlink($tmp);
    error_log('[datafort] import failed: ' . $e->getMessage());
    respond(['error' => 'Import failed. Nothing was written.'], 500);
}

// The uploaded copy goes now. See the file header.
@unlink($tmp);

audit($pdo, $tid, $user, 'import', $fileName,
    $written . ' leads imported from ' . $fileName .
    ' (' . ($read - $written - $skippedNoContact) . ' duplicates, ' .
    $skippedNoContact . ' with no contact)', $ctx['device']);

respond([
    'ok'         => true,
    'sourceId'   => $sourceId,
    'read'       => $read,
    'imported'   => $written,
    'duplicates' => max(0, $read - $written - $skippedNoContact),
    'noContact'  => $skippedNoContact,
]);
