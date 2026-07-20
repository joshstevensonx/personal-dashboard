<?php
/**
 * Notion-style page view: cover, icon, title, block editor — or, when the page
 * is flagged as a database, its table / board / gallery / list views.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

/* ---------------------------------------------------------------- writes --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    $pid = (int)($_POST['page_id'] ?? 0);

    if ($a === 'new_page') {
        $id = create_page(
            ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null,
            trim($_POST['title'] ?? '') ?: 'Untitled',
            !empty($_POST['is_database'])
        );
        redirect('page.php?id=' . $id);

    } elseif ($a === 'rename') {
        $pdo->prepare("UPDATE pages SET title = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([trim($_POST['title'] ?? '') ?: 'Untitled', $pid]);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'set_icon') {
        $pdo->prepare("UPDATE pages SET icon = ? WHERE id = ?")
            ->execute([trim($_POST['icon'] ?? '') ?: null, $pid]);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'set_cover') {
        $c = $_POST['cover'] ?? 'none';
        $pdo->prepare("UPDATE pages SET cover = ? WHERE id = ?")
            ->execute([$c === 'none' ? null : $c, $pid]);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'favorite') {
        $pdo->prepare("UPDATE pages SET favorite = 1 - favorite WHERE id = ?")->execute([$pid]);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'delete') {
        delete_page($pid);
        flash('Page moved to trash.');
        redirect('page.php');

    } elseif ($a === 'add_row') {
        $rid = create_page($pid, trim($_POST['title'] ?? '') ?: 'Untitled');
        // Rows are pages too, but they shouldn't clutter the sidebar tree as
        // standalone pages — the database view is their home.
        if (!empty($_POST['group_key']) && isset($_POST['group_value'])) {
            set_value($rid, (string)$_POST['group_key'], (string)$_POST['group_value']);
        }
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'add_prop') {
        $opts = null;
        if (in_array($_POST['type'] ?? '', ['select', 'multi'], true)) {
            $raw = array_filter(array_map('trim', explode(',', (string)($_POST['options'] ?? ''))));
            $opts = $raw ?: ['Option 1', 'Option 2'];
        }
        add_property($pid, trim($_POST['name'] ?? '') ?: 'Field', $_POST['type'] ?? 'text', $opts);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'del_prop') {
        delete_property($pid, (int)$_POST['prop_id']);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'set_view') {
        $v = in_array($_POST['view'] ?? '', ['table', 'board', 'gallery', 'list'], true) ? $_POST['view'] : 'table';
        $pdo->prepare("UPDATE pages SET db_view = ? WHERE id = ?")->execute([$v, $pid]);
        redirect('page.php?id=' . $pid . '&' . http_build_query(array_filter([
            'sort' => $_POST['sort'] ?? '', 'dir' => $_POST['dir'] ?? '',
        ])));

    } elseif ($a === 'set_group') {
        $pdo->prepare("UPDATE pages SET db_group_by = ? WHERE id = ?")
            ->execute([trim($_POST['group_by'] ?? '') ?: null, $pid]);
        redirect('page.php?id=' . $pid);
    }
    redirect('page.php');
}

/* ----------------------------------------------------------------- read --- */
$tree = page_tree();
$roots = $tree[0] ?? [];

$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = $pageId ? get_page($pageId) : null;

// No page selected: show an index of top-level pages.
if (!$page) {
    page_header('page.php');
    ?>
    <div class="page-wrap" style="padding-top:40px">
        <h1 class="page-title" style="font-size:32px">Pages</h1>
        <p class="muted" style="margin:0 0 18px">A Notion-style workspace. Every page holds blocks, and any page can become a database.</p>
        <form method="post" class="row" style="margin-bottom:20px">
            <?= csrf_field() ?><input type="hidden" name="action" value="new_page">
            <div class="field" style="flex:1;min-width:200px"><label>New page</label>
                <input name="title" placeholder="Page title" required></div>
            <div class="field"><label>&nbsp;</label>
                <label style="display:flex;gap:6px;align-items:center;color:var(--n-text);font-size:13px">
                    <input type="checkbox" name="is_database" style="min-width:auto"> As database</label></div>
            <button type="submit">Create</button>
        </form>
        <div class="list">
            <?php foreach ($roots as $p): ?>
                <a class="item" style="text-decoration:none;color:inherit" href="page.php?id=<?= (int)$p['id'] ?>">
                    <span style="font-size:20px;width:26px"><?= e($p['icon'] ?: ($p['is_database'] ? '🗃' : '📄')) ?></span>
                    <span class="grow"><span class="title"><?= e($p['title']) ?></span></span>
                    <?php if ($p['is_database']): ?><span class="pill">database</span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$roots): ?><div class="empty">No pages yet — create your first above.</div><?php endif; ?>
        </div>
    </div>
    <?php
    page_footer();
    exit;
}

$isDb = !empty($page['is_database']);
$trail = page_trail($pageId);
$blocks = page_blocks($pageId);
$children = $tree[$pageId] ?? [];

$props = $isDb ? db_properties($pageId) : [];
$sortKey = $_GET['sort'] ?? '';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$fKey = $_GET['fkey'] ?? '';
$fVal = $_GET['fval'] ?? '';
$rows = $isDb ? db_rows($pageId, [
    'sort'   => $sortKey !== '' ? ['key' => $sortKey, 'dir' => $sortDir] : [],
    'filter' => $fKey !== '' ? ['key' => $fKey, 'op' => 'contains', 'value' => $fVal] : [],
]) : [];

