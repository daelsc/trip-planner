<?php
// trip-lib.php — extracted trip logic for testability

function tripEndDate($state, $startDate) {
    if (!empty($state['dd'])) {
        $dates = explode(',', $state['dd']);
        for ($i = count($dates) - 1; $i >= 0; $i--) {
            if (!empty($dates[$i])) return $dates[$i];
        }
    }
    return $startDate;
}

function buildRoute($state) {
    if (empty($state['l'])) return '';
    $pairs = explode(',', $state['l']);
    $airports = [];
    foreach ($pairs as $p) {
        $parts = explode('-', $p);
        if (empty($airports)) $airports[] = $parts[0] ?? '';
        $airports[] = $parts[1] ?? '';
    }
    return implode(' → ', $airports);
}
