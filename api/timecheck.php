<?php
/**
 * timecheck.php — says exactly what every clock in the stack thinks the
 * time is, and whether stored rows need migrating.
 *
 * Standalone on purpose, the same way dbtest.php is: it includes
 * NOTHING, so it still answers when db.php or http.php are stale on the
 * server. Upload, open in a browser, read the verdict.
 *
 * ⚠ DELETE IT AFTERWARDS. It names the database and prints row
 *   timestamps. It is a setup tool, not part of the app.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

/* HTML rather than text/plain for one reason: the browser is the only
 * correctly-set clock this script can reach. A timezone can be read out
 * of MySQL, but "is the server's clock actually right?" cannot be
 * answered from inside the server — it needs an outside reference, and
 * the laptop looking at this page is one. The skew line below is filled
 * in by JS comparing the two.
 *
 * This matters: a server can be perfectly configured for UTC and still
 * be two hours wrong. Without this check the verdict further down would
 * cheerfully report "already runs in UTC" while every timestamp on the
 * site was drifting. */
$serverEpochMs = (int) round(microtime(true) * 1000);
?><!doctype html>
<meta charset="utf-8">
<title>Datafort · clock check</title>
<style>
  body { background:#0c0c0e; color:#e8e8ea; font:14px ui-monospace,Consolas,monospace;
         margin:0; padding:28px; }
  pre  { white-space:pre-wrap; margin:0; }
  #skew { border:1px solid #2c2c32; border-radius:8px; padding:16px 18px;
          margin:0 0 22px; background:#151518; }
  b.bad  { color:#f0564b; }
  b.good { color:#4ade80; }
</style>
<div id="skew">checking the server clock against this browser…</div>
<script>
  (function () {
    var server = <?php echo $serverEpochMs; ?>;
    // Network transit makes the browser's reading slightly later than
    // the server's, so a second or two of positive skew is normal and
    // not worth flagging.
    var skew = Math.round((server - Date.now()) / 1000);
    var box = document.getElementById('skew');
    var abs = Math.abs(skew);
    var human = abs < 60 ? abs + 's'
      : abs < 3600 ? Math.floor(abs / 60) + 'm ' + (abs % 60) + 's'
      : Math.floor(abs / 3600) + 'h ' + Math.floor((abs % 3600) / 60) + 'm';

    if (abs <= 5) {
      box.innerHTML = 'SERVER CLOCK: <b class="good">correct</b> — within ' + human +
        ' of this browser.';
    } else {
      box.innerHTML = 'SERVER CLOCK: <b class="bad">WRONG by ' + human + '</b> (' +
        (skew > 0 ? 'server is AHEAD of' : 'server is BEHIND') + ' this machine).<br><br>' +
        'This is not a timezone problem — timezone offsets are whole ' +
        'multiples of 15 minutes and no code change can correct a clock ' +
        'that is simply wrong. Fix it on the host (enable NTP / ask your ' +
        'provider to sync the system clock), then reload this page.<br><br>' +
        'Assumes this machine\'s own clock is right — check that first if ' +
        'the number looks impossible.';
    }
  })();
</script>
<pre>
<?php
echo "Datafort — clock and timezone check\n";
echo str_repeat('=', 62) . "\n\n";

/* ── PHP ─────────────────────────────────────────────────────────── */

echo "PHP\n";
echo '  date.timezone (php.ini) : ' . (ini_get('date.timezone') ?: '(not set)') . "\n";
echo '  date_default_timezone   : ' . date_default_timezone_get() . "\n";
echo '  PHP now                 : ' . date('Y-m-d H:i:s') . "\n";
echo '  PHP now (UTC)           : ' . gmdate('Y-m-d H:i:s') . "\n\n";

/* ── Database ────────────────────────────────────────────────────── */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    echo "api/config.php not found — cannot check the database half.\n";
    exit;
}

$CONFIG = require $configPath;
$db = $CONFIG['db'] ?? null;
if (!$db) {
    echo "config.php has no 'db' section.\n";
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
    );
} catch (PDOException $e) {
    echo 'Database connection failed: ' . htmlspecialchars($e->getMessage()) . "\n";
    echo "Run api/dbtest.php for help with that first.\n";
    exit;
}

