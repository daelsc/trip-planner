<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['authed'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '#') === 0) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}
$FB_USER = getenv('FB_USER') ?: '';
$FB_PASS = getenv('FB_PASS') ?: '';
$FB_BASE = 'https://flightbridge.com';

if (!$FB_USER || !$FB_PASS) {
    http_response_code(500);
    echo json_encode(['error' => 'FlightBridge credentials not configured']);
    exit;
}

require_once __DIR__ . '/db.php';
$db = getDb();

// Get the local trip ID
$localTripId = $_GET['tripId'] ?? '';
if (!$localTripId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tripId parameter']);
    exit;
}

// Look up the FlightBridge trip ID
$stmt = $db->prepare('SELECT flightbridge_trip_id FROM trips WHERE id = ?');
$stmt->execute([$localTripId]);
$fbTripId = $stmt->fetchColumn();
if (!$fbTripId) {
    http_response_code(404);
    echo json_encode(['error' => 'Trip not pushed to FlightBridge yet']);
    exit;
}

// --- HTTP helper ---
$cookieJar = [];

function fbRequest($method, $url, $body = null) {
    global $cookieJar;
    $cookieHeader = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookieJar), $cookieJar));
    $headers = "Cookie: $cookieHeader\r\nUser-Agent: FlightPlanner/1.0\r\n";
    if ($method === 'POST') {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $headers .= "Content-Length: " . strlen($body ?? '') . "\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $rh = $http_response_header ?? [];
    $location = null;
    foreach ($rh as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $p = explode(';', trim(substr($h, 11)))[0];
            [$n, $v] = explode('=', $p, 2);
            $cookieJar[trim($n)] = trim($v);
        }
        if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
    }
    $status = 0;
    if (!empty($rh[0])) { preg_match('/(\d{3})/', $rh[0], $m); $status = (int)($m[1] ?? 0); }
    return ['status' => $status, 'body' => $resp, 'location' => $location];
}

// --- Login (two-step flow) ---
fbRequest('GET', "$FB_BASE/Account/LogIn");
$r = fbRequest('POST', "$FB_BASE/Account/LogIn", http_build_query([
    'UserName' => $FB_USER, 'ReturnUrl' => '',
]));
if ($r['status'] !== 302 || $r['location'] !== '/Account/LogInPassword') {
    http_response_code(502);
    echo json_encode(['error' => 'FlightBridge email step failed']);
    exit;
}
fbRequest('GET', "$FB_BASE/Account/LogInPassword");
fbRequest('POST', "$FB_BASE/Account/LogInPassword", http_build_query([
    'UserName' => $FB_USER, 'ReturnUrl' => '',
    'Password' => $FB_PASS, 'RememberMe' => 'true',
]));
if (empty($cookieJar['.ASPXAUTH'])) {
    http_response_code(502);
    echo json_encode(['error' => 'FlightBridge login failed — check credentials']);
    exit;
}

// --- Load the trip to get leg IDs ---
$r = fbRequest('GET', "$FB_BASE/ROTrip/Trip?tripId=$fbTripId");
if ($r['status'] !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to load FlightBridge trip']);
    exit;
}

// Parse SelectProvider links to get leg→airport mappings
preg_match_all('/SelectProvider\?([^"]+)/', $r['body'], $linkMatches);
$legAirports = []; // legId => [arrival => airportId, departure => airportId, legNumber => guid]
foreach ($linkMatches[1] as $qs) {
    parse_str($qs, $params);
    $legId = $params['legId'] ?? '';
    $airportId = $params['airportId'] ?? '';
    $arrDep = strtolower($params['arrivalOrDeparture'] ?? '');
    $legNumber = $params['legNumber'] ?? '';
    $providerType = $params['providerType'] ?? '';
    if (!$legId || !$airportId || $providerType !== 'Ground') continue;
    if (!isset($legAirports[$legId])) $legAirports[$legId] = ['legNumber' => $legNumber];
    $legAirports[$legId][$arrDep] = $airportId;
}

// --- Query ground transport providers for each leg's arrival ---
$services = [];
foreach ($legAirports as $legId => $info) {
    $arrAirportId = $info['arrival'] ?? null;
    if (!$arrAirportId) continue;
    $legNumber = $info['legNumber'] ?? '';

    $providerUrl = "$FB_BASE/DirectoryListing/SelectProvider?" . http_build_query([
        'legId' => $legId,
        'legNumber' => $legNumber,
        'airportId' => $arrAirportId,
        'tripId' => $fbTripId,
        'arrivalOrDeparture' => 'Arrival',
        'providerType' => 'Ground',
        'BackToTripId' => $fbTripId,
        'BackToLegNumber' => $legNumber,
    ]);

    $r = fbRequest('GET', $providerUrl);
    if ($r['status'] !== 200) continue;

    // Parse provider forms
    $providers = [];
    $forms = explode('action="/Order/GroundOrderAddEdit"', $r['body']);
    foreach (array_slice($forms, 1) as $form) {
        $fields = [];
        preg_match_all('/name="(\w+)"\s*value="([^"]*)"/', $form, $fm);
        for ($j = 0; $j < count($fm[1]); $j++) {
            $fields[$fm[1][$j]] = html_entity_decode(urldecode($fm[2][$j]));
        }

        $name = $fields['OrderProviderName'] ?? '';
        // Also try to get display name from <strong> tag
        if (preg_match('/<strong>([^<]+)<\/strong>/', $form, $strongMatch)) {
            $name = html_entity_decode(trim($strongMatch[1]));
        }

        if (!$name) continue;
        $providers[] = [
            'name' => $name,
            'phone' => $fields['OrderProviderPhone'] ?? '',
            'email' => $fields['OrderProviderEmail'] ?? '',
            'registered' => ($fields['ProviderRegistrationStatus'] ?? '') === 'Registered',
            'companyId' => $fields['ProviderCompanyId'] ?? '',
            'csaId' => $fields['ProviderCsaId'] ?? '',
        ];
    }

    $services[] = [
        'legId' => $legId,
        'airportId' => $arrAirportId,
        'arrivalOrDeparture' => 'Arrival',
        'providers' => $providers,
    ];
}

echo json_encode([
    'success' => true,
    'flightbridgeTripId' => $fbTripId,
    'groundServices' => $services,
]);
