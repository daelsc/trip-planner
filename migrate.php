<?php
// One-time migration: import saved_trips/*.json into SQLite
// Run: php migrate.php

require_once __DIR__ . '/db.php';

$dataDir = __DIR__ . '/saved_trips';
$counterFile = "$dataDir/.counter";

if (!is_dir($dataDir)) {
    echo "No saved_trips/ directory found. Nothing to migrate.\n";
    exit(0);
}

$db = getDb();
$files = glob("$dataDir/*.json");

if (!$files) {
    echo "No trip files found.\n";
    exit(0);
}

$imported = 0;
$skipped = 0;

$stmt = $db->prepare("INSERT OR IGNORE INTO trips (id, number, name, route, state, saved_at) VALUES (?, ?, ?, ?, ?, ?)");

foreach ($files as $f) {
    $raw = file_get_contents($f);
    $data = json_decode($raw, true);
    if (!$data || !isset($data['state'])) {
        echo "SKIP (invalid): $f\n";
        $skipped++;
        continue;
    }

    $id = $data['id'] ?? basename($f, '.json');
    $number = $data['number'] ?? null;
    $name = $data['name'] ?? 'Untitled';
    $route = $data['route'] ?? '';
    $state = json_encode($data['state']);
    $saved = $data['saved'] ?? date('c');

    $stmt->execute([$id, $number, $name, $route, $state, $saved]);
    $imported++;
    echo "OK: $id ($name)\n";
}

// Migrate counter
if (file_exists($counterFile)) {
    $maxNum = (int)file_get_contents($counterFile);
    // Verify against DB
    $dbMax = $db->query("SELECT MAX(number) FROM trips")->fetchColumn();
    echo "Counter file: $maxNum, DB max: $dbMax\n";
}

echo "\nMigration complete: $imported imported, $skipped skipped.\n";
echo "You can now remove saved_trips/ once verified.\n";
