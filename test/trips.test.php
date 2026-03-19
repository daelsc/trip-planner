<?php
// test/trips.test.php — PHP unit tests for trip-lib.php
require_once __DIR__ . '/../trip-lib.php';

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

// --- tripEndDate ---
echo "=== tripEndDate ===\n";

// With dd key, last non-empty date
assertEqual(
    tripEndDate(['dd' => '2026-03-20,,2026-03-22'], '2026-03-19'),
    '2026-03-22',
    'tripEndDate: uses last non-empty dd value'
);

// With dd key, only first populated
assertEqual(
    tripEndDate(['dd' => '2026-03-20,,'], '2026-03-19'),
    '2026-03-20',
    'tripEndDate: falls back to earlier dd value'
);

// Empty dd values
assertEqual(
    tripEndDate(['dd' => ',,'], '2026-03-19'),
    '2026-03-19',
    'tripEndDate: all empty dd → fallback to startDate'
);

// No dd key
assertEqual(
    tripEndDate([], '2026-03-19'),
    '2026-03-19',
    'tripEndDate: no dd key → startDate'
);

// Empty state
assertEqual(
    tripEndDate([], ''),
    '',
    'tripEndDate: empty state and empty startDate'
);

// --- buildRoute ---
echo "\n=== buildRoute ===\n";

// Multi-leg
assertEqual(
    buildRoute(['l' => 'KSJC-KLAX,KLAX-KLAS,KLAS-KSJC']),
    'KSJC → KLAX → KLAS → KSJC',
    'buildRoute: multi-leg'
);

// Single leg
assertEqual(
    buildRoute(['l' => 'KTEB-KHOU']),
    'KTEB → KHOU',
    'buildRoute: single leg'
);

// Empty
assertEqual(
    buildRoute([]),
    '',
    'buildRoute: empty state'
);

// No l key
assertEqual(
    buildRoute(['a' => 'G550']),
    '',
    'buildRoute: no l key'
);

// --- Trip number auto-increment (in-memory SQLite) ---
echo "\n=== Trip number auto-increment ===\n";

// Set up in-memory DB
$testDb = new PDO('sqlite::memory:');
$testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testDb->exec("CREATE TABLE trips (
    id TEXT PRIMARY KEY,
    number INTEGER,
    name TEXT,
    route TEXT DEFAULT '',
    purpose TEXT DEFAULT '',
    cargo TEXT DEFAULT '',
    state TEXT DEFAULT '{}',
    version INTEGER DEFAULT 1,
    saved_at TEXT,
    saved_by TEXT DEFAULT 'test',
    flightbridge_trip_id TEXT DEFAULT NULL
)");
$testDb->exec("CREATE TABLE trip_versions (
    trip_id TEXT,
    version INTEGER,
    state TEXT,
    saved_at TEXT,
    saved_by TEXT,
    PRIMARY KEY (trip_id, version)
)");

// Insert two trips
$testDb->exec("INSERT INTO trips (id, number, name, state, version, saved_at) VALUES ('t1', 1, 'Trip 1', '{}', 1, '2026-03-19')");
$testDb->exec("INSERT INTO trips (id, number, name, state, version, saved_at) VALUES ('t2', 2, 'Trip 2', '{}', 1, '2026-03-19')");

$maxNum = $testDb->query("SELECT COALESCE(MAX(number), 0) FROM trips")->fetchColumn();
assertEqual($maxNum + 1, 3, 'Auto-increment: next trip number is 3');

// --- Version capping ---
echo "\n=== Version capping ===\n";

for ($v = 1; $v <= 25; $v++) {
    $testDb->prepare("INSERT INTO trip_versions (trip_id, version, state, saved_at, saved_by) VALUES (?, ?, '{}', '2026-03-19', 'test')")
        ->execute(['t1', $v]);
}

$count = $testDb->query("SELECT COUNT(*) FROM trip_versions WHERE trip_id = 't1'")->fetchColumn();
assertEqual((int)$count, 25, 'Before cap: 25 versions exist');

$testDb->prepare("DELETE FROM trip_versions WHERE trip_id = ? AND version NOT IN (SELECT version FROM trip_versions WHERE trip_id = ? ORDER BY version DESC LIMIT 20)")
    ->execute(['t1', 't1']);

$count = $testDb->query("SELECT COUNT(*) FROM trip_versions WHERE trip_id = 't1'")->fetchColumn();
assertEqual((int)$count, 20, 'After cap: 20 versions remain');

$minVer = $testDb->query("SELECT MIN(version) FROM trip_versions WHERE trip_id = 't1'")->fetchColumn();
assertEqual((int)$minVer, 6, 'Oldest remaining version is 6');

// --- Summary ---
echo "\n" . ($fail === 0 ? "OK" : "FAILED") . ": $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
