<?php
// Shared configuration and helpers of mental-state-logger.

const DEVICES = [            // DEVICE_ID => label; the status image is devices/status-image-DEVICE_ID.png
    'epocx1' => 'EPOC X #1',
    'epocx2' => 'EPOC X #2',
    'muse1'  => 'Muse headband',
];
const DATA_DIR = __DIR__ . '/data';          // not web-readable (see data/.htaccess)
const USERS_FILE = DATA_DIR . '/users.json';
const EVENTS_FILE = DATA_DIR . '/events.csv';
const FEED_KEY_FILE = DATA_DIR . '/feed-key.txt';  // optional shared secret for feed-status-image.php

// Metadata stored for each user (all optional). name => [type, options]; labels: 'fields' / 'options' in languages/*.php
const USER_FIELDS = [
    'gender'          => ['select', ['', 'female', 'male', 'non-binary', 'other', 'prefer not to say']],
    'age'             => ['number', null],
    'handedness'      => ['select', ['', 'right', 'left', 'ambidextrous']],
    'mother_tongue'   => ['text', null],
    'other_languages' => ['text', null],
    'education'       => ['select', ['', 'primary', 'secondary', 'bachelor', 'master', 'doctorate']],
    'field_of_study'  => ['text', null],
    'vision'          => ['select', ['', 'normal', 'corrected (glasses)', 'corrected (contact lenses)', 'impaired']],
    'hearing'         => ['select', ['', 'normal', 'impaired', 'hearing aid']],
    'neuro_conditions'=> ['text', null],
    'medication'      => ['text', null],
    'hair_type'       => ['select', ['', 'none / very short', 'short', 'medium', 'long', 'thick / curly', 'braids / dreadlocks']],
    'head_circumference' => ['number', null],
    'musical_training'=> ['number', null],
    'meditation'      => ['select', ['', 'never', 'occasionally', 'regularly']],
    'usual_sleep'     => ['number', null],
    'sleep_last_night'=> ['number', null],
    'caffeine_today'  => ['text', null],
    'nicotine'        => ['select', ['', 'no', 'occasionally', 'daily']],
    'alcohol_24h'     => ['select', ['', 'no', 'a little', 'more']],
    'notes'           => ['textarea', null],
];

const LANG_DIR = __DIR__ . '/languages';     // <code>.php returns an array of UI strings; en.php is the default
const STALE_AFTER_S = 3;                     // status image older than this (feeder silent) -> "no connection"

function start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    // interface language: ?lang=xx (from the language menu) is remembered in the session and a cookie
    $lang = $_GET['lang'] ?? null;
    if (is_string($lang) && preg_match('/^[a-z]{2,3}$/', $lang) && is_file(LANG_DIR . "/$lang.php")) {
        $_SESSION['lang'] = $lang;
        setcookie('msl_lang', $lang, time() + 365 * 86400);
    } elseif (!isset($_SESSION['lang']) && preg_match('/^[a-z]{2,3}$/', $_COOKIE['msl_lang'] ?? '')
              && is_file(LANG_DIR . '/' . $_COOKIE['msl_lang'] . '.php')) {
        $_SESSION['lang'] = $_COOKIE['msl_lang'];
    }
}

function ui_lang(): string {
    return $_SESSION['lang'] ?? 'en';
}

// UI strings of the current language, English as the fallback (nested arrays are merged per key).
function strings(): array {
    static $L = null;
    if ($L === null) {
        $L = require LANG_DIR . '/en.php';
        if (ui_lang() !== 'en') {
            $T = require LANG_DIR . '/' . ui_lang() . '.php';
            foreach ($T as $k => $v) {
                $L[$k] = is_array($v) && is_array($L[$k] ?? null) ? array_replace($L[$k], $v) : $v;
            }
        }
    }
    return $L;
}

function t(string $key, ?string $sub = null): string {
    $v = strings()[$key] ?? $key;
    if ($sub !== null) {
        $v = is_array($v) ? ($v[$sub] ?? $sub) : $sub;
    }
    return (string)$v;
}

