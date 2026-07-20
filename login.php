<?php
require_once __DIR__ . '/lib.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (attempt_login($u, $p)) {
        redirect('index.php');
    }
    $error = 'Wrong username or password.';
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<form class="login" method="post">
    <h1><?= e(APP_NAME) ?></h1>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field">
        <label>Username</label>
        <input name="username" autofocus autocomplete="username">
    </div>
    <div class="field">
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password">
    </div>
    <button type="submit">Sign in</button>
</form>
</body></html>
