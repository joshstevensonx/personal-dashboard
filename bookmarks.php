<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_bookmark') {
        $title = trim($_POST['title'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        if ($url !== '') {
            if (!preg_match('~^https?://~i', $url)) { $url = 'https://' . $url; }
            if ($title === '') { $title = parse_url($url, PHP_URL_HOST) ?: $url; }
            $pdo->prepare("INSERT INTO bookmarks (title, url, tags) VALUES (?,?,?)")->execute([$title, $url, $tags]);
            flash('Bookmark saved.');
        }
    } elseif ($action === 'del_bookmark') {
        $pdo->prepare("DELETE FROM bookmarks WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'add_snippet') {
        $label = trim($_POST['label'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        if ($label !== '' && $body !== '') {
            $pdo->prepare("INSERT INTO snippets (label, body, tags) VALUES (?,?,?)")->execute([$label, $body, $tags]);
            flash('Snippet saved.');
        }
    } elseif ($action === 'del_snippet') {
        $pdo->prepare("DELETE FROM snippets WHERE id = ?")->execute([(int)$_POST['id']]);
    }
    redirect('bookmarks.php' . (isset($_POST['q']) && $_POST['q'] !== '' ? '?q=' . urlencode($_POST['q']) : ''));
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $like = '%' . $q . '%';
    $bm = $pdo->prepare("SELECT * FROM bookmarks WHERE title LIKE ? OR url LIKE ? OR tags LIKE ? ORDER BY id DESC");
    $bm->execute([$like, $like, $like]);
    $bookmarks = $bm->fetchAll();
    $sn = $pdo->prepare("SELECT * FROM snippets WHERE label LIKE ? OR body LIKE ? OR tags LIKE ? ORDER BY id DESC");
    $sn->execute([$like, $like, $like]);
    $snippets = $sn->fetchAll();
} else {
    $bookmarks = $pdo->query("SELECT * FROM bookmarks ORDER BY id DESC")->fetchAll();
    $snippets = $pdo->query("SELECT * FROM snippets ORDER BY id DESC")->fetchAll();
}

page_header('bookmarks.php');
?>
<h1>Bookmarks &amp; snippet vault</h1>
<p class="sub">Saved links and reusable text, all searchable.</p>

<form class="row" method="get" style="margin-bottom:22px">
    <div class="field" style="flex:1;min-width:220px"><label>Search everything</label>
        <input name="q" value="<?= e($q) ?>" placeholder="title, url, tag, snippet text…"></div>
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="pill" href="bookmarks.php">Clear</a><?php endif; ?>
</form>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));align-items:start">

    <div>
        <h2 style="font-size:16px">Bookmarks</h2>
        <form class="row" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_bookmark"><input type="hidden" name="q" value="<?= e($q) ?>">
            <div class="field" style="flex:1;min-width:120px"><label>Title (optional)</label><input name="title" placeholder="Docs"></div>
            <div class="field" style="flex:1;min-width:140px"><label>URL</label><input name="url" placeholder="example.com" required></div>
            <div class="field"><label>Tags</label><input name="tags" placeholder="ref, tools" style="min-width:100px"></div>
            <button type="submit">Save</button>
        </form>
        <div class="list">
        <?php foreach ($bookmarks as $b): ?>
            <div class="item">
                <div class="grow">
                    <div class="title"><a href="<?= e($b['url']) ?>" target="_blank" rel="noopener"><?= e($b['title']) ?></a></div>
                    <div class="meta"><?= e($b['url']) ?><?= $b['tags'] ? ' · <span class="pill">' . e($b['tags']) . '</span>' : '' ?></div>
                </div>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del_bookmark"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
                    <button class="ghost mini" type="submit">✕</button></form>
            </div>
        <?php endforeach; ?>
        <?php if (!$bookmarks): ?><div class="empty">No bookmarks<?= $q !== '' ? ' match your search' : ' yet' ?>.</div><?php endif; ?>
        </div>
    </div>

    <div>
        <h2 style="font-size:16px">Snippets</h2>
        <form method="post" style="margin-bottom:18px">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_snippet"><input type="hidden" name="q" value="<?= e($q) ?>">
            <div class="row" style="margin-bottom:8px">
                <div class="field" style="flex:1;min-width:140px"><label>Label</label><input name="label" placeholder="Home address" required></div>
                <div class="field"><label>Tags</label><input name="tags" placeholder="personal" style="min-width:100px"></div>
            </div>
            <div class="field"><label>Text</label><textarea name="body" placeholder="The reusable text to copy later…" required></textarea></div>
            <div style="margin-top:8px"><button type="submit">Save snippet</button></div>
        </form>
        <div class="list">
        <?php foreach ($snippets as $s): ?>
            <div class="item">
                <div class="grow">
                    <div class="title"><?= e($s['label']) ?><?= $s['tags'] ? ' <span class="pill">' . e($s['tags']) . '</span>' : '' ?></div>
                    <div class="meta" style="white-space:pre-wrap"><?= e(mb_strimwidth($s['body'], 0, 160, '…')) ?></div>
                </div>
                <button class="ghost mini" type="button" data-copy="<?= e($s['body']) ?>">Copy</button>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="del_snippet"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
                    <button class="ghost mini" type="submit">✕</button></form>
            </div>
        <?php endforeach; ?>
        <?php if (!$snippets): ?><div class="empty">No snippets<?= $q !== '' ? ' match your search' : ' yet' ?>.</div><?php endif; ?>
        </div>
    </div>

</div>
<?php page_footer();
