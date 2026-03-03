<?php
header('Content-Type: application/json');

$dataDir = __DIR__ . '/saved_trips';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$method = $_SERVER['REQUEST_METHOD'];

// List all saved trips
if ($method === 'GET' && !isset($_GET['id'])) {
    $trips = [];
    foreach (glob("$dataDir/*.json") as $f) {
        $data = json_decode(file_get_contents($f), true);
        if ($data) {
            $trips[] = [
                'id' => basename($f, '.json'),
                'name' => $data['name'] ?? 'Untitled',
                'route' => $data['route'] ?? '',
                'saved' => $data['saved'] ?? '',
            ];
        }
    }
    usort($trips, fn($a, $b) => strcmp($b['saved'], $a['saved']));
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
    $data = [
        'id' => $id,
        'name' => substr($input['name'], 0, 100),
        'route' => $route,
        'state' => $input['state'],
        'saved' => date('c'),
    ];
    file_put_contents("$dataDir/$id.json", json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['id' => $id, 'name' => $data['name']]);
    exit;
}

// Delete a trip
if ($method === 'DELETE') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
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

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
