<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $body = trim($_POST['body'] ?? '');
        $kind = in_array($_POST['kind'] ?? '', ['note', 'todo', 'link'], true) ? $_POST['kind'] : 'note';
        if ($body !== '') {
            $st = $pdo->prepare("INSERT INTO inbox (body, kind) VALUES (?, ?)");
            $st->execute([$body, $kind]);
            flash('Captured.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare("UPDATE inbox SET done = 1 - done WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM inbox WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Deleted.');
    } elseif ($action === 'clear_done') {
        $pdo->exec("DELETE FROM inbox WHERE done = 1");
        flash('Cleared completed items.');
    }
    redirect('inbox.php');
}

$filter = $_GET['show'] ?? 'open';
$where = $filter === 'all' ? '' : ($filter === 'done' ? 'WHERE done = 1' : 'WHERE done = 0');
$items = $pdo->query("SELECT * FROM inbox $where ORDER BY done ASC, id DESC")->fetchAll();

page_header('inbox.php');
?>
<h1>Quick-capture inbox</h1>
<p class="sub">Dump a thought, a task, or a link. Sort it out later.</p>

<form class="row" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="field" style="flex:1;min-width:260px">
        <label>What's on your mind?</label>
        <input name="body" placeholder="Call the dentist / https://… / random idea" autofocus>
    </div>
    <div class="field">
        <label>Type</label>
        <select name="kind">
            <option value="note">Note</option>
            <option value="todo">To-do</option>
            <option value="link">Link</option>
        </select>
    </div>
    <button type="submit">Capture</button>
</form>

<div class="row" style="margin-bottom:14px">
    <a class="pill <?= $filter==='open'?'ok':'' ?>" href="?show=open">Open</a>
    <a class="pill <?= $filter==='done'?'ok':'' ?>" href="?show=done">Done</a>
    <a class="pill <?= $filter==='all'?'ok':'' ?>" href="?show=all">All</a>
    <form method="post" style="margin-left:auto">
        <?= csrf_field() ?><input type="hidden" name="action" value="clear_done">
        <button class="ghost mini" type="submit">Clear completed</button>
    </form>
</div>

<div class="list">
<?php foreach ($items as $it): ?>
    <div class="item">
        <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <button class="ghost mini" type="submit" title="Toggle done"><?= $it['done'] ? '↺' : '✓' ?></button>
        </form>
        <div class="grow">
            <div class="title <?= $it['done'] ? 'done' : '' ?>">
                <?php if ($it['kind'] === 'link' && filter_var($it['body'], FILTER_VALIDATE_URL)): ?>
                    <a href="<?= e($it['body']) ?>" target="_blank" rel="noopener"><?= e($it['body']) ?></a>
                <?php else: ?>
                    <?= e($it['body']) ?>
                <?php endif; ?>
            </div>
            <div class="meta"><span class="pill"><?= e($it['kind']) ?></span> · <?= e($it['created_at']) ?></div>
        </div>
        <form method="post" onsubmit="return confirm('Delete this?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <button class="ghost mini" type="submit">✕</button>
        </form>
    </div>
<?php endforeach; ?>
<?php if (!$items): ?><div class="empty">Nothing here. Capture something above.</div><?php endif; ?>
</div>
<?php page_footer();
