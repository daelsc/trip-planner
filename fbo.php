<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

// Fetch from AirNav
$url = "https://www.airnav.com/airport/$icao";
$ctx = stream_context_create(['http' => [
    'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36\r\n",
    'timeout' => 15,
    'ignore_errors' => true,
]]);

$html = @file_get_contents($url, false, $ctx);
if ($html === false) {
    echo json_encode(['icao' => $icao, 'fbos' => []]);
    exit;
}

// Parse FBOs
$fbos = parseFbos($html);

$result = json_encode(['icao' => $icao, 'fbos' => $fbos]);
file_put_contents($cacheFile, $result);
echo $result;

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
