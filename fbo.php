<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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
        $addr = preg_replace('/,?\s*USA$/', '', $addr);
        $addr = preg_replace('/\s+\d{5}(-\d{4})?$/', '', $addr);
    }
    return $addr;
}

function parseFbos($html) {
    // Find FBO section
    $bizPos = strpos($html, '<A name="biz"></A>');
    if ($bizPos === false) return [];

    $section = substr($html, $bizPos, 50000);
    // Find end of FBO section
    foreach ([
        '<H3>Aviation Businesses, Services, and Facilities</H3>',
        '<H3>Would you like to see your business',
        '<A name="links">',
    ] as $end) {
        $endPos = strpos($section, $end);
        if ($endPos !== false) {
            $section = substr($section, 0, $endPos);
            break;
        }
    }

    // Split on FBO row boundaries
    $segments = preg_split('/<TR valign=middle>\s*\n\s*<TD width=240>/', $section);
    array_shift($segments); // skip header

    $fbos = [];
    foreach ($segments as $seg) {
        // Find name area (before contact TD)
        $contactPos = strpos($seg, '<TD nowrap align=left>');
        $nameArea = $contactPos > 0 ? substr($seg, 0, $contactPos) : substr($seg, 0, 1000);

        $name = null;

        // 1) IMG alt text (logo)
        if (preg_match_all('/<IMG[^>]+alt="([^"]*)"[^>]*>/', $nameArea, $imgMatches, PREG_SET_ORDER)) {
            foreach ($imgMatches as $im) {
                $alt = trim($im[1]);
                if (!$alt || strlen($alt) <= 1) continue;
                if (strpos($im[0], '1dot.gif') !== false || strpos($im[0], 'wing.gif') !== false || strpos($im[0], 'tagline') !== false) continue;
                $name = $alt;
                break;
            }
        }

        // 2) Bold link
        if (!$name && preg_match('/<B><A[^>]*>([^<]+)<\/A><\/B>/', $nameArea, $bm)) {
            $name = trim($bm[1]);
        }

        // 3) Plain link
        if (!$name && preg_match_all('/<A[^>]*>([^<]+)<\/A>/', $nameArea, $lm, PREG_SET_ORDER)) {
            foreach ($lm as $link) {
                $candidate = trim($link[1]);
                if ($candidate && !in_array(strtolower($candidate), ['web site', 'email', ''])) {
                    $name = $candidate;
                    break;
                }
            }
        }

        if (!$name) continue;

        // Phone
        $phone = null;
        if (preg_match('/<TD nowrap align=left><FONT size="-1">(.*?)<\/FONT><\/TD>/s', $seg, $cm)) {
            if (preg_match('/\d{3}-\d{3}-\d{4}|\(\d{3}\)\s*\d{3}-\d{4}/', $cm[1], $pm)) {
                $phone = $pm[0];
            }
        }

        // Fuel
        $fuel = null;
        if (preg_match('/<TD width="94">(.*?)<\/TD>/s', $seg, $fm)) {
            if (preg_match_all('/100LL|Jet[- ]?A|MOGAS|SAF|UL94|100VLL|Swift UL94|UL91/i', $fm[1], $fuels)) {
                $seen = [];
                $unique = [];
                foreach ($fuels[0] as $ft) {
                    $ft = trim($ft);
                    if (!in_array(strtolower($ft), $seen)) {
                        $seen[] = strtolower($ft);
                        $unique[] = $ft;
                    }
                }
                $fuel = implode(' ', $unique);
            }
        }

        $fbo = ['name' => $name];
        if ($phone) $fbo['phone'] = $phone;
        if ($fuel) $fbo['fuel'] = $fuel;
        $fbos[] = $fbo;
    }

    return $fbos;
}

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

    // Login
    $loginPage = $doReq('GET', "$base/Account/LogIn");
    if (!$loginPage) return [];
    preg_match('/name="__RequestVerificationToken"[^>]*value="([^"]+)"/', $loginPage, $cm);
    if (empty($cm[1])) return [];
    $doReq('POST', "$base/Account/LogOn", http_build_query([
        '__RequestVerificationToken' => $cm[1],
        'UserName' => $user, 'Password' => $pass, 'RememberMe' => 'true',
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
