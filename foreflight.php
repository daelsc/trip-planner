<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['authed'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$FF_API_KEY = 'DBX4TAvf3+Dn5kHsCEutJOpP5Rd45w50D0sNbYVc/Z8=';
$FF_TAIL = 'N785QS';
$FF_URL = 'https://dispatch.foreflight.com/public/api/schedule/flights';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['flights']) || !is_array($input['flights'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing flights array']);
    exit;
}

// Build ForeFlight payload
$ffFlights = [];
foreach ($input['flights'] as $i => $leg) {
    if (empty($leg['departure']) || empty($leg['destination']) || empty($leg['departureTime'])) continue;
    $ffFlights[] = [
        'departure' => $leg['departure'],
        'destination' => $leg['destination'],
        'aircraftRegistration' => $FF_TAIL,
        'scheduledTimeOfDeparture' => $leg['departureTime'],
        'scheduledTimeOfArrival' => $leg['arrivalTime'] ?? null,
        'flightRule' => 'IFR',
        'externalId' => $leg['externalId'] ?? "trip-leg-$i",
        'tripId' => $leg['tripId'] ?? null,
    ];
}

if (empty($ffFlights)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid flights']);
    exit;
}

$payload = json_encode(['flights' => $ffFlights]);

$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n" .
                "x-api-key: $FF_API_KEY\r\n",
    'content' => $payload,
    'timeout' => 30,
    'ignore_errors' => true,
]]);

$resp = @file_get_contents($FF_URL, false, $ctx);
$statusLine = $http_response_headers[0] ?? '';
preg_match('/(\d{3})/', $statusLine, $m);
$statusCode = (int)($m[1] ?? 500);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach ForeFlight API']);
    exit;
}

http_response_code($statusCode);
echo $resp;
