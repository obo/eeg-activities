<?php
// Feeder endpoint: the recording machine POSTs the current status image (PNG, 180x180) of one device, e.g.
//   curl -s --data-binary @frame.png -H 'Content-Type: image/png' \
//        'https://HOST/PATH/feed-status-image.php?device=epocx1&key=SECRET'
// (key only if data/feed-key.txt exists). The image is stored atomically as devices/status-image-DEVICE.png;
// the pages poll that static file (see status.js), so nothing else has to run on the server.
// One HTTP keep-alive connection can be reused by the feeder for all frames (curl/requests.Session do that).
require __DIR__ . '/lib.php';
$dev = (string)($_GET['device'] ?? '');
if (!isset(DEVICES[$dev])) {
    http_response_code(404);
    exit("unknown device\n");
}
if (is_file(FEED_KEY_FILE) && !hash_equals(trim(file_get_contents(FEED_KEY_FILE)), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("bad key\n");
}
$png = file_get_contents('php://input');
if (strlen($png) < 8 || strlen($png) > 2000000 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    http_response_code(400);
    exit("expecting a PNG body\n");
}
$target = __DIR__ . "/devices/status-image-$dev.png";
$tmp = "$target.tmp" . getmypid();
file_put_contents($tmp, $png);
rename($tmp, $target);  // atomic: readers never see a half-written image
http_response_code(204);
