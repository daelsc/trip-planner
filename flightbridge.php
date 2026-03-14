<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['authed'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// FlightBridge credentials — from .env file or environment
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
$FB_TAIL = 'N785QS';

if (!$FB_USER || !$FB_PASS) {
    http_response_code(500);
    echo json_encode(['error' => 'FlightBridge credentials not configured. Set FB_USER and FB_PASS environment variables.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['legs']) || !is_array($input['legs'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing legs array']);
    exit;
}

// --- Helper: make HTTP request with cookies ---
$cookieJar = [];

function fbRequest($method, $url, $body = null, $contentType = 'application/x-www-form-urlencoded') {
    global $cookieJar;
    $cookieHeader = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookieJar), $cookieJar));
    $headers = "Cookie: $cookieHeader\r\n";
    if ($method === 'POST') {
        $headers .= "Content-Type: $contentType\r\n";
        $headers .= "Content-Length: " . strlen($body ?? '') . "\r\n";
    }
    $headers .= "User-Agent: FlightPlanner/1.0\r\n";

    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
        'follow_location' => 0, // don't follow redirects automatically
    ]]);

    $resp = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? [];

    // Parse status code
    $statusCode = 500;
    if (!empty($responseHeaders[0])) {
        preg_match('/(\d{3})/', $responseHeaders[0], $m);
        $statusCode = (int)($m[1] ?? 500);
    }

    // Parse Set-Cookie headers
    foreach ($responseHeaders as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $cookiePart = trim(substr($h, 11));
            $cookiePart = explode(';', $cookiePart)[0];
            [$name, $val] = explode('=', $cookiePart, 2);
            $cookieJar[trim($name)] = trim($val);
        }
    }

    // Parse Location header
    $location = null;
    foreach ($responseHeaders as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }

    return ['status' => $statusCode, 'body' => $resp, 'location' => $location];
}

// --- Step 1: Login to FlightBridge ---
// Get login page for CSRF token
$loginPage = fbRequest('GET', "$FB_BASE/Account/LogIn");
if ($loginPage['status'] !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to load FlightBridge login page', 'status' => $loginPage['status']]);
    exit;
}

// Extract __RequestVerificationToken from HTML form
preg_match('/name="__RequestVerificationToken"[^>]*value="([^"]+)"/', $loginPage['body'], $csrfMatch);
$csrfToken = $csrfMatch[1] ?? '';
if (!$csrfToken) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not extract CSRF token from FlightBridge login page']);
    exit;
}

// Submit login
$loginBody = http_build_query([
    '__RequestVerificationToken' => $csrfToken,
    'UserName' => $FB_USER,
    'Password' => $FB_PASS,
    'RememberMe' => 'true',
]);
$loginResp = fbRequest('POST', "$FB_BASE/Account/LogOn", $loginBody);
if ($loginResp['status'] !== 302 || empty($cookieJar['.ASPXAUTH'])) {
    http_response_code(502);
    echo json_encode(['error' => 'FlightBridge login failed', 'status' => $loginResp['status']]);
    exit;
}

// --- Step 2: Create or Edit trip ---
// Check if we already have a FlightBridge trip ID for this trip
$existingFbTripId = null;
$localTripId = $input['localTripId'] ?? null;
if ($localTripId) {
    require_once __DIR__ . '/db.php';
    $db = getDb();
    $stmt = $db->prepare('SELECT flightbridge_trip_id FROM trips WHERE id = ?');
    $stmt->execute([$localTripId]);
    $existingFbTripId = $stmt->fetchColumn() ?: null;
}

$isEdit = !empty($existingFbTripId);
if ($isEdit) {
    $createResp = fbRequest('GET', "$FB_BASE/FlightBridgeTrip/EditFlightBridgeTrip?tripId=$existingFbTripId&BackToTripId=$existingFbTripId");
} else {
    $createResp = fbRequest('GET', "$FB_BASE/FlightBridgeTrip/CreateFlightBridgeTrip");
}
if ($createResp['status'] !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to load FlightBridge trip form', 'status' => $createResp['status']]);
    exit;
}