/* What the connection looks like WITHOUT the app's UTC pin — this is
 * the state every row already in the database was written under. */
$row = $pdo->query(
    "SELECT @@global.time_zone   AS g,
            @@session.time_zone  AS s,
            @@system_time_zone   AS sys,
            NOW()                AS now_local,
            UTC_TIMESTAMP()      AS now_utc,
            TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_secs"
)->fetch(PDO::FETCH_ASSOC);

$offsetSecs = (int) $row['offset_secs'];
$offsetHrs  = $offsetSecs / 3600;

echo "MySQL (as this server connects by default)\n";
echo '  @@global.time_zone      : ' . $row['g'] . "\n";
echo '  @@session.time_zone     : ' . $row['s'] . "\n";
echo '  @@system_time_zone      : ' . $row['sys'] . "\n";
echo '  NOW()                   : ' . $row['now_local'] . "\n";
echo '  UTC_TIMESTAMP()         : ' . $row['now_utc'] . "\n";
printf("  NOW() is UTC%+.2f hours\n\n", $offsetHrs);

/* Confirm the app's pin actually takes on this MySQL build. */
$pdo->exec("SET time_zone = '+00:00'");
$pinned = $pdo->query('SELECT NOW() AS n')->fetch(PDO::FETCH_ASSOC);
echo "With the app's pin applied (SET time_zone = '+00:00')\n";
echo '  NOW()                   : ' . $pinned['n'] . "\n";
echo '  matches UTC             : '
     . (abs(strtotime($pinned['n']) - strtotime($row['now_utc'])) <= 2 ? 'yes' : 'NO')
     . "\n\n";

/* ── Newest stored row ───────────────────────────────────────────── */

echo str_repeat('-', 62) . "\n";
try {
    $last = $pdo->query('SELECT MAX(at) AS at FROM audit_log')->fetchColumn();
    if ($last) {
        $ageHrs = (strtotime((string) $row['now_utc']) - strtotime((string) $last)) / 3600;
        echo 'Newest audit_log row      : ' . $last . "\n";
        printf("Age if read as UTC        : %+.2f hours\n", $ageHrs);
        echo "  (a row written moments ago should be near 0. If it is near\n";
        echo "   the offset printed above, existing rows are in server-local\n";
        echo "   time and want the migration below.)\n\n";
    } else {
        echo "audit_log is empty — nothing to migrate.\n\n";
    }
} catch (PDOException $e) {
    echo "Could not read audit_log: " . htmlspecialchars($e->getMessage()) . "\n\n";
}

/* ── Verdict ─────────────────────────────────────────────────────── */

echo str_repeat('=', 62) . "\n";
if ($offsetSecs === 0) {
    echo "VERDICT: this MySQL already runs in UTC.\n\n";
    echo "Rows already stored are UTC, the app now pins the session to UTC\n";
    echo "explicitly, and the API sends timestamps with a Z. No migration\n";
    echo "needed — do NOT run it, it would shift correct rows.\n\n";
    echo "NOTE: this only says the ZONE is right. It says nothing about\n";
    echo "whether the clock itself is. A server can be correctly set to UTC\n";
    echo "and still be hours wrong, and every timestamp in the app would be\n";
    echo "wrong with it. Read the SERVER CLOCK box at the top of this page —\n";
    echo "that is the check that catches it.\n";
} else {
    printf("VERDICT: this MySQL runs at UTC%+.2f, not UTC.\n\n", $offsetHrs);
    echo "Every row written before this fix holds server-local wall clock.\n";
    echo "The app now writes UTC, so old and new rows are in different zones\n";
    echo "until you convert the old ones. Run this ONCE, against a backup:\n\n";
    printf("    mysql -u USER -p %s &lt; api/migrations/009_utc_timestamps.sql\n\n",
           htmlspecialchars((string) $db['name']));
    printf("The migration shifts existing rows by %+d seconds.\n", -$offsetSecs);
    echo "Open it first — it carries the offset as a variable you must set\n";
    echo "to the value printed here, because only this server knows it.\n";
}

echo "\nDelete this file now.\n";

// Closes the <pre> opened above the report.
echo "</pre>\n";
