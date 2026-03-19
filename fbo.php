<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/fbo-lib.php';

$GOOGLE_PLACES_KEY = 'AIzaSyARWRAq_K1p3jVwTDCG17TBKDIHKrgKbHo';

$icao = strtoupper(trim($_GET['icao'] ?? ''));
if (!preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ICAO code']);
    exit;
}

// Cache directory
$cacheDir = __DIR__ . '/fbo_cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$cacheFile = "$cacheDir/$icao.json";
$cacheMaxAge = 86400 * 7; // 7 days

// Return cached if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

// Load airport names for better search queries
$airportName = '';
$apFile = __DIR__ . '/airports.json';
if (file_exists($apFile)) {
    $airports = json_decode(file_get_contents($apFile), true);
    if ($airports) {
        foreach ($airports as $ap) {
            if (($ap['icao'] ?? '') === $icao) {
                $airportName = $ap['name'] ?? '';
                break;
            }
        }
    }
}

// Fetch from AirNav
$url = "https://www.airnav.com/airport/$icao";
$ctx = stream_context_create(['http' => [
    'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36\r\n",
    'timeout' => 15,
    'ignore_errors' => true,
]]);

$html = @file_get_contents($url, false, $ctx);
$fbos = ($html !== false) ? parseFbos($html) : [];

// If AirNav returned no FBOs (common for international airports), try FlightBridge
if (empty($fbos)) {
    $fbFbos = fetchFlightBridgeFbos($icao);
    if (!empty($fbFbos)) $fbos = $fbFbos;
}

// Look up addresses via Google Places for AirNav results
foreach ($fbos as &$fbo) {
    if (!empty($fbo['source']) && $fbo['source'] === 'flightbridge') continue; // FB results don't need Places lookup
    $query = $fbo['name'] . ' ' . ($airportName ?: $icao);
    $address = placesLookup($query, $GOOGLE_PLACES_KEY);
    if ($address) $fbo['address'] = $address;
}
unset($fbo);

$result = json_encode(['icao' => $icao, 'fbos' => $fbos]);
file_put_contents($cacheFile, $result);
echo $result;

function placesLookup($query, $key) {
    $payload = json_encode(['textQuery' => $query]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n" .
                    "X-Goog-Api-Key: $key\r\n" .
                    "X-Goog-FieldMask: places.formattedAddress\r\n",
        'content' => $payload,
        'timeout' => 10,
    ]]);
    $resp = @file_get_contents('https://places.googleapis.com/v1/places:searchText', false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    $addr = $data['places'][0]['formattedAddress'] ?? null;
    if ($addr) {
        // Strip zip code and country: "123 Main St, City, ST 12345, USA" → "123 Main St, City, ST"
        $addr = cleanAddress($addr);
    }
    return $addr;
}

// parseFbos and cleanAddress are in fbo-lib.php

function fetchFlightBridgeFbos($icao) {
    // Load credentials
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) return [];
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '#') === 0) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    $user = $env['FB_USER'] ?? '';
    $pass = $env['FB_PASS'] ?? '';
    if (!$user || !$pass) return [];

    $base = 'https://flightbridge.com';
    $cookies = [];

    $doReq = function($method, $url, $body = null) use (&$cookies) {
        $cookieStr = implode('; ', array_map(fn($k,$v) => "$k=$v", array_keys($cookies), $cookies));
        $headers = "Cookie: $cookieStr\r\nUser-Agent: FlightPlanner/1.0\r\n";
        if ($body !== null) {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $headers .= "Content-Length: " . strlen($body) . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'content' => $body,
            'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $p = explode(';', trim(substr($h, 11)))[0];
                [$n, $v] = explode('=', $p, 2);
                $cookies[trim($n)] = trim($v);
            }
        }
        return $resp;
    };

    // Login (two-step flow)
    $doReq('GET', "$base/Account/LogIn");
    $r = $doReq('POST', "$base/Account/LogIn", http_build_query([
        'UserName' => $user, 'ReturnUrl' => '',
    ]));
    // Follow redirect to password page
    $doReq('GET', "$base/Account/LogInPassword");
    $doReq('POST', "$base/Account/LogInPassword", http_build_query([
        'UserName' => $user, 'ReturnUrl' => '',
        'Password' => $pass, 'RememberMe' => 'true',
    ]));
    if (empty($cookies['.ASPXAUTH'])) return [];

    // Search for the airport
    $searchResp = $doReq('POST', "$base/AirportTypeAhead/Search",
        http_build_query(['searchText' => $icao, 'maxResults' => 1]));
    $airports = json_decode($searchResp, true);
    if (empty($airports[0]['Id'])) return [];
    $airportId = $airports[0]['Id'];

    // Get FBOs
    $fboResp = $doReq('POST', "$base/FlightBridgeTrip/GetJsonFbosForAirport",
        http_build_query(['airportId' => $airportId]));
    $fbFbos = json_decode($fboResp, true);
    if (empty($fbFbos)) return [];

    $result = [];
    foreach ($fbFbos as $f) {
        $fbo = ['name' => $f['Display'], 'source' => 'flightbridge'];
        if (!empty($f['IsRegistered'])) $fbo['registered'] = true;
        $result[] = $fbo;
    }
    return $result;
}