$view = $page['db_view'] ?: 'table';
$groupBy = $page['db_group_by'] ?: '';
$coverCss = cover_css($page['cover']);

page_header('page.php');
?>

<?php if ($coverCss): ?>
    <div class="page-cover" style="background:<?= e($coverCss) ?>"></div>
<?php endif; ?>

<div class="page-wrap">
    <!-- breadcrumbs -->
    <div class="crumbs">
        <a href="page.php">Pages</a>
        <?php foreach ($trail as $i => $t): ?>
            <span>/</span>
            <?php if ($i === count($trail) - 1): ?>
                <span class="cur"><?= e(($t['icon'] ? $t['icon'] . ' ' : '') . $t['title']) ?></span>
            <?php else: ?>
                <a href="page.php?id=<?= (int)$t['id'] ?>"><?= e(($t['icon'] ? $t['icon'] . ' ' : '') . $t['title']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- icon + title -->
    <?php if ($page['icon']): ?>
        <div class="page-icon" id="pageicon"><?= e($page['icon']) ?></div>
    <?php endif; ?>

    <form method="post" id="titleform">
        <?= csrf_field() ?><input type="hidden" name="action" value="rename">
        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
        <input class="page-title" name="title" value="<?= e($page['title']) ?>"
               placeholder="Untitled" autocomplete="off">
    </form>

    <div class="page-actions" id="pageactions">
        <button class="nbtn" type="button" id="btn-icon">😀 <?= $page['icon'] ? 'Change icon' : 'Add icon' ?></button>
        <button class="nbtn" type="button" id="btn-cover"><?= $coverCss ? 'Change cover' : 'Add cover' ?></button>
        <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="favorite"><input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <button class="nbtn" type="submit"><?= !empty($page['favorite']) ? '★ Favourited' : '☆ Favourite' ?></button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Move this page and its children to trash?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <button class="nbtn" type="submit">Delete</button></form>
    </div>

    <!-- icon picker -->
    <div class="picker" id="iconpicker" hidden>
        <form method="post" id="iconform">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_icon">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <input type="hidden" name="icon" id="iconval">
            <div class="picker-grid">
                <?php foreach (['📄','📝','✅','📌','💡','🔥','⭐','🎯','📚','🧠','🛠','📅','💰','🏠','🚀','🎵','🍀','☕','🌙','⚡','🧩','📈','🗂','🔒'] as $em): ?>
                    <button class="picker-em" type="button" data-em="<?= $em ?>"><?= $em ?></button>
                <?php endforeach; ?>
            </div>
            <button class="nbtn" type="button" data-em="">Remove icon</button>
        </form>
    </div>

    <!-- cover picker -->
    <div class="picker" id="coverpicker" hidden>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_cover">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <div class="picker-covers">
                <?php foreach (cover_presets() as $k => $c): ?>
                    <button class="picker-cover" type="submit" name="cover" value="<?= e($k) ?>"
                            style="background:<?= e($c['css'] ?: 'var(--n-hover)') ?>" title="<?= e($c['label']) ?>"></button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <?php if ($isDb): ?>
        <?php require __DIR__ . '/partials_database.php'; ?>
    <?php else: ?>

        <!-- block editor -->
        <div class="blocks" id="blocks" data-page="<?= (int)$pageId ?>">
            <?php foreach ($blocks as $b) { echo render_block($b); } ?>
        </div>
        <div style="padding:0 0 40px">
            <button class="nbtn" type="button" id="addblock">+ Add a block</button>
        </div>

        <?php if ($children): ?>
            <h3 style="font-size:14px;color:var(--n-muted);margin:20px 0 8px">Sub-pages</h3>
            <div class="list">
                <?php foreach ($children as $c): ?>
                    <a class="item" style="text-decoration:none;color:inherit;padding:8px 12px" href="page.php?id=<?= (int)$c['id'] ?>">
                        <span style="width:22px"><?= e($c['icon'] ?: ($c['is_database'] ? '🗃' : '📄')) ?></span>
                        <span class="grow"><?= e($c['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="row" style="margin-top:14px">
            <?= csrf_field() ?><input type="hidden" name="action" value="new_page">
            <input type="hidden" name="parent_id" value="<?= (int)$pageId ?>">
            <div class="field" style="flex:1;min-width:180px"><label>Add a sub-page</label>
                <input name="title" placeholder="Sub-page title" required></div>
            <div class="field"><label>&nbsp;</label>
                <label style="display:flex;gap:6px;align-items:center;color:var(--n-text);font-size:13px">
                    <input type="checkbox" name="is_database" style="min-width:auto"> As database</label></div>
            <button class="ghost" type="submit">Create</button>
        </form>
    <?php endif; ?>
</div>

<div class="saving" id="saving" hidden>Saving…</div>

<script>
window.PAGE_ID = <?= (int)$pageId ?>;
window.CSRF = <?= json_for_html(csrf_token()) ?>;
window.BLOCK_TYPES = <?= json_for_html(array_map(
    fn($k, $v) => ['type' => $k, 'label' => $v['label'], 'icon' => $v['icon'], 'hint' => $v['hint']],
    array_keys(block_types()), array_values(block_types())
)) ?>;
</script>
<script src="assets/blocks.js"></script>
<?php page_footer();
