<?php
session_start();
header('Content-Type: application/json');

// Protect write operations
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && empty($_SESSION['authed'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$dataDir = __DIR__ . '/saved_trips';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
$counterFile = "$dataDir/.counter";

function nextTripNumber() {
    global $counterFile;
    $n = file_exists($counterFile) ? (int)file_get_contents($counterFile) : 0;
    $n++;
    file_put_contents($counterFile, $n);
    return $n;
}

// List all saved trips
if ($method === 'GET' && !isset($_GET['id'])) {
    $trips = [];
    foreach (glob("$dataDir/*.json") as $f) {
        $data = json_decode(file_get_contents($f), true);
        if ($data) {
            $tripDate = '';
            if (isset($data['state']['t'])) {
                $tripDate = substr($data['state']['t'], 0, 10); // YYYY-MM-DD
            }
            $trips[] = [
                'id' => basename($f, '.json'),
                'number' => $data['number'] ?? null,
                'name' => $data['name'] ?? 'Untitled',
                'route' => $data['route'] ?? '',
                'date' => $tripDate,
                'saved' => $data['saved'] ?? '',
            ];
        }
    }
    // Sort chronologically by trip date (earliest first), then by number
    usort($trips, function($a, $b) {
        $da = $a['date'] ?: '9999';
        $db = $b['date'] ?: '9999';
        $cmp = strcmp($da, $db);
        return $cmp !== 0 ? $cmp : (($a['number'] ?? 0) - ($b['number'] ?? 0));
    });
    echo json_encode($trips);
    exit;
}

// Load a specific trip
if ($method === 'GET' && isset($_GET['id'])) {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id']);
    $file = "$dataDir/$id.json";
    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Trip not found']);
        exit;
    }
    echo file_get_contents($file);
    exit;
}

// Delete a trip (POST with action=delete, or DELETE method) — must be before save handler
if (($method === 'POST' && ($_GET['action'] ?? '') === 'delete') || $method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? ($_GET['id'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $file = "$dataDir/$id.json";
    if (file_exists($file)) {
        unlink($file);
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
    $route = '';
    if (isset($input['state']['l'])) {
        $pairs = explode(',', $input['state']['l']);
        $airports = [];
        foreach ($pairs as $p) {
            $parts = explode('-', $p);
            if (empty($airports)) $airports[] = $parts[0] ?? '';
            $airports[] = $parts[1] ?? '';
        }
        $route = implode(' → ', $airports);
    }
    // Use client-provided number, or preserve existing, or auto-assign
    $number = $input['number'] ?? null;
    if (!$number) {
        $existingFile = "$dataDir/$id.json";
        if (file_exists($existingFile)) {
            $existing = json_decode(file_get_contents($existingFile), true);
            $number = $existing['number'] ?? null;
        }
    }
    if (!$number) $number = nextTripNumber();

    $data = [
        'id' => $id,
        'number' => $number,
        'name' => substr($input['name'], 0, 100),
        'route' => $route,
        'state' => $input['state'],
        'saved' => date('c'),
    ];
    file_put_contents("$dataDir/$id.json", json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['id' => $id, 'number' => $number, 'name' => $data['name']]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
