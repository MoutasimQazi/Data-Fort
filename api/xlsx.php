<?php
/**
 * xlsx.php — minimal Excel reader. No Composer, no dependencies.
 *
 * The problem this product exists to solve is stated as "startups
 * operate on excel sheet". An importer that only accepts CSV asks every
 * customer to convert their file before they can use the thing that is
 * supposed to replace it.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY NOT PhpSpreadsheet
 *
 * PhpSpreadsheet is the obvious choice and it is a good library. It also
 * means adding Composer and roughly a hundred transitive files to a
 * product whose pitch is that data does not leak, running on shared
 * cPanel hosting where nobody will be watching for advisories. An .xlsx
 * is a ZIP of XML, and PHP already ships ZipArchive and SimpleXML.
 * Reading one is a couple of hundred lines.
 *
 * WHAT THIS DOES NOT DO
 *
 * Formulas (returns the cached value), styling, multiple sheets (reads
 * the first), charts, macros. For a lead list that is all irrelevant —
 * these files are rectangles of text. If a customer ever needs real
 * spreadsheet semantics, revisit; do not quietly extend this.
 *
 * SECURITY
 *
 * The file comes from outside and is opened on the server. Three
 * defences, all necessary:
 *
 *   1. XXE — libxml_disable_entity_loader / LIBXML_NONET. An XLSX is
 *      XML, and XML from a stranger is an XXE attempt until proven
 *      otherwise. Without this, a crafted sheet reads /etc/passwd or
 *      makes the server fetch a URL of the attacker's choosing.
 *   2. Zip bombs — the uncompressed size is checked BEFORE extracting.
 *      A 40 KB xlsx can expand to gigabytes.
 *   3. Path traversal — entries are read by name from the archive, never
 *      extracted to disk, so "../../" in an entry name goes nowhere.
 * ─────────────────────────────────────────────────────────────────
 */

/** Total uncompressed bytes we are willing to expand. */
const XLSX_MAX_UNCOMPRESSED = 200 * 1024 * 1024;   // 200 MB
const XLSX_MAX_ROWS         = 200000;

/**
 * Reads the first worksheet.
 *
 * Returns ['headers' => [...], 'rows' => [[...], ...]]
 * Throws RuntimeException with a message safe to show the admin.
 */
function xlsxRead(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException(
            'This server has no ZipArchive extension, so .xlsx files cannot be read. ' .
            'Save the file as CSV instead, or ask the host to enable php-zip.'
        );
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException(
            'That file is not a readable .xlsx. If it is an older .xls, open it in ' .
            'Excel and use Save As to produce .xlsx or CSV.'
        );
    }

    // ── Zip bomb check, before anything is decompressed ──
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $total += (int) ($stat['size'] ?? 0);

        if ($total > XLSX_MAX_UNCOMPRESSED) {
            $zip->close();
            throw new RuntimeException(
                'That file expands to more than 200 MB and was rejected. ' .
                'Split it, or export it as CSV.'
            );
        }
    }

    try {
        $strings = xlsxSharedStrings($zip);
        $sheet   = xlsxFirstSheetPath($zip);
        $xml     = $zip->getFromName($sheet);

        if ($xml === false) {
            throw new RuntimeException('The workbook has no readable worksheet.');
        }

        $rows = xlsxParseSheet($xml, $strings);

    } finally {
        $zip->close();
    }

    if (!$rows) {
        throw new RuntimeException('That worksheet is empty.');
    }

    $headers = array_shift($rows);
    $headers = array_map(function ($h) { return trim((string) $h); }, $headers);

    // Drop rows that are entirely blank — Excel loves trailing them.
    $rows = array_values(array_filter($rows, function ($r) {
        foreach ($r as $v) { if (trim((string) $v) !== '') return true; }
        return false;
    }));

    return ['headers' => $headers, 'rows' => $rows];
}


/** Parses an XML string with entity resolution and network access off. */
function xlsxXml(string $xml): SimpleXMLElement
{
    // Deprecated and a no-op from PHP 8.0, where the unsafe default was
    // finally removed. Called anyway so this is safe on 7.x hosting too.
    if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
        libxml_disable_entity_loader(true);
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
    libxml_use_internal_errors($prev);

    if ($doc === false) {
        throw new RuntimeException('The workbook contains XML this reader cannot parse.');
    }
    return $doc;
}


/**
 * The shared string table. Excel stores most text once here and
 * references it by index from the cells, so without this every text cell
 * reads as a number.
 */
function xlsxSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return [];

    $doc = xlsxXml($xml);
    $out = [];

    foreach ($doc->si as $si) {
        // A cell can be split into several runs (<r>) when part of it is
        // formatted differently. Concatenate them or the value arrives
        // truncated at the first style change.
        if (isset($si->r)) {
            $text = '';
            foreach ($si->r as $run) { $text .= (string) $run->t; }
            $out[] = $text;
        } else {
            $out[] = (string) $si->t;
        }
    }
    return $out;
}


/** Path of the first worksheet inside the archive. */
function xlsxFirstSheetPath(ZipArchive $zip): string
{
    // The usual location. Checked first because resolving it properly
    // means reading workbook.xml and its rels, and for a file exported
    // by Excel or Google Sheets this is simply correct.
    if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
            return $name;
        }
    }

    throw new RuntimeException('No worksheet found inside the workbook.');
}


/** Column reference to zero-based index: A→0, Z→25, AA→26. */
function xlsxColIndex(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}


function xlsxParseSheet(string $xml, array $strings): array
{
    $doc  = xlsxXml($xml);
    $rows = [];

    if (!isset($doc->sheetData)) return $rows;

    foreach ($doc->sheetData->row as $row) {
        if (count($rows) >= XLSX_MAX_ROWS) break;

        $cells = [];
        $maxCol = -1;

        foreach ($row->c as $c) {
            $ref  = (string) $c['r'];
            $type = (string) $c['t'];

            /* Cell position is taken from its r="B7" reference, never
             * from its order in the file. Excel omits empty cells
             * entirely, so counting them shifts every column after the
             * first gap — which silently maps phone numbers into the
             * city field. */
            $col = $ref !== '' ? xlsxColIndex($ref) : count($cells);

            if ($type === 'inlineStr') {
                $value = isset($c->is->t) ? (string) $c->is->t : '';
                if (isset($c->is->r)) {
                    $value = '';
                    foreach ($c->is->r as $run) { $value .= (string) $run->t; }
                }
            } elseif ($type === 's') {
                $idx   = (int) $c->v;
                $value = $strings[$idx] ?? '';
            } else {
                // Numbers, dates and formula results all land here. A
                // formula cell carries its last cached value in <v>,
                // which is what a lead list needs.
                $value = isset($c->v) ? (string) $c->v : '';
            }

            $cells[$col] = $value;
            if ($col > $maxCol) $maxCol = $col;
        }

        // Fill the gaps so every row is the same width and column N
        // means the same thing on every line.
        $flat = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $flat[] = $cells[$i] ?? '';
        }

        $rows[] = $flat;
    }

    return $rows;
}
