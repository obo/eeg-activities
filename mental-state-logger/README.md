# mental-state-logger

Small PHP + JavaScript web page for people wearing an EEG device (2x EMOTIV EPOC X, 1x Muse) to log their
current mental state during a lecture. No database, no PHP extensions beyond the standard ones.

- `index.php` – Device dropdown, User button, 16 toggle buttons for lecture states (double click = "was happening
  in the recent past", logged as event `recent`, the button stays off), modifier rows (language incl.
  Custom, fitness). Grey = off, blue = on. Toggle states survive a reload within the tab.
- `user.php` – choose a known user (password) or create a new one; then optional metadata (gender, age,
  handedness, languages, vision, hearing, conditions, medication, sleep, caffeine, hair type, ...). Save -> index.php.
  Stored in `data/users.json` (plain text, passwords unencrypted by design) under `flock`, written atomically.
- `event-logger.php` – every interaction (load/reload/leave, clicks, toggles ON/OFF, each key typed incl. the key,
  select changes, device choice, login/logout/save) is appended to `data/events.csv`:
  `server_ms, server_iso (UTC), client_ms, session (PHP session id), device, user, page, event, detail`.
- Live device status (180x180, top right on every page):
  - the recording machine POSTs PNG frames to `feed-status-image.php?device=ID[&key=...]`, which writes
    `devices/status-image-ID.png` atomically (temp file + rename);
  - pages poll that static file every 200 ms (5 fps) with `fetch(..., {cache: 'no-cache'})`: the browser reuses
    one keep-alive HTTP connection and revalidates with ETag, so an unchanged frame is a cheap 304 and the image is
    only replaced when the ETag changes. No PHP runs per frame on the reading side.
  - feeder example: `curl -s --data-binary @frame.png -H 'Content-Type: image/png' 'https://HOST/PATH/feed-status-image.php?device=epocx1'`
  - optional shared secret: put it in `data/feed-key.txt` and pass `&key=...`.
- `download.php?key=...` – ships `data/events.csv` + `data/users.json` as one zip. users.json holds plain-text
  passwords, so it is disabled (403) unless `data/download-key.txt` exists; the `key` parameter must match it.
- `make-mock-images.py` – mock frames for the 3 devices (drawn with eeg-activities/eegact/eegact-headmap).

Devices and user metadata fields are configured at the top of `lib.php`.

Interface language: menu top right (left of the status image) -> `?lang=xx`, remembered in the session and a
cookie. Strings are in `languages/<code>.php` (PHP files returning an array; `en.php` is the default and the
fallback, `cs.php` Czech); a new language = a new file with the same keys. Logged button names are always the
English labels. The status image is overlaid with a broken-connection icon when the server is unreachable, the
image is missing, or the feeder has not sent a frame for `STALE_AFTER_S` (3 s; judged from the server's
`Date` vs. `Last-Modified` headers).
Setup: the web server user needs write access to `data/` and `devices/` (here: setfacl u:www-data:rwx);
`data/.htaccess` denies web access to users.json and events.csv (Apache; for nginx add an equivalent rule).
