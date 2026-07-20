<?php
/**
 * setup.php — one-time, browser-based password hash generator.
 *
 * No SSH required. Open this page, type the password you want, and it prints the
 * exact line to paste into config.php (edit config.php in Plesk File Manager).
 *
 * SECURITY: delete this file once you've set your password. It only generates a
 * hash and never stores anything, but there's no reason to leave it online.
 */
require_once __DIR__ . '/config.php';

$hash = '';
$pw = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = $_POST['pw'] ?? '';
    if ($pw !== '') {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
    }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup &middot; <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<main style="max-width:640px">
    <h1>One-time setup</h1>
    <p class="sub">Generate your login password hash — no SSH or command line needed.</p>

    <div class="flash" style="background:rgba(242,180,90,.12);border-color:var(--warn);color:var(--warn)">
        Delete <strong>setup.php</strong> after you're done. Serve this over HTTPS.
    </div>

    <form method="post" class="row">
        <div class="field" style="flex:1;min-width:240px">
            <label>Choose a strong password</label>
            <input type="text" name="pw" value="<?= h($pw) ?>" placeholder="type the password you want to log in with" autofocus>
        </div>
        <button type="submit">Generate hash</button>
    </form>

    <?php if ($hash): ?>
        <p><strong>1.</strong> Open <code>config.php</code> in Plesk File Manager and replace the
           <code>APP_PASSWORD_HASH</code> line with this exact line:</p>
        <div class="item" style="align-items:flex-start">
            <div class="grow"><code style="white-space:pre-wrap;word-break:break-all">define('APP_PASSWORD_HASH', '<?= h($hash) ?>');</code></div>
            <button class="ghost mini" type="button"
                data-copy="define('APP_PASSWORD_HASH', '<?= h($hash) ?>');">Copy</button>
        </div>
        <p><strong>2.</strong> Set <code>APP_USERNAME</code> in the same file if you want something other than <code>admin</code>.</p>
        <p><strong>3.</strong> Save the file, then <strong>delete setup.php</strong>. Now sign in at
           <a href="login.php">login.php</a>.</p>
    <?php endif; ?>
</main>
<script>
document.addEventListener('click', function(ev){
    var b = ev.target.closest('[data-copy]'); if(!b) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function(){
        var t=b.textContent; b.textContent='Copied!'; setTimeout(function(){b.textContent=t;},1200);
    });
});
</script>
</body></html>