// English label of a button: this is what gets logged, whatever the interface language.
function en(string $key, string $sub): string {
    static $E = null;
    if ($E === null) {
        $E = require LANG_DIR . '/en.php';
    }
    return (string)($E[$key][$sub] ?? $sub);
}

function available_languages(): array {
    $out = [];
    foreach (glob(LANG_DIR . '/*.php') as $f) {
        $out[basename($f, '.php')] = (require $f)['lang_name'] ?? basename($f, '.php');
    }
    return $out;
}

// mbstring may be missing on the server: count / cut UTF-8 characters with PCRE instead.
function utf8_len(string $s): int {
    return preg_match_all('/./su', $s);
}

function utf8_cut(string $s, int $n): string {
    preg_match('/^.{0,' . $n . '}/su', $s, $m);
    return $m[0] ?? '';
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Run $fn(array $users): ?array under an exclusive lock on users.json; if $fn returns an array, it is saved
// (atomically: temp file + rename). Returns the (possibly updated) users. Readers use the same lock (shared).
function with_users(?callable $fn = null): array {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0770, true);
    }
    $lock = fopen(USERS_FILE . '.lock', 'c');
    flock($lock, $fn ? LOCK_EX : LOCK_SH);
    $users = is_file(USERS_FILE) ? (json_decode(file_get_contents(USERS_FILE), true) ?: []) : [];
    if ($fn) {
        $new = $fn($users);
        if (is_array($new)) {
            $tmp = USERS_FILE . '.tmp' . getmypid();
            file_put_contents($tmp, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            rename($tmp, USERS_FILE);
            $users = $new;
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $users;
}

// Append one row to data/events.csv (exclusive lock, header on first use).
function log_event(string $event, string $detail = '', string $page = '', string $client_ts = ''): void {
    $t = microtime(true);
    $row = [
        sprintf('%d', (int)round($t * 1000)),                         // server time, ms since epoch
        gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000)),
        $client_ts,                                                    // browser time, ms since epoch
        session_id(),
        $_SESSION['device'] ?? '',
        $_SESSION['user'] ?? '',
        $page,
        $event,
        $detail,
    ];
    $new = !is_file(EVENTS_FILE);
    $fh = fopen(EVENTS_FILE, 'a');
    flock($fh, LOCK_EX);
    if ($new) {
        fputcsv($fh, ['server_ms', 'server_iso', 'client_ms', 'session', 'device', 'user', 'page', 'event', 'detail']);
    }
    fputcsv($fh, $row);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Top right: interface language menu and the live status image of the selected device (updated by common.js;
// the overlay with the broken-connection icon is shown when the server or the feeder is not reachable).
function header_right_html(): string {
    $menu = '<select id="ui-lang" name="ui-lang" title="' . h(t('ui_language')) . '">';
    foreach (available_languages() as $code => $name) {
        $menu .= '<option value="' . h($code) . '"' . ($code === ui_lang() ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    $menu .= '</select>';
    $dev = $_SESSION['device'] ?? '';
    $src = isset(DEVICES[$dev]) && is_file(__DIR__ . '/devices/status-image-' . $dev . '.png')
        ? 'devices/status-image-' . $dev . '.png' : 'devices/no-device.png';
    return '<div class="header-right">' . $menu
        . '<div class="status" id="status" data-stale-after="' . STALE_AFTER_S . '">'
        . '<img id="status-image" width="180" height="180" alt="' . h(t('status_alt')) . '" src="' . h($src) . '" data-device="' . h($dev) . '">'
        . '<div class="lost-overlay"><svg viewBox="0 0 64 64" width="72" height="72" aria-hidden="true">'
        . '<g fill="none" stroke="#c62828" stroke-width="5" stroke-linecap="round">'
        . '<path d="M27 37l-8 8a7 7 0 0 1-10-10l8-8"/><path d="M37 27l8-8a7 7 0 0 1 10 10l-8 8"/>'
        . '<path d="M24 22l-4-6M22 26l-7-2M40 42l4 6M42 38l7 2"/></g></svg>'
        . '<span>' . h(t('no_connection')) . '</span></div></div></div>';
}
