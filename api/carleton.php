<?php
// Carleton public-schedule lookup. A PHP port of the Node "server.js" parser:
// the website can't call central.carleton.ca directly (browsers block it), so this file asks Carleton
// for the course page, reads the table, and hands back clean section data.
declare(strict_types=1);

const CARLETON_UA = 'Mozilla/5.0 (course-calendar-prototype)';
const CARLETON_NO_RESULTS = '/No courses meet the search criteria/i';

function carleton_urls(): array {
    $c = cfg();
    return [
        $c['carleton_search_url'] ?? 'https://central.carleton.ca/prod/bwysched.p_course_search',
        $c['carleton_term_url'] ?? 'https://central.carleton.ca/prod/bwysched.p_select_term?wsea_code=EXT',
    ];
}

// ---------- 1. The same form Carleton's own site sends ----------
function carleton_body(string $term, string $subj, string $num, string $sessionId): string {
    $pairs = [['wsea_code', 'EXT'], ['term_code', $term], ['session_id', $sessionId], ['ws_numb', '']];
    foreach (['sel_aud', 'sel_subj', 'sel_camp', 'sel_sess', 'sel_attr', 'sel_levl', 'sel_schd', 'sel_insm', 'sel_link',
        'sel_wait', 'sel_day', 'sel_begin_hh', 'sel_begin_mi', 'sel_begin_am_pm', 'sel_end_hh', 'sel_end_mi',
        'sel_end_am_pm', 'sel_instruct', 'sel_special', 'sel_resd', 'sel_breadth'] as $k) {
        $pairs[] = [$k, 'dummy'];
    }
    array_push($pairs,
        ['sel_levl', ''], ['sel_subj', $subj], ['sel_number', $num], ['sel_crn', ''], ['sel_special', 'N'],
        ['sel_sess', ''], ['sel_schd', ''], ['sel_instruct', ''],
        ['sel_begin_hh', '0'], ['sel_begin_mi', '0'], ['sel_begin_am_pm', 'a'],
        ['sel_end_hh', '0'], ['sel_end_mi', '0'], ['sel_end_am_pm', 'a']);
    foreach (['m', 't', 'w', 'r', 'f', 's', 'u'] as $d) {
        $pairs[] = ['sel_day', $d];
    }
    $pairs[] = ['block_button', ''];
    return implode('&', array_map(fn($p) => urlencode($p[0]) . '=' . urlencode($p[1]), $pairs));
}

// Loads Carleton's term page like a browser would (to get its cookies and hidden session_id), then
// posts the search with the same connection. Returns the results page as text.
function carleton_fetch(string $term, string $subj, string $num): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('NO_CURL');
    }
    [$searchUrl, $termUrl] = carleton_urls();
    $ch = curl_init();
    $common = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_COOKIEFILE => '',
        CURLOPT_USERAGENT => CARLETON_UA, CURLOPT_ENCODING => '',
    ];
    curl_setopt_array($ch, $common + [CURLOPT_URL => $termUrl, CURLOPT_HTTPGET => true]);
    $termHtml = curl_exec($ch);
    if ($termHtml === false) {
        $e = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Could not reach Carleton: ' . $e);
    }
    $sessionId = '';
    if (preg_match('/name=["\']?session_id["\']?[^>]*value=["\']?(\d+)/i', (string)$termHtml, $m)
        || preg_match('/value=["\']?(\d+)["\']?[^>]*name=["\']?session_id/i', (string)$termHtml, $m)) {
        $sessionId = $m[1];
    }
    curl_setopt_array($ch, $common + [
        CURLOPT_URL => $searchUrl, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => carleton_body($term, $subj, $num, $sessionId),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://central.carleton.ca',
            'Referer: ' . $termUrl,
        ],
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false) {
        throw new RuntimeException('Could not reach Carleton: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Carleton responded with HTTP ' . $code);
    }
    return (string)$html;
}

// ---------- 2. Turn the HTML into a flat list of text "cells" ----------
const WS = '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

function carleton_tokens(string $html): array {
    $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    $html = preg_replace('~<script[\s\S]*?</script>|<style[\s\S]*?</style>~i', '', $html);
    $html = preg_replace('~</(td|th|tr|table|p|div|li|h\d)>|<br\s*/?>~i', "\n", $html);
    $html = preg_replace('~<[^>]+>~', ' ', $html);
    $html = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], [' ', '&', '<', '>', '"', "'"], $html);
    $out = [];
    foreach (explode("\n", $html) as $line) {
        $line = preg_replace('/[' . WS . ']+/u', ' ', $line);
        $line = preg_replace('/^[' . WS . ']+|[' . WS . ']+$/u', '', (string)$line);
        if ($line !== '' && $line !== null) {
            $out[] = $line;
        }
    }
    return $out;
}

