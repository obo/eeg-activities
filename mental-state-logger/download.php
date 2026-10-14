<?php
// Ships the recorded data (data/events.csv + data/users.json) as one zip: download.php?key=...
// or one of them as is (no php zip extension needed): download.php?key=...&file=events.csv
// users.json holds plain-text passwords, so this is closed unless data/download-key.txt exists and matches.
require __DIR__ . '/lib.php';

const DOWNLOAD_KEY_FILE = DATA_DIR . '/download-key.txt';

$expected = is_file(DOWNLOAD_KEY_FILE) ? trim((string)file_get_contents(DOWNLOAD_KEY_FILE)) : '';
if ($expected === '') {
    http_response_code(403);
    exit("download disabled: create data/download-key.txt\n");
}
if (!hash_equals($expected, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("bad key\n");
}

if (isset($_GET['file'])) {
    $files = ['events.csv' => [EVENTS_FILE, 'text/csv'], 'users.json' => [USERS_FILE, 'application/json']];
    [$file, $type] = $files[(string)$_GET['file']] ?? [null, null];
    if ($file === null) {
        http_response_code(400);
        exit("file must be one of: " . implode(', ', array_keys($files)) . "\n");
    }
    if (!is_file($file)) {
        http_response_code(404);
        exit("no data recorded yet\n");
    }
    header('Content-Type: ' . $type . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    exit;

}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit("php zip extension missing\n");
}

$tmp = tempnam(sys_get_temp_dir(), 'msl');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit("cannot create zip\n");
}
foreach ([EVENTS_FILE, USERS_FILE] as $file) {
    if (is_file($file)) {
        $zip->addFile($file, basename($file));
    }
}
if ($zip->numFiles === 0) {
    $zip->close();
    unlink($tmp);
    http_response_code(404);
    exit("no data recorded yet\n");
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="mental-state-logger-' . gmdate('Ymd-His') . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
