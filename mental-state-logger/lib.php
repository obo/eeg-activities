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

// Metadata stored for each user (all optional). name => [label, type, options]
const USER_FIELDS = [
    'gender'          => ['Gender', 'select', ['', 'female', 'male', 'non-binary', 'other', 'prefer not to say']],
    'age'             => ['Age (years)', 'number', null],
    'handedness'      => ['Handedness', 'select', ['', 'right', 'left', 'ambidextrous']],
    'mother_tongue'   => ['Mother tongue', 'text', null],
    'other_languages' => ['Other languages (with level)', 'text', null],
    'education'       => ['Highest education', 'select', ['', 'primary', 'secondary', 'bachelor', 'master', 'doctorate']],
    'field_of_study'  => ['Field of study / work', 'text', null],
    'vision'          => ['Vision', 'select', ['', 'normal', 'corrected (glasses)', 'corrected (contact lenses)', 'impaired']],
    'hearing'         => ['Hearing', 'select', ['', 'normal', 'impaired', 'hearing aid']],
    'neuro_conditions'=> ['Neurological / psychiatric conditions (e.g. epilepsy, ADHD, migraine)', 'text', null],
    'medication'      => ['Medication affecting the nervous system', 'text', null],
    'hair_type'       => ['Hair (for electrode contact)', 'select', ['', 'none / very short', 'short', 'medium', 'long', 'thick / curly', 'braids / dreadlocks']],
    'head_circumference' => ['Head circumference (cm)', 'number', null],
    'musical_training'=> ['Musical training (years)', 'number', null],
    'meditation'      => ['Meditation practice', 'select', ['', 'never', 'occasionally', 'regularly']],
    'usual_sleep'     => ['Usual sleep per night (hours)', 'number', null],
    'sleep_last_night'=> ['Sleep last night (hours)', 'number', null],
    'caffeine_today'  => ['Caffeine today (cups, last one when)', 'text', null],
    'nicotine'        => ['Nicotine use', 'select', ['', 'no', 'occasionally', 'daily']],
    'alcohol_24h'     => ['Alcohol in the last 24 h', 'select', ['', 'no', 'a little', 'more']],
    'notes'           => ['Other notes', 'textarea', null],
];

function start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
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

// Top-right live status image of the selected device (updated by status.js).
function status_image_html(): string {
    $dev = $_SESSION['device'] ?? '';
    $src = isset(DEVICES[$dev]) ? 'devices/status-image-' . $dev . '.png' : 'devices/no-device.png';
    return '<img id="status-image" width="180" height="180" alt="device status" src="' . h($src) . '" data-device="' . h($dev) . '">';
}