const CARLETON_MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
    'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

function carleton_iso(string $m, string $d, string $y): ?string {
    if (!isset(CARLETON_MONTHS[$m])) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int)$y, CARLETON_MONTHS[$m], (int)$d);
}

// Carleton's table has one row per section, cells in this order:
//   [Status] CRN | Subject+Number | Section | Title | Credits | Schedule | Restricts? | Prereqs? | Instructor
// followed by "Meeting Date: .. Days: .. Time: .." / "Also Register in: .." lines.
// We anchor on the CRN (5 digits) followed by "SYSC 1006"-style text.
function carleton_parse(string $html): array {
    $t = carleton_tokens($html);
    $n = count($t);
    $anchors = [];
    for ($i = 0; $i < $n - 1; $i++) {
        if (preg_match('/^\d{5}\z/', $t[$i]) && preg_match('/^[A-Z]{2,5}\s*\d{3,5}[A-Z]?\z/', $t[$i + 1])) {
            $anchors[] = $i;
        }
    }
    $endAll = -1;
    foreach ($t as $i => $x) {
        if (preg_match('/^DRAFT TIMETABLE/i', $x)) {
            $endAll = $i;
            break;
        }
    }
    $MEET = '/Meeting Date:\s*(.+?)\s*Days:\s*(.*?)\s*Time:\s*(\d{1,2}:\d{2})\s*(?:-|–|to)\s*(\d{1,2}:\d{2})/u';
    $sections = [];
    foreach ($anchors as $k => $a) {
        $end = $k + 1 < count($anchors) ? $anchors[$k + 1] : ($endAll > $a ? $endAll : $n);
        preg_match('/^([A-Z]{2,5})\s*(\d{3,5}[A-Z]?)\z/', $t[$a + 1], $sm);
        $sec = [
            'crn' => $t[$a], 'subject' => $sm[1], 'number' => $sm[2], 'section' => $t[$a + 2] ?? '',
            'title' => $t[$a + 3] ?? '', 'credits' => $t[$a + 4] ?? '', 'type' => $t[$a + 5] ?? '',
            'instructor' => '', 'also' => '', 'meetings' => [],
        ];
        // Instructor = last non-Yes/No cell before the first "Meeting Date"
        $firstMeet = -1;
        for ($i = $a + 1; $i < $end; $i++) {
            if (preg_match('/^Meeting Date:/i', $t[$i])) {
                $firstMeet = $i;
                break;
            }
        }
        $stop = $firstMeet === -1 ? $end : $firstMeet;
        $between = [];
        for ($i = $a + 6; $i < $stop; $i++) {
            if (!preg_match('/^(Yes|No)\z/i', $t[$i])) {
                $between[] = $t[$i];
            }
        }
        $sec['instructor'] = $between ? $between[count($between) - 1] : '';

        for ($i = $a + 6; $i < $end; $i++) {
            if (preg_match('/^Also Register in:/i', $t[$i])) {
                $sec['also'] = preg_replace('/^Also Register in:\s*/i', '', $t[$i]);
            }
            if (preg_match_all($MEET, $t[$i], $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    preg_match_all('/([A-Z][a-z]{2})[a-z]*\s+(\d{1,2}),?\s+(\d{4})/', $m[1], $d, PREG_SET_ORDER);
                    preg_match_all('/(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i', $m[2], $dm, PREG_SET_ORDER);
                    $days = array_map(fn($x) => strtoupper($x[1][0]) . strtolower(substr($x[1], 1, 2)), $dm);
                    $sec['meetings'][] = [
                        'startDate' => isset($d[0]) ? carleton_iso($d[0][1], $d[0][2], $d[0][3]) : null,
                        'endDate' => isset($d[1]) ? carleton_iso($d[1][1], $d[1][2], $d[1][3]) : null,
                        'days' => $days,
                        'start' => str_pad($m[3], 5, '0', STR_PAD_LEFT),
                        'end' => str_pad($m[4], 5, '0', STR_PAD_LEFT),
                    ];
                }
            }
        }
        $sections[] = $sec;
    }
    return $sections;
}

// Looks up one course. Returns ['sections' => [...], 'warning' => string|null].
function carleton_lookup(string $term, string $subj, string $num): array {
    $html = carleton_fetch($term, $subj, $num);
    $sections = array_values(array_filter(carleton_parse($html), fn($s) => !$s['subject'] || $s['subject'] === $subj));
    $warning = null;
    if (!$sections) {
        $warning = preg_match(CARLETON_NO_RESULTS, $html)
            ? 'Carleton says no courses matched that term and course code.'
            : 'Carleton answered, but no sections could be read from the page.';
    }
    return ['sections' => $sections, 'warning' => $warning];
}

function carleton_norm(string $v): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($v));
}
