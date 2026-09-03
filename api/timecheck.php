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

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

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
    echo 'Database connection failed: ' . $e->getMessage() . "\n";
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
    echo "Could not read audit_log: " . $e->getMessage() . "\n\n";
}

/* ── Verdict ─────────────────────────────────────────────────────── */

echo str_repeat('=', 62) . "\n";
if ($offsetSecs === 0) {
    echo "VERDICT: this MySQL already runs in UTC.\n\n";
    echo "Rows already stored are UTC, the app now pins the session to UTC\n";
    echo "explicitly, and the API sends timestamps with a Z. Nothing further\n";
    echo "to do — do NOT run the migration, it would shift correct rows.\n";
} else {
    printf("VERDICT: this MySQL runs at UTC%+.2f, not UTC.\n\n", $offsetHrs);
    echo "Every row written before this fix holds server-local wall clock.\n";
    echo "The app now writes UTC, so old and new rows are in different zones\n";
    echo "until you convert the old ones. Run this ONCE, against a backup:\n\n";
    printf("    mysql -u USER -p %s < api/migrations/009_utc_timestamps.sql\n\n", $db['name']);
    printf("The migration shifts existing rows by %+d seconds.\n", -$offsetSecs);
    echo "Open it first — it carries the offset as a variable you must set\n";
    echo "to the value printed here, because only this server knows it.\n";
}

echo "\nDelete this file now.\n";
