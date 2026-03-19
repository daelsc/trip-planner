<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$db = getDb();

function tripEndDate($state, $startDate) {
    // Best effort: use last departure date override, or fall back to start date
    if (!empty($state['dd'])) {
        $dates = explode(',', $state['dd']);
        for ($i = count($dates) - 1; $i >= 0; $i--) {
            if (!empty($dates[$i])) return $dates[$i];
        }
    }
    return $startDate;
}

// Expire stale locks (no heartbeat in 60s)
$db->exec("DELETE FROM trip_locks WHERE datetime(locked_at) < datetime('now', '-60 seconds')");

// Protect write operations
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Lock actions (require auth)
if ($action === 'lock' && $method === 'POST') {
    if (empty($_SESSION['authed'])) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $email = $_SESSION['user_email'] ?? 'shared';
    $name = $_SESSION['user_name'] ?? 'Unknown';
    // Check existing lock
    $stmt = $db->prepare("SELECT locked_by, locked_name FROM trip_locks WHERE trip_id = ?");
    $stmt->execute([$id]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lock && $lock['locked_by'] !== $email) {
        echo json_encode(['ok' => false, 'locked_by' => $lock['locked_by'], 'locked_name' => $lock['locked_name']]);
        exit;
    }
    $db->prepare("INSERT OR REPLACE INTO trip_locks (trip_id, locked_by, locked_name, locked_at) VALUES (?, ?, ?, datetime('now'))")->execute([$id, $email, $name]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'heartbeat' && $method === 'POST') {
    if (empty($_SESSION['authed'])) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $email = $_SESSION['user_email'] ?? 'shared';
    $stmt = $db->prepare("UPDATE trip_locks SET locked_at = datetime('now') WHERE trip_id = ? AND locked_by = ?");
    $stmt->execute([$id, $email]);
    echo json_encode(['ok' => $stmt->rowCount() > 0]);
    exit;
}

if ($action === 'unlock' && $method === 'POST') {
    if (empty($_SESSION['authed'])) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $email = $_SESSION['user_email'] ?? 'shared';
    $db->prepare("DELETE FROM trip_locks WHERE trip_id = ? AND locked_by = ?")->execute([$id, $email]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'checklock' && $method === 'GET') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $stmt = $db->prepare("SELECT locked_by, locked_name FROM trip_locks WHERE trip_id = ?");
    $stmt->execute([$id]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['locked' => !!$lock, 'locked_by' => $lock['locked_by'] ?? null, 'locked_name' => $lock['locked_name'] ?? null]);
    exit;
}

if ($method !== 'GET' && empty($_SESSION['authed'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// List all saved trips
if ($method === 'GET' && !isset($_GET['id'])) {
    $rows = $db->query("SELECT id, number, name, route, purpose, cargo, state, version, saved_at, saved_by, flightbridge_trip_id FROM trips ORDER BY saved_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $trips = [];
    foreach ($rows as $r) {
        $state = json_decode($r['state'], true) ?: [];
        $tripDate = '';
        if (isset($state['t'])) {
            $tripDate = substr($state['t'], 0, 10);
        }
        $trips[] = [
            'id' => $r['id'],
            'number' => $r['number'],
            'name' => $r['name'] ?? 'Untitled',
            'route' => $r['route'] ?? '',
            'purpose' => $r['purpose'] ?? '',
            'cargo' => $r['cargo'] ?? '',
            'aircraft' => $state['a'] ?? 'G550',
            'date' => $tripDate,
            'endDate' => tripEndDate($state, $tripDate),
            'saved' => $r['saved_at'] ?? '',
            'savedBy' => $r['saved_by'] ?? '',
            'version' => $r['version'] ?? 1,
            'flightbridgeTripId' => $r['flightbridge_trip_id'] ?? null,
        ];
    }
    // Sort chronologically by trip date (earliest first), then by number
    usort($trips, function($a, $b) {
        $da = $a['date'] ?: '9999';
        $db_ = $b['date'] ?: '9999';
        $cmp = strcmp($da, $db_);
        return $cmp !== 0 ? $cmp : (($a['number'] ?? 0) - ($b['number'] ?? 0));
    });
    echo json_encode($trips);
    exit;
}

// Load a specific trip (with optional version or history)
if ($method === 'GET' && isset($_GET['id'])) {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id']);

    // Version history list
    if (isset($_GET['history'])) {
        $stmt = $db->prepare("SELECT version, saved_at, saved_by FROM trip_versions WHERE trip_id = ? ORDER BY version DESC");
        $stmt->execute([$id]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Also include current version
        $cur = $db->prepare("SELECT version, saved_at, saved_by FROM trips WHERE id = ?");
        $cur->execute([$id]);
        $current = $cur->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['current' => $current, 'versions' => $versions]);
        exit;
    }

    // Load specific version
    if (isset($_GET['version'])) {
        $ver = (int)$_GET['version'];
        $stmt = $db->prepare("SELECT state, saved_at, saved_by FROM trip_versions WHERE trip_id = ? AND version = ?");
        $stmt->execute([$id, $ver]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Version not found']);
            exit;
        }
        $row['state'] = json_decode($row['state'], true);
        echo json_encode($row);
        exit;
    }

    // Load current trip
    $stmt = $db->prepare("SELECT * FROM trips WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Trip not found']);
        exit;
    }
    $out = [
        'id' => $row['id'],
        'number' => $row['number'],
        'name' => $row['name'],
        'route' => $row['route'],
        'purpose' => $row['purpose'],
        'cargo' => $row['cargo'],
        'state' => json_decode($row['state'], true),
        'saved' => $row['saved_at'],
        'savedBy' => $row['saved_by'],
        'version' => $row['version'],
        'flightbridgeTripId' => $row['flightbridge_trip_id'] ?? null,
    ];
    echo json_encode($out);
    exit;
}

// Delete a trip
if (($method === 'POST' && ($_GET['action'] ?? '') === 'delete') || $method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? ($_GET['id'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $stmt = $db->prepare("DELETE FROM trips WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Trip not found']);
    }
    exit;
}

// Save a trip
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['name']) || !isset($input['state'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing name or state']);
        exit;
    }
    $id = $input['id'] ?? bin2hex(random_bytes(6));
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

    // Build route string from legs
    $route = '';
    $state = $input['state'];
    if (isset($state['l'])) {
        $pairs = explode(',', $state['l']);
        $airports = [];
        foreach ($pairs as $p) {
            $parts = explode('-', $p);
            if (empty($airports)) $airports[] = $parts[0] ?? '';
            $airports[] = $parts[1] ?? '';
        }
        $route = implode(' → ', $airports);
    }

    // Extract purpose and cargo from state
    $purpose = $state['pu'] ?? '';
    $cargo = $state['cg'] ?? '';

    $stateJson = json_encode($state);
    $userEmail = $_SESSION['user_email'] ?? 'shared';
    $now = date('c');

    // Check if trip exists
    $existing = $db->prepare("SELECT number, version, state FROM trips WHERE id = ?");
    $existing->execute([$id]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingRow) {
        // Archive current version before updating
        $curVersion = $existingRow['version'] ?? 1;
        $archiveStmt = $db->prepare("INSERT OR IGNORE INTO trip_versions (trip_id, version, state, saved_at, saved_by)
            SELECT id, version, state, saved_at, saved_by FROM trips WHERE id = ?");
        $archiveStmt->execute([$id]);

        $newVersion = $curVersion + 1;
        $number = $existingRow['number'];

        $stmt = $db->prepare("UPDATE trips SET name = ?, route = ?, purpose = ?, cargo = ?, state = ?, version = ?, saved_at = ?, saved_by = ? WHERE id = ?");
        $stmt->execute([substr($input['name'], 0, 100), $route, $purpose, $cargo, $stateJson, $newVersion, $now, $userEmail, $id]);

        // Cap at 20 versions
        $db->prepare("DELETE FROM trip_versions WHERE trip_id = ? AND version NOT IN (SELECT version FROM trip_versions WHERE trip_id = ? ORDER BY version DESC LIMIT 20)")->execute([$id, $id]);
    } else {
        // New trip — auto-assign number
        $number = $input['number'] ?? null;
        if (!$number) {
            $maxNum = $db->query("SELECT COALESCE(MAX(number), 0) FROM trips")->fetchColumn();
            $number = $maxNum + 1;
        }

        $stmt = $db->prepare("INSERT INTO trips (id, number, name, route, purpose, cargo, state, version, saved_at, saved_by) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $stmt->execute([$id, $number, substr($input['name'], 0, 100), $route, $purpose, $cargo, $stateJson, $now, $userEmail]);
    }

    echo json_encode(['id' => $id, 'number' => $number, 'name' => substr($input['name'], 0, 100)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
