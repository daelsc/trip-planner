<?php
// fbo-lib.php — extracted FBO parsing logic for testability

function parseFbos($html) {
    $bizPos = strpos($html, '<A name="biz"></A>');
    if ($bizPos === false) return [];

    $section = substr($html, $bizPos, 50000);
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

    $segments = preg_split('/<TR valign=middle>\s*\n\s*<TD width=240>/', $section);
    array_shift($segments);

    $fbos = [];
    foreach ($segments as $seg) {
        $contactPos = strpos($seg, '<TD nowrap align=left>');
        $nameArea = $contactPos > 0 ? substr($seg, 0, $contactPos) : substr($seg, 0, 1000);

        $name = null;

        if (preg_match_all('/<IMG[^>]+alt="([^"]*)"[^>]*>/', $nameArea, $imgMatches, PREG_SET_ORDER)) {
            foreach ($imgMatches as $im) {
                $alt = trim($im[1]);
                if (!$alt || strlen($alt) <= 1) continue;
                if (strpos($im[0], '1dot.gif') !== false || strpos($im[0], 'wing.gif') !== false || strpos($im[0], 'tagline') !== false) continue;
                $name = $alt;
                break;
            }
        }

        if (!$name && preg_match('/<B><A[^>]*>([^<]+)<\/A><\/B>/', $nameArea, $bm)) {
            $name = trim($bm[1]);
        }

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

        $phone = null;
        if (preg_match('/<TD nowrap align=left><FONT size="-1">(.*?)<\/FONT><\/TD>/s', $seg, $cm)) {
            if (preg_match('/\d{3}-\d{3}-\d{4}|\(\d{3}\)\s*\d{3}-\d{4}/', $cm[1], $pm)) {
                $phone = $pm[0];
            }
        }

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

function cleanAddress($addr) {
    if (!$addr) return null;
    $addr = preg_replace('/,?\s*USA$/', '', $addr);
    $addr = preg_replace('/\s+\d{5}(-\d{4})?$/', '', $addr);
    return $addr;
}
