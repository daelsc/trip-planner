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