// Extract ALL leg GUIDs from the leg-nav elements
preg_match_all('/leg-nav-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $createResp['body'], $legMatches);
$allLegGuids = array_values(array_unique($legMatches[1] ?? []));
$currentLegGuid = $allLegGuids[0] ?? '';
if (!$currentLegGuid) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not extract leg GUID from FlightBridge trip form']);
    exit;
}

// Extract trip number from edit form if available
$fbTripNumber = '';
if (preg_match('/id="TripNumber"[^>]*value="([^"]*)"/', $createResp['body'], $tnMatch)) {
    $fbTripNumber = $tnMatch[1] ?? '';
}

$results = [];
$tripId = null;
$legs = $input['legs'];

foreach ($legs as $i => $leg) {
    if (empty($leg['departure']) || empty($leg['arrival']) || empty($leg['departureTime'])) {
        $results[] = ['leg' => $i, 'status' => 'skipped', 'reason' => 'missing required fields'];
        continue;
    }

    // Resolve departure airport
    $depSearch = fbRequest('POST', "$FB_BASE/AirportTypeAhead/Search",
        http_build_query(['searchText' => $leg['departure'], 'maxResults' => 1]));
    $depAirports = json_decode($depSearch['body'], true);
    if (empty($depAirports[0])) {
        $results[] = ['leg' => $i, 'status' => 'error', 'reason' => "Airport not found: {$leg['departure']}"];
        continue;
    }
    $depAirportId = $depAirports[0]['Id'];
    $depAirportName = $depAirports[0]['Text'];

    // Resolve arrival airport
    $arrSearch = fbRequest('POST', "$FB_BASE/AirportTypeAhead/Search",
        http_build_query(['searchText' => $leg['arrival'], 'maxResults' => 1]));
    $arrAirports = json_decode($arrSearch['body'], true);
    if (empty($arrAirports[0])) {
        $results[] = ['leg' => $i, 'status' => 'error', 'reason' => "Airport not found: {$leg['arrival']}"];
        continue;
    }
    $arrAirportId = $arrAirports[0]['Id'];
    $arrAirportName = $arrAirports[0]['Text'];

    // Resolve FBOs (pick first registered match, or first result)
    $depFboId = 0;
    $depFboName = '';
    if (!empty($leg['departureFbo'])) {
        $depFbos = json_decode(fbRequest('POST', "$FB_BASE/FlightBridgeTrip/GetJsonFbosForAirport",
            http_build_query(['airportId' => $depAirportId]))['body'], true) ?: [];
        foreach ($depFbos as $fbo) {
            if (stripos($fbo['Display'], $leg['departureFbo']) !== false) {
                $depFboId = $fbo['CompanyServiceAirportId'];
                $depFboName = $fbo['Display'];
                break;
            }
        }
        // Fallback: first registered FBO
        if (!$depFboId) {
            foreach ($depFbos as $fbo) {
                if ($fbo['IsRegistered']) {
                    $depFboId = $fbo['CompanyServiceAirportId'];
                    $depFboName = $fbo['Display'];
                    break;
                }
            }
        }
        // Fallback: first FBO
        if (!$depFboId && !empty($depFbos)) {
            $depFboId = $depFbos[0]['CompanyServiceAirportId'];
            $depFboName = $depFbos[0]['Display'];
        }
    }

    $arrFboId = 0;
    $arrFboName = '';
    if (!empty($leg['arrivalFbo'])) {
        $arrFbos = json_decode(fbRequest('POST', "$FB_BASE/FlightBridgeTrip/GetJsonFbosForAirport",
            http_build_query(['airportId' => $arrAirportId]))['body'], true) ?: [];
        foreach ($arrFbos as $fbo) {
            if (stripos($fbo['Display'], $leg['arrivalFbo']) !== false) {
                $arrFboId = $fbo['CompanyServiceAirportId'];
                $arrFboName = $fbo['Display'];
                break;
            }
        }
        if (!$arrFboId) {
            foreach ($arrFbos as $fbo) {
                if ($fbo['IsRegistered']) {
                    $arrFboId = $fbo['CompanyServiceAirportId'];
                    $arrFboName = $fbo['Display'];
                    break;
                }
            }
        }
        if (!$arrFboId && !empty($arrFbos)) {
            $arrFboId = $arrFbos[0]['CompanyServiceAirportId'];
            $arrFboName = $arrFbos[0]['Display'];
        }
    }

    // Use pre-formatted local times from frontend
    $depDate = $leg['departureDate'] ?? '';
    $depTime = $leg['departureTime'] ?? '';
    $arrDate = $leg['arrivalDate'] ?? '';
    $arrTime = $leg['arrivalTime'] ?? '';

    // If not the first leg, add a new leg (or use existing leg GUID from edit)
    if ($i > 0) {
        // Check if there's already a leg at this index (editing a multi-leg trip)
        if (isset($allLegGuids[$i])) {
            $currentLegGuid = $allLegGuids[$i];
            // Show the existing leg so we can save to it
            fbRequest('POST', "$FB_BASE/FlightBridgeTrip/ShowLegByNumber",
                http_build_query(['Number' => $currentLegGuid]));
        } else {
            // Add a new leg
            $addLegResp = fbRequest('POST', "$FB_BASE/FlightBridgeTrip/AddLeg");
            // AddLeg returns a partial with hidden input: <input id="Number" name="Number" ... value="GUID">
            if (preg_match('/name="Number"[^>]*value="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/i', $addLegResp['body'], $newLegMatch)) {
                $currentLegGuid = $newLegMatch[1];
            } elseif (preg_match('/leg-nav-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $addLegResp['body'], $newLegMatch)) {
                $currentLegGuid = $newLegMatch[1];
            }
        }
    }

    // Save the leg (also track last saved leg data for submit)
    $lastSavedLeg = [
        'depAirportName' => $depAirportName, 'depAirportId' => $depAirportId,
        'arrAirportName' => $arrAirportName, 'arrAirportId' => $arrAirportId,
        'depFboName' => $depFboName, 'depFboId' => $depFboId,
        'arrFboName' => $arrFboName, 'arrFboId' => $arrFboId,
        'depDate' => $depDate, 'depTime' => $depTime,
        'arrDate' => $arrDate, 'arrTime' => $arrTime,
    ];
    $saveLegBody = http_build_query([
        'Number' => $currentLegGuid,
        'DepartureAirportName' => $depAirportName,
        'DepartureAirportId' => $depAirportId,
        'ArrivalAirportName' => $arrAirportName,
        'ArrivalAirportId' => $arrAirportId,
        'DepartureFboName' => $depFboName,
        'DepartureFboId' => $depFboId,
        'ArrivalFboName' => $arrFboName,
        'ArrivalFboId' => $arrFboId,
        'OtherDepartureFbo' => 'Enter FBO Name...',
        'OtherArrivalFbo' => 'Enter FBO Name...',
        'DepartureDate' => $depDate,
        'DepartureTime' => $depTime,
        'ArrivalDate' => $arrDate,
        'ArrivalTime' => $arrTime,
        'FarPart' => $leg['farPart'] ?? '91',
        'Callsign' => $leg['callsign'] ?? '',
        'PassengerNumber' => '',
        'PassengerNumberId' => '',
        'CrewNumber' => $leg['crewName'] ?? '',
        'CrewNumberId' => '',
        'CrewType' => $leg['crewType'] ?? 'PIC',
        'TripPurposeId' => -1,
    ]);

    $saveResp = fbRequest('POST', "$FB_BASE/FlightBridgeTrip/SaveLeg", $saveLegBody);
    $saveData = json_decode($saveResp['body'], true);

    if (($saveData['Status'] ?? '') === 'Success') {
        $results[] = ['leg' => $i, 'status' => 'saved', 'route' => "{$leg['departure']} → {$leg['arrival']}"];
    } else {
        $results[] = ['leg' => $i, 'status' => 'error', 'reason' => $saveData['Status'] ?? 'Unknown error', 'detail' => $saveResp['body']];
    }
}

// --- Step 3: Submit the trip (must include last leg's data) ---
$sl = $lastSavedLeg ?? [];
$tripNum = $fbTripNumber ?: ($input['tripNumber'] ?? '');
$submitBody = http_build_query([
    'TripName' => $tripNum,
    'TripNumber' => $tripNum,
    'AircraftTailNumber' => $FB_TAIL,
    'LocalZuluType' => 'Local',
    'LastEditedDateTime' => date('n/j/Y g:i:s A'),
    'Number' => $currentLegGuid,
    'DepartureAirportName' => $sl['depAirportName'] ?? '',
    'DepartureAirportId' => $sl['depAirportId'] ?? '',
    'ArrivalAirportName' => $sl['arrAirportName'] ?? '',
    'ArrivalAirportId' => $sl['arrAirportId'] ?? '',
    'DepartureFboId' => $sl['depFboId'] ?? '',
    'DepartureFboName' => $sl['depFboName'] ?? '',
    'OtherDepartureFbo' => 'Enter FBO Name...',
    'ArrivalFboId' => $sl['arrFboId'] ?? '',
    'ArrivalFboName' => $sl['arrFboName'] ?? '',
    'OtherArrivalFbo' => 'Enter FBO Name...',
    'DepartureDate' => $sl['depDate'] ?? '',
    'DepartureTime' => $sl['depTime'] ?? '',
    'ArrivalDate' => $sl['arrDate'] ?? '',
    'ArrivalTime' => $sl['arrTime'] ?? '',
    'FarPart' => '91',
    'Callsign' => '',
    'TripPurposeId' => -1,
    'PassengerNumber' => '',
    'PassengerNumberId' => '',
    'CrewNumber' => '',
    'CrewType' => '',
    'CrewNumberId' => '',
]);

$submitResp = fbRequest('POST', "$FB_BASE/FlightBridgeTrip/SubmitFlightBridgeTrip", $submitBody);

if ($submitResp['status'] === 302 && $submitResp['location']) {
    // Extract trip ID from redirect
    preg_match('/tripToOpen=(\d+)/', $submitResp['location'], $tripMatch);
    $tripId = $tripMatch[1] ?? null;
}

// For edits, if submit succeeded (302) but redirect didn't include tripToOpen, use the existing ID
if (!$tripId && $submitResp['status'] === 302 && $isEdit) {
    $tripId = $existingFbTripId;
}

$success = !empty($tripId);

// If submit failed, capture debug info
$debug = null;
if (!$success) {
    $debug = [
        'isEdit' => $isEdit,
        'existingFbTripId' => $existingFbTripId,
        'submitStatus' => $submitResp['status'],
        'submitLocation' => $submitResp['location'],
        'legGuid' => $currentLegGuid,
        'legCount' => count($legs),
        'savedCount' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'saved')),
    ];
    // Check for validation errors in response body
    if (preg_match_all('/field-validation-error[^>]*>(.*?)<\/span>/s', $submitResp['body'] ?? '', $fieldErrs)) {
        $debug['validationErrors'] = array_filter(array_map('trim', array_map('strip_tags', $fieldErrs[1])));
    }
}

// Save FlightBridge trip ID back to our database
if ($success && $localTripId) {
    if (!isset($db)) { require_once __DIR__ . '/db.php'; $db = getDb(); }
    $stmt = $db->prepare('UPDATE trips SET flightbridge_trip_id = ? WHERE id = ?');
    $stmt->execute([$tripId, $localTripId]);
}

$resp = [
    'success' => $success,
    'updated' => $isEdit,
    'tripId' => $tripId,
    'tripUrl' => $tripId ? "$FB_BASE/FlightCenter/TripsLink?tripToOpen=$tripId" : null,
    'results' => $results,
];
if ($debug) $resp['debug'] = $debug;
if (!$success) $resp['error'] = 'FlightBridge submit failed (status ' . ($submitResp['status'] ?? '?') . ')';
echo json_encode($resp);
