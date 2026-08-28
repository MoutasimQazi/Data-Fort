<?php
/**
 * mtls-test.php — throwaway diagnostic. DELETE IT once mTLS verifies.
 *
 * Answers three questions in order, because they fail in order:
 *   1. Is Apache exposing SSL_* to PHP at all?      (SSLOptions +StdEnvVars)
 *   2. Did the client present a certificate?        (SSLVerifyClient)
 *   3. Is it the one Datafort expects?              (issuer / CN / serial)
 *
 * Reads REDIRECT_-prefixed copies too: any .htaccess rewrite causes an
 * internal redirect, and Apache re-exposes the variables under that
 * prefix. Reading only the bare names makes a working setup look dead.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/** Same lookup order as api/device.php::sslVar(). */
function v(string $name): string {
    foreach ([$name, 'REDIRECT_' . $name, 'REDIRECT_REDIRECT_' . $name] as $k) {
        if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return (string) $_SERVER[$k];
    }
    return '';
}

$EXPECTED_ISSUER_CN = 'Movenetics Digital Device CA';

echo "== every SSL_* Apache handed PHP ==\n";
$found = 0;
foreach ($_SERVER as $k => $val) {
    if (strpos($k, 'SSL_') === 0 || strpos($k, 'REDIRECT_SSL_') === 0) {
        if (strpos($k, '_CERT') !== false) { $val = '<PEM, ' . strlen($val) . " bytes>"; }
        printf("%-34s = %s\n", $k, $val);
        $found++;
    }
}
if (!$found) echo "(none)\n";

$verify   = v('SSL_CLIENT_VERIFY');
$issuerCn = v('SSL_CLIENT_I_DN_CN');
$cn       = v('SSL_CLIENT_S_DN_CN');
$serial   = v('SSL_CLIENT_M_SERIAL');

echo "\n== verdict ==\n";

if ($verify === '') {
    echo "STEP 1 FAILED: PHP cannot see SSL_CLIENT_VERIFY.\n";
    echo "  The vhost Include is not loaded, or 'SSLOptions +StdEnvVars\n";
    echo "  +ExportCertData' is missing. Nothing else can work until this does.\n";
    exit;
}
echo "STEP 1 OK: Apache is exposing client certificate variables.\n";

if ($verify === 'NONE') {
    echo "STEP 2: no certificate was presented (SSL_CLIENT_VERIFY = NONE).\n";
    echo "  Correct before enrolment. After enrolment it means the browser had\n";
    echo "  no certificate matching the CA Apache advertises -- check that the\n";
    echo "  laptop cert's issuer is exactly: {$EXPECTED_ISSUER_CN}\n";
    exit;
}
if (strpos($verify, 'FAILED') === 0) {
    echo "STEP 2 FAILED: Apache rejected the certificate -- {$verify}\n";
    echo "  Usually a chain mismatch, an expired cert, or SSLVerifyDepth too low.\n";
    exit;
}
echo "STEP 2 OK: certificate presented and verified.\n";

echo "\nCN     = {$cn}\n";
echo "issuer = {$issuerCn}\n";
echo "serial = {$serial}\n";

echo "\n";
if (strcasecmp($issuerCn, $EXPECTED_ISSUER_CN) !== 0) {
    echo "STEP 3 FAILED: signed by '{$issuerCn}', expected '{$EXPECTED_ISSUER_CN}'.\n";
    echo "  Reissue the laptop certificate from the correct CA.\n";
    exit;
}

// Datafort's normalisation, so the printed value is exactly what belongs
// in company_devices.certificate_serial.
$norm = ltrim(preg_replace('/[^0-9A-F]/', '', preg_replace('/^0X/', '', strtoupper(trim($serial)))), '0');
echo "STEP 3 OK: correct CA.\n";
echo "Register this device as:  device code {$cn}, serial " . ($norm === '' ? '0' : $norm) . "\n";
