<?php
function getDb() {
    $db = new PDO('sqlite:' . __DIR__ . '/flights.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        email TEXT UNIQUE NOT NULL,
        name TEXT,
        allowed INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS trips (
        id TEXT PRIMARY KEY,
        number INTEGER,
        name TEXT,
        route TEXT,
        purpose TEXT,
        cargo TEXT,
        state TEXT NOT NULL,
        version INTEGER DEFAULT 1,
        saved_at TEXT DEFAULT (datetime('now')),
        saved_by TEXT
    )");

    // Add flightbridge_trip_id column if missing
    $cols = $db->query("PRAGMA table_info(trips)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('flightbridge_trip_id', $cols)) {
        $db->exec("ALTER TABLE trips ADD COLUMN flightbridge_trip_id TEXT");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS trip_locks (
        trip_id TEXT PRIMARY KEY,
        locked_by TEXT NOT NULL,
        locked_name TEXT,
        locked_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS trip_versions (
        id INTEGER PRIMARY KEY,
        trip_id TEXT NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
        version INTEGER NOT NULL,
        state TEXT NOT NULL,
        saved_at TEXT NOT NULL,
        saved_by TEXT,
        UNIQUE(trip_id, version)
    )");

    return $db;
}
