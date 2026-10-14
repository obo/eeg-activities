<?php
// Receives one UI event (POST: event, detail, page, client_ms) and appends it to data/events.csv together with
// the server time, the PHP session id, the selected device and the logged-in user.
// event=device additionally stores the selected device in the session.
require __DIR__ . '/lib.php';
start_session();
$event = substr((string)($_POST['event'] ?? ''), 0, 100);
$detail = substr((string)($_POST['detail'] ?? ''), 0, 1000);
if ($event === 'device') {
    $_SESSION['device'] = isset(DEVICES[$detail]) ? $detail : '';
}
session_write_close();  // do not block the other requests of this session
if ($event !== '') {
    log_event($event, $detail, substr((string)($_POST['page'] ?? ''), 0, 50), preg_replace('/\D/', '', (string)($_POST['client_ms'] ?? '')));
}
http_response_code(204);
