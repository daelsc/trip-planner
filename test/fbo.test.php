<?php
// test/fbo.test.php — PHP unit tests for fbo-lib.php
require_once __DIR__ . '/../fbo-lib.php';

$pass = 0;
$fail = 0;

function assertEqual($actual, $expected, $msg) {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $msg\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

function assertTrue($val, $msg) {
    global $pass, $fail;
    if ($val) { $pass++; } else { $fail++; echo "FAIL: $msg\n"; }
}

// --- parseFbos with fixture ---
echo "=== parseFbos ===\n";

$html = file_get_contents(__DIR__ . '/fixtures/airnav-sample.html');
$fbos = parseFbos($html);

assertTrue(count($fbos) === 3, 'parseFbos: found 3 FBOs, got ' . count($fbos));

// First FBO: from IMG alt
assertEqual($fbos[0]['name'], 'Signature Aviation', 'parseFbos: first FBO name from IMG alt');
assertEqual($fbos[0]['phone'], '408-555-1234', 'parseFbos: first FBO phone');
assertEqual($fbos[0]['fuel'], 'Jet-A 100LL', 'parseFbos: first FBO fuel');

// Second FBO: from bold link
assertEqual($fbos[1]['name'], 'ACM Aviation', 'parseFbos: second FBO name from bold link');
assertEqual($fbos[1]['phone'], '(650) 555-9999', 'parseFbos: second FBO phone');
assertEqual($fbos[1]['fuel'], 'Jet-A', 'parseFbos: second FBO fuel');

// Third FBO: from plain link
assertEqual($fbos[2]['name'], 'Bay Area Aero Club', 'parseFbos: third FBO name from plain link');
assertTrue(!isset($fbos[2]['phone']), 'parseFbos: third FBO has no phone');
assertEqual($fbos[2]['fuel'], '100LL MOGAS', 'parseFbos: third FBO fuel');

// --- Empty/missing FBO section ---
echo "\n=== parseFbos edge cases ===\n";

$emptyFbos = parseFbos('<html><body>No FBO section here</body></html>');
assertEqual($emptyFbos, [], 'parseFbos: no biz section → empty array');

$noFboRows = parseFbos('<html><body><A name="biz"></A><H3>FBOs</H3><A name="links"></body></html>');
assertEqual($noFboRows, [], 'parseFbos: biz section but no FBO rows → empty array');

// --- cleanAddress ---
echo "\n=== cleanAddress ===\n";

assertEqual(
    cleanAddress('123 Main St, San Jose, CA 95110, USA'),
    '123 Main St, San Jose, CA',
    'cleanAddress: strips zip and USA'
);

assertEqual(
    cleanAddress('456 Oak Ave, Dallas, TX 75201'),
    '456 Oak Ave, Dallas, TX',
    'cleanAddress: strips zip only'
);

assertEqual(
    cleanAddress('789 Elm St, London, UK'),
    '789 Elm St, London, UK',
    'cleanAddress: non-US address unchanged'
);

assertEqual(
    cleanAddress('100 Airport Rd, Teterboro, NJ 07608-1203, USA'),
    '100 Airport Rd, Teterboro, NJ',
    'cleanAddress: strips zip+4 and USA'
);

assertEqual(
    cleanAddress(null),
    null,
    'cleanAddress: null → null'
);

// --- Summary ---
echo "\n" . ($fail === 0 ? "OK" : "FAILED") . ": $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
