<?php
// Main page: device + user in the top row, the live device status image top right, toggle buttons for the
// current mental state (lecture setting) and modifier rows (language, fitness). Every interaction is logged
// via event-logger.php (common.js); toggle states survive reloads within the browser tab (sessionStorage).
require __DIR__ . '/lib.php';
start_session();
$device = $_SESSION['device'] ?? '';
$user = $_SESSION['user'] ?? '';
session_write_close();

const STATES = [
    'Listening', 'Watching slides / board', 'Reading', 'Writing notes', 'Focused thinking',
    'Problem solving', 'Memorizing', 'Asking / answering', 'Discussing with others', 'Interested',
    'Confused', 'Bored', 'Daydreaming', 'Distracted (phone, laptop)', 'Taking a break', 'Resting',
];
const LANGUAGES = ['Mother tongue', 'English', 'Czech'];
const FITNESS = ['Fit', 'Tired', 'Sleepy', 'Hungry', 'Stressed', 'Relaxed', 'Headache'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mental state logger</title>
<link rel="stylesheet" href="style.css">
</head>
<body data-page="index.php">
<header>
  <div class="controls">
    <label>Device
      <select id="device" name="device">
        <option value="">– choose –</option>
        <?php foreach (DEVICES as $id => $label): ?>
          <option value="<?= h($id) ?>"<?= $id === $device ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <a class="button" id="user-button" data-name="User" href="user.php">User: <?= $user === '' ? '<i>not logged in</i>' : h($user) ?></a>
  </div>
  <?= status_image_html() ?>
</header>

<main>
  <section class="states">
    <?php foreach (STATES as $s): ?>
      <button class="toggle state" data-group="state" data-name="<?= h($s) ?>"><?= h($s) ?></button>
    <?php endforeach; ?>
  </section>

  <section class="modifiers">
    <div class="row">
      <span class="row-label">Language</span>
      <?php foreach (LANGUAGES as $s): ?>
        <button class="toggle" data-group="language" data-name="<?= h($s) ?>"><?= h($s) ?></button>
      <?php endforeach; ?>
      <button class="toggle" id="custom-language" data-group="language" data-name="Custom">Custom</button>
    </div>
    <div class="row">
      <span class="row-label">Fitness</span>
      <?php foreach (FITNESS as $s): ?>
        <button class="toggle" data-group="fitness" data-name="<?= h($s) ?>"><?= h($s) ?></button>
      <?php endforeach; ?>
    </div>
  </section>
</main>

<dialog id="custom-dialog">
  <form method="dialog">
    <label>Language <input id="custom-name" name="custom-language-name" autocomplete="off"></label>
    <div class="dialog-buttons">
      <button value="cancel" data-name="Custom language: cancel">Cancel</button>
      <button value="ok" data-name="Custom language: OK">OK</button>
    </div>
  </form>
</dialog>

<script src="common.js"></script>
<script>
'use strict';
const KEY = 'msl-toggles';
const DOUBLE_CLICK_MS = 300;  // a single click on an activity is applied after this delay
const saved = JSON.parse(sessionStorage.getItem(KEY) || '{}');

function nameOf(b) {
  return b.id === 'custom-language' && b.dataset.custom ? `Custom: ${b.dataset.custom}` : b.dataset.name;
}
function setOn(b, on) {
  b.classList.toggle('on', on);
  saved[b.dataset.group + '/' + b.dataset.name] = on ? (b.dataset.custom || true) : undefined;
  sessionStorage.setItem(KEY, JSON.stringify(saved));
}

document.querySelectorAll('button.toggle').forEach((b) => {
  const s = saved[b.dataset.group + '/' + b.dataset.name];
  if (s) {
    if (typeof s === 'string') { b.dataset.custom = s; b.textContent = `Custom: ${s}`; }
    b.classList.add('on');
  }
  b.addEventListener('click', () => {
    if (b.dataset.group === 'state') {  // activities: wait whether this becomes a double click
      if (b.clickTimer) {               // second click: "this activity was happening in the recent past"
        clearTimeout(b.clickTimer);
        b.clickTimer = null;
        if (b.classList.contains('on')) {  // the button stays (or becomes) off
          setOn(b, false);
          MSL.log('toggle', `${b.dataset.group}: ${nameOf(b)} OFF`);
        }
        MSL.log('recent', `${b.dataset.group}: ${nameOf(b)} was happening in the recent past`);
        b.classList.add('flash');
        setTimeout(() => b.classList.remove('flash'), 1000);
        return;
      }
      b.clickTimer = setTimeout(() => { b.clickTimer = null; toggle(b); }, DOUBLE_CLICK_MS);
      return;
    }
    toggle(b);
  });
});

function toggle(b) {
    const on = !b.classList.contains('on');
    if (b.id === 'custom-language' && on) {       // ask for the language whenever Custom is switched on
      const dlg = document.getElementById('custom-dialog');
      const inp = document.getElementById('custom-name');
      inp.value = b.dataset.custom || '';
      dlg.returnValue = '';
      dlg.showModal();
      dlg.addEventListener('close', () => {
        const v = inp.value.trim();
        if (dlg.returnValue === 'ok' && v) {
          b.dataset.custom = v;
          b.textContent = `Custom: ${v}`;
          setOn(b, true);
          MSL.log('toggle', `${b.dataset.group}: ${nameOf(b)} ON`);
        } else {
          MSL.log('toggle', `${b.dataset.group}: Custom cancelled`);
        }
      }, {once: true});
      return;
    }
    setOn(b, on);
    MSL.log('toggle', `${b.dataset.group}: ${nameOf(b)} ${on ? 'ON' : 'OFF'}`);
}

document.getElementById('device').addEventListener('change', (e) => {
  MSL.log('device', e.target.value);   // also stores the device in the PHP session
  MSL.status.setDevice(e.target.value);
});
</script>
</body>
</html>
