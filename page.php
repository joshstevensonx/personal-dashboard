<?php
/**
 * Notion-style page view: cover, icon, title, block editor — or, when the page
 * is flagged as a database, its table / board / gallery / list views.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/db_advanced.php';
require_once __DIR__ . '/lib/page_ops.php';
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
        $type = $_POST['type'] ?? 'text';
        $opts = null;
        if (in_array($type, ['select', 'multi'], true)) {
            $raw = array_filter(array_map('trim', explode(',', (string)($_POST['options'] ?? ''))));
            $opts = $raw ?: ['Option 1', 'Option 2'];
        } elseif ($type === 'relation') {
            $opts = ['target' => (int)($_POST['rel_target'] ?? 0)];
        } elseif ($type === 'rollup') {
            $opts = [
                'relation'    => trim((string)($_POST['ru_relation'] ?? '')),
                'target_prop' => trim((string)($_POST['ru_prop'] ?? '')),
                'agg'         => array_key_exists($_POST['ru_agg'] ?? '', rollup_aggregations()) ? $_POST['ru_agg'] : 'count',
            ];
        } elseif ($type === 'formula') {
            $opts = ['expr' => (string)($_POST['fx'] ?? '')];
        }
        // add_property() only knows the basic types; validate the new ones here.
        $valid = array_key_exists($type, property_types_all()) ? $type : 'text';
        $pdo->prepare("INSERT INTO db_properties (database_id, key, name, type, options, position)
                       VALUES (?,?,?,?,?,(SELECT COALESCE(MAX(position),0)+1 FROM db_properties WHERE database_id = ?))")
            ->execute([
                $pid,
                (function (string $n) use ($pid, $pdo) {
                    $k = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($n))) ?: 'field';
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM db_properties WHERE database_id=? AND key=?");
                    $base = $k; $i = 2; $chk->execute([$pid, $k]);
                    while ((int)$chk->fetchColumn() > 0) { $k = $base . '_' . $i++; $chk->execute([$pid, $k]); }
                    return $k;
                })(trim($_POST['name'] ?? '') ?: 'Field'),
                trim($_POST['name'] ?? '') ?: 'Field',
                $valid,
                $opts !== null ? json_encode(array_values((array)$opts) === (array)$opts ? array_values((array)$opts) : $opts) : null,
                $pid,
            ]);
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

    /* ---------------------------------------------------------- page ops -- */
    } elseif ($a === 'duplicate') {
        $new = duplicate_page($pid);
        flash('Page duplicated.');
        redirect('page.php?id=' . ($new ?: $pid));

    } elseif ($a === 'move') {
        $target = ($_POST['new_parent'] ?? '') !== '' ? (int)$_POST['new_parent'] : null;
        flash(move_page($pid, $target) ? 'Page moved.' : "Can't move a page inside itself.");
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'toggle_template') {
        $pdo->prepare("UPDATE pages SET is_template = 1 - is_template WHERE id = ?")->execute([$pid]);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'from_template') {
        $new = create_from_template((int)$_POST['template_id'], ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null);
        redirect('page.php?id=' . ($new ?: 0));

    } elseif ($a === 'restore') {
        restore_page($pid);
        flash('Page restored.');
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'purge') {
        purge_page($pid);
        flash('Page permanently deleted.');
        redirect('page.php?trash=1');

    } elseif ($a === 'empty_trash') {
        $n = empty_trash();
        flash("Trash emptied — $n page(s) removed.");
        redirect('page.php?trash=1');

    /* --------------------------------------------------------- comments -- */
    } elseif ($a === 'add_comment') {
        $body = trim($_POST['body'] ?? '');
        if ($body !== '') { add_comment($pid, $body); }
        redirect('page.php?id=' . $pid . '#comments');

    } elseif ($a === 'resolve_comment') {
        resolve_comment((int)$_POST['comment_id']);
        redirect('page.php?id=' . $pid . '#comments');

    } elseif ($a === 'del_comment') {
        delete_comment((int)$_POST['comment_id']);
        redirect('page.php?id=' . $pid . '#comments');

    /* ------------------------------------------------------ saved views -- */
    } elseif ($a === 'add_view') {
        $t = array_key_exists($_POST['type'] ?? '', view_types()) ? $_POST['type'] : 'table';
        $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM db_views WHERE database_id = " . (int)$pid)->fetchColumn();
        $pdo->prepare("INSERT INTO db_views (database_id, name, type, position) VALUES (?,?,?,?)")
            ->execute([$pid, trim($_POST['name'] ?? '') ?: ucfirst($t), $t, $pos]);
        redirect('page.php?id=' . $pid . '&view=' . (int)$pdo->lastInsertId());

    } elseif ($a === 'update_view') {
        $vid = (int)$_POST['view_id'];
        $t = array_key_exists($_POST['type'] ?? '', view_types()) ? $_POST['type'] : 'table';
        $pdo->prepare("UPDATE db_views SET name=?, type=?, group_by=?, sort_key=?, sort_dir=?,
                       filter_key=?, filter_op=?, filter_value=? WHERE id=? AND database_id=?")
            ->execute([
                trim($_POST['name'] ?? '') ?: ucfirst($t), $t,
                trim($_POST['group_by'] ?? '') ?: null,
                trim($_POST['sort_key'] ?? '') ?: null,
                ($_POST['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
                trim($_POST['filter_key'] ?? '') ?: null,
                $_POST['filter_op'] ?? 'contains',
                trim($_POST['filter_value'] ?? '') ?: null,
                $vid, $pid,
            ]);
        redirect('page.php?id=' . $pid . '&view=' . $vid);

    } elseif ($a === 'del_view') {
        $pdo->prepare("DELETE FROM db_views WHERE id = ? AND database_id = ?")
            ->execute([(int)$_POST['view_id'], $pid]);
        redirect('page.php?id=' . $pid);

    } elseif ($a === 'set_relation') {
        // Store the chosen related page ids as a comma-separated list.
        $ids = array_map('intval', (array)($_POST['related'] ?? []));
        set_value((int)$_POST['row_id'], (string)$_POST['key'], implode(',', array_filter($ids)));
        redirect('page.php?id=' . $pid);
    }
    redirect('page.php');
}

/* ----------------------------------------------------------------- read --- */
$tree = page_tree();
$roots = $tree[0] ?? [];

$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = $pageId ? get_page($pageId) : null;

/* ---- trash view ---- */
if (isset($_GET['trash'])) {
    $trash = trashed_pages();
    page_header('page.php');
    ?>
    <div class="page-wrap" style="padding-top:32px">
        <div class="crumbs"><a href="page.php">Pages</a><span>/</span><span class="cur">Trash</span></div>
        <h1 class="page-title" style="font-size:30px">Trash</h1>
        <p class="muted">Deleted pages stay here until you remove them permanently.</p>
        <?php if ($trash): ?>
            <form method="post" onsubmit="return confirm('Permanently delete ALL trashed pages? This cannot be undone.')"
                  style="margin:12px 0 18px">
                <?= csrf_field() ?><input type="hidden" name="action" value="empty_trash">
                <button class="nbtn" type="submit">Empty trash (<?= count($trash) ?>)</button>
            </form>
        <?php endif; ?>
        <div class="list">
            <?php foreach ($trash as $t): ?>
                <div class="item">
                    <span style="width:24px"><?= e($t['icon'] ?: ($t['is_database'] ? '🗃' : '📄')) ?></span>
                    <span class="grow"><?= e($t['title']) ?></span>
                    <form method="post"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="page_id" value="<?= (int)$t['id'] ?>">
                        <button class="nbtn" type="submit">Restore</button></form>
                    <form method="post" onsubmit="return confirm('Permanently delete this page and its children?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="purge">
                        <input type="hidden" name="page_id" value="<?= (int)$t['id'] ?>">
                        <button class="nbtn" type="submit">Delete forever</button></form>
                </div>
            <?php endforeach; ?>
            <?php if (!$trash): ?><div class="empty">Trash is empty.</div><?php endif; ?>
        </div>
    </div>
    <?php page_footer(); exit;
}

/* ---- search across pages ---- */
if (isset($_GET['q']) && trim($_GET['q']) !== '') {
    $q = trim($_GET['q']);
    $hits = search_pages($q);
    page_header('page.php');
    ?>
    <div class="page-wrap" style="padding-top:32px">
        <h1 class="page-title" style="font-size:30px">Search</h1>
        <form method="get" class="row" style="margin:10px 0 18px">
            <div class="field" style="flex:1"><label>Find pages and blocks</label>
                <input name="q" value="<?= e($q) ?>" autofocus></div>
            <button type="submit">Search</button>
        </form>
        <div class="list">
            <?php foreach ($hits as $h): ?>
                <a class="item" style="text-decoration:none;color:inherit" href="page.php?id=<?= (int)$h['id'] ?>">
                    <span style="width:24px"><?= e($h['icon'] ?: ($h['is_database'] ? '🗃' : '📄')) ?></span>
                    <span class="grow">
                        <span class="title"><?= e($h['title']) ?></span>
                        <?php if (!empty($h['snippet'])): ?>
                            <span class="meta"><?= e(mb_strimwidth($h['snippet'], 0, 110, '…')) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
            <?php if (!$hits): ?><div class="empty">Nothing matched “<?= e($q) ?>”.</div><?php endif; ?>
        </div>
    </div>
    <?php page_footer(); exit;
}

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

/* Saved views: the active one supplies type, grouping, sort and filter.
   URL params still win, so a quick ad-hoc sort doesn't overwrite the view. */
$views = $isDb ? db_views($pageId) : [];
$activeView = $views[0] ?? null;
if ($isDb && isset($_GET['view'])) {
    foreach ($views as $v) {
        if ((int)$v['id'] === (int)$_GET['view']) { $activeView = $v; break; }
    }
}

$sortKey = $_GET['sort'] ?? ($activeView['sort_key'] ?? '');
$sortDir = ($_GET['dir'] ?? ($activeView['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$fKey    = $_GET['fkey'] ?? ($activeView['filter_key'] ?? '');
$fVal    = $_GET['fval'] ?? ($activeView['filter_value'] ?? '');
$fOp     = $activeView['filter_op'] ?? 'contains';

$rows = $isDb ? db_rows($pageId, [
    'sort'   => $sortKey !== '' ? ['key' => $sortKey, 'dir' => $sortDir] : [],
    'filter' => $fKey !== '' ? ['key' => $fKey, 'op' => $fOp, 'value' => $fVal] : [],
]) : [];

// Rollups and formulas are derived, so they're computed after the rows load.
if ($isDb) { apply_computed($props, $rows); }

$view    = $activeView['type'] ?? ($page['db_view'] ?: 'table');
$groupBy = $activeView['group_by'] ?? ($page['db_group_by'] ?: '');
$hidden  = $activeView ? hidden_props($activeView) : [];
$coverCss = cover_css($page['cover']);

$comments = page_comments($pageId, true);
$openComments = count(array_filter($comments, fn($c) => !$c['resolved']));

// Breadcrumb HTML is shared with the "breadcrumb" block type.
$crumbHtml = '';
foreach ($trail as $i => $t) {
    $crumbHtml .= ($i ? ' / ' : '') . e(($t['icon'] ? $t['icon'] . ' ' : '') . $t['title']);
}
$GLOBALS['__crumbHtml'] = $crumbHtml;

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
        <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="duplicate"><input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <button class="nbtn" type="submit">Duplicate</button></form>
        <button class="nbtn" type="button" id="btn-move">Move to…</button>
        <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_template"><input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <button class="nbtn" type="submit"><?= !empty($page['is_template']) ? '◆ Is a template' : '◇ Make template' ?></button></form>
        <a class="nbtn" href="#comments">💬 <?= $openComments ?: '' ?> Comments</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Move this page and its children to trash?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <button class="nbtn" type="submit">Delete</button></form>
    </div>

    <!-- move picker -->
    <div class="picker" id="movepicker" hidden>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="move">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <div class="field"><label>Move under</label>
                <select name="new_parent">
                    <option value="">— top level —</option>
                    <?php
                    $flat = [];
                    $walk = function ($parent, $depth) use (&$walk, $tree, &$flat, $pageId) {
                        foreach ($tree[$parent] ?? [] as $n) {
                            if ((int)$n['id'] === $pageId) continue;   // can't nest under itself
                            $flat[] = ['id' => $n['id'], 'label' => str_repeat('— ', $depth) . $n['title']];
                            if ($depth < 5) { $walk((int)$n['id'], $depth + 1); }
                        }
                    };
                    $walk(0, 0);
                    foreach ($flat as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= (int)$page['parent_id'] === (int)$f['id'] ? 'selected' : '' ?>>
                            <?= e($f['label']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button type="submit" style="margin-top:8px">Move</button>
        </form>
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
            <?php
            // Table-of-contents blocks need the whole heading list, so they're
            // filled in here rather than inside render_block().
            $headings = array_values(array_filter($blocks,
                fn($b) => in_array($b['type'], ['heading1', 'heading2', 'heading3'], true) && trim($b['content']) !== ''));
            $tocHtml = '';
            foreach ($headings as $h) {
                $lvl = (int)substr($h['type'], -1);
                $tocHtml .= "<a class='lvl$lvl' href='#h" . (int)$h['id'] . "'>" . e($h['content']) . "</a>";
            }
            if ($tocHtml === '') { $tocHtml = "<span class='muted'>Add headings to build a table of contents.</span>"; }

            foreach ($blocks as $b) {
                $html = render_block($b);
                if ($b['type'] === 'toc') {
                    $html = str_replace("<span class='muted'>Table of contents</span>", $tocHtml, $html);
                }
                // Give headings an anchor so the TOC can jump to them.
                if (in_array($b['type'], ['heading1', 'heading2', 'heading3'], true)) {
                    $html = str_replace("<div class='bk' data-id='" . (int)$b['id'] . "'",
                                        "<div id='h" . (int)$b['id'] . "' class='bk' data-id='" . (int)$b['id'] . "'", $html);
                }
                echo $html;
            }
            ?>
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

        <?php $tpls = templates_list(); if ($tpls): ?>
            <form method="post" class="row" style="margin-top:8px">
                <?= csrf_field() ?><input type="hidden" name="action" value="from_template">
                <input type="hidden" name="parent_id" value="<?= (int)$pageId ?>">
                <div class="field"><label>Or start from a template</label>
                    <select name="template_id">
                        <?php foreach ($tpls as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e(($t['icon'] ? $t['icon'] . ' ' : '') . $t['title']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <button class="ghost" type="submit">Use template</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <!-- comments -->
    <div class="comments" id="comments">
        <h3 style="font-size:15px;margin:0 0 10px">
            Comments <?= $openComments ? "<span class='pill'>$openComments open</span>" : '' ?>
        </h3>
        <?php foreach ($comments as $c): ?>
            <div class="comment <?= $c['resolved'] ? 'resolved' : '' ?>">
                <div class="comment-body">
                    <?= nl2br(e($c['body'])) ?>
                    <div class="comment-meta"><?= e($c['created_at']) ?><?= $c['resolved'] ? ' · resolved' : '' ?></div>
                </div>
                <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="resolve_comment">
                    <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                    <button class="nbtn" type="submit"><?= $c['resolved'] ? 'Reopen' : 'Resolve' ?></button></form>
                <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="del_comment">
                    <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                    <button class="nbtn" type="submit">✕</button></form>
            </div>
        <?php endforeach; ?>
        <?php if (!$comments): ?><p class="muted" style="font-size:13.5px">No comments yet.</p><?php endif; ?>

        <form method="post" style="margin-top:12px">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_comment">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <textarea name="body" placeholder="Add a comment…" style="min-height:64px" required></textarea>
            <div style="margin-top:8px"><button type="submit">Comment</button></div>
        </form>
    </div>
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
