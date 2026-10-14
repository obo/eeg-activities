<?php
// User subform: choose a known user (password) or create a new one, then fill in / update the optional
// metadata. Everything is stored (unencrypted, by design) in data/users.json under a file lock, so several
// people can use the form at the same time. Save returns to index.php.
require __DIR__ . '/lib.php';
start_session();
$msg = '';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    $name = trim((string)($_POST['username'] ?? ''));
    $users = with_users();
    if (!isset($users[$name])) {
        $msg = 'msg_choose_user';
    } elseif ((string)($users[$name]['password'] ?? '') !== (string)($_POST['password'] ?? '')) {
        $msg = 'msg_wrong_password';
        log_event('login-failed', $name, 'user.php');
    } else {
        $_SESSION['user'] = $name;
        log_event('login', $name, 'user.php');
    }
} elseif ($action === 'create') {
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['new_username'] ?? '')));
    $pw = (string)($_POST['new_password'] ?? '');
    if ($name === '' || utf8_len($name) > 40) {
        $msg = 'msg_user_name';
    } elseif ($pw === '') {
        $msg = 'msg_choose_password';
    } elseif ($pw !== (string)($_POST['new_password2'] ?? '')) {
        $msg = 'msg_passwords_differ';
    } else {
        $created = false;
        with_users(function ($users) use ($name, $pw, &$created) {
            if (isset($users[$name])) {
                return null;   // exists already (maybe created a moment ago by someone else): no change
            }
            $users[$name] = ['password' => $pw, 'created' => date('c'), 'meta' => []];
            $created = true;
            return $users;
        });
        if ($created) {
            $_SESSION['user'] = $name;
            log_event('user-created', $name, 'user.php');
        } else {
            $msg = 'msg_user_exists';
        }
    }
} elseif ($action === 'save' && isset($_SESSION['user'])) {
    $name = $_SESSION['user'];
    $meta = [];
    foreach (USER_FIELDS as $f => $_) {
        $v = trim((string)($_POST[$f] ?? ''));
        if ($v !== '') {
            $meta[$f] = utf8_cut($v, 2000);
        }
    }
    with_users(function ($users) use ($name, $meta) {
        if (!isset($users[$name])) {
            return null;
        }
        $users[$name]['meta'] = $meta;
        $users[$name]['updated'] = date('c');
        return $users;
    });
    log_event('user-saved', json_encode($meta, JSON_UNESCAPED_UNICODE), 'user.php');
    session_write_close();
    header('Location: index.php');
    exit;
} elseif ($action === 'logout') {
    log_event('logout', $_SESSION['user'] ?? '', 'user.php');
    unset($_SESSION['user']);
}

$user = $_SESSION['user'] ?? '';
$users = with_users();
if ($user !== '' && !isset($users[$user])) {   // removed from users.json meanwhile
    unset($_SESSION['user']);
    $user = '';
}
$meta = $user !== '' ? ($users[$user]['meta'] ?? []) : [];
session_write_close();
$names = array_keys($users);
natcasesort($names);
?>
<!DOCTYPE html>
<html lang="<?= h(ui_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('user') . ' – ' . t('title')) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body data-page="user.php">
<header>
  <div class="controls">
    <a class="button" href="index.php" data-name="Back">&larr; <?= h(t('back')) ?></a>
    <span class="title"><?= h(t('user')) ?><?= $user !== '' ? ': <b>' . h($user) . '</b>' : '' ?></span>
  </div>
  <?= header_right_html() ?>
</header>

<main class="subform">
<?php if ($msg !== ''): ?><p class="msg"><?= h(t($msg)) ?></p><?php endif; ?>

<?php if ($user === ''): ?>
  <div class="cols">
    <form method="post" class="box">
      <h2><?= h(t('known_user')) ?></h2>
      <select name="username" size="12" class="userlist" required>
        <?php foreach ($names as $n): ?>
          <option value="<?= h($n) ?>"><?= h($n) ?></option>
        <?php endforeach; ?>
      </select>
      <label><?= h(t('password')) ?> <input type="password" name="password" autocomplete="off"></label>
      <button name="action" value="login" data-name="Log in"><?= h(t('log_in')) ?></button>
    </form>
    <form method="post" class="box">
      <h2><?= h(t('new_user')) ?></h2>
      <label><?= h(t('user_name')) ?> <input name="new_username" maxlength="40" autocomplete="off"></label>
      <label><?= h(t('password')) ?> <input type="password" name="new_password" autocomplete="new-password"></label>
      <label><?= h(t('password_again')) ?> <input type="password" name="new_password2" autocomplete="new-password"></label>
      <p class="hint"><?= h(t('password_hint')) ?></p>
      <button name="action" value="create" data-name="Create user"><?= h(t('create_user')) ?></button>
    </form>
  </div>
<?php else: ?>
  <form method="post" class="box meta">
    <p class="hint"><?= h(t('optional_hint')) ?></p>
    <?php foreach (USER_FIELDS as $f => [$type, $opts]): $v = $meta[$f] ?? ''; ?>
      <label><span><?= h(t('fields', $f)) ?></span>
        <?php if ($type === 'select'): ?>
          <select name="<?= h($f) ?>">
            <?php foreach ($opts as $o): ?>
              <option value="<?= h($o) ?>"<?= $o === $v ? ' selected' : '' ?>><?= h(t('options', $o)) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($type === 'textarea'): ?>
          <textarea name="<?= h($f) ?>" rows="3"><?= h($v) ?></textarea>
        <?php else: ?>
          <input type="<?= $type === 'number' ? 'number' : 'text' ?>" name="<?= h($f) ?>" value="<?= h($v) ?>"<?= $type === 'number' ? ' min="0" step="any"' : '' ?>>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    <div class="dialog-buttons">
      <button name="action" value="logout" data-name="Switch user" formnovalidate><?= h(t('switch_user')) ?></button>
      <button name="action" value="save" data-name="Save" class="primary"><?= h(t('save')) ?></button>
    </div>
  </form>
<?php endif; ?>
</main>
<script src="common.js"></script>
</body>
</html>
