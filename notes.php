<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/notes.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

$uploadDir = __DIR__ . '/uploads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';

    if ($a === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '') ?: 'Untitled';
        $body = (string)($_POST['body'] ?? '');
        $folder = ($_POST['folder_id'] ?? '') !== '' ? (int)$_POST['folder_id'] : null;
        $fmtIn = $_POST['format'] ?? 'md';
        $format = in_array($fmtIn, ['md', 'rich'], true) ? $fmtIn : 'md';
        $dailyDate = ($_POST['daily_date'] ?? '') !== '' ? $_POST['daily_date'] : null;

        if ($id > 0) {
            // Keep a revision if the body actually changed.
            $old = $pdo->prepare("SELECT body FROM notes WHERE id=?");
            $old->execute([$id]);
            $prev = (string)$old->fetchColumn();
            if ($prev !== $body) {
                $pdo->prepare("INSERT INTO note_revisions (note_id, body) VALUES (?,?)")->execute([$id, $prev]);
                // Keep the 50 most recent revisions per note.
                $pdo->prepare("DELETE FROM note_revisions WHERE note_id = ? AND id NOT IN
                               (SELECT id FROM note_revisions WHERE note_id = ? ORDER BY id DESC LIMIT 50)")
                    ->execute([$id, $id]);
            }
            $pdo->prepare("UPDATE notes SET title=?, body=?, folder_id=?, format=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$title, $body, $folder, $format, $id]);
        } else {
            $pdo->prepare("INSERT INTO notes (title, body, folder_id, format, daily_date, updated_at) VALUES (?,?,?,?,?,datetime('now'))")
                ->execute([$title, $body, $folder, $format, $dailyDate]);
            $id = (int)$pdo->lastInsertId();
            resolve_dangling_links($id, $title);
        }
        sync_note_links($id, $body);
        sync_tags('note', $id, $_POST['tags'] ?? '');
        reindex_fts();
        flash('Note saved.');
        redirect('notes.php?id=' . $id);

    } elseif ($a === 'delete') {
        $pdo->prepare("UPDATE notes SET deleted_at=datetime('now') WHERE id=?")->execute([(int)$_POST['id']]);
        reindex_fts();
        flash('Note moved to trash.');
        redirect('notes.php');

    } elseif ($a === 'restore') {
        $pdo->prepare("UPDATE notes SET deleted_at=NULL WHERE id=?")->execute([(int)$_POST['id']]);
        reindex_fts();
        redirect('notes.php?id=' . (int)$_POST['id']);

    } elseif ($a === 'pin') {
        $pdo->prepare("UPDATE notes SET pinned = 1 - pinned WHERE id=?")->execute([(int)$_POST['id']]);
        redirect('notes.php?id=' . (int)$_POST['id']);

    } elseif ($a === 'add_folder') {
        $n = trim($_POST['name'] ?? '');
        if ($n !== '') {
            $pdo->prepare("INSERT INTO folders (parent_id, name) VALUES (?,?)")
                ->execute([($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null, $n]);
            flash('Folder created.');
        }
        redirect('notes.php');

    } elseif ($a === 'del_folder') {
        $pdo->prepare("DELETE FROM folders WHERE id=?")->execute([(int)$_POST['id']]);
        redirect('notes.php');

    } elseif ($a === 'restore_rev') {
        $rid = (int)$_POST['rev_id'];
        $nid = (int)$_POST['id'];
        $r = $pdo->prepare("SELECT body FROM note_revisions WHERE id=? AND note_id=?");
        $r->execute([$rid, $nid]);
        if ($body = $r->fetchColumn()) {
            $cur = $pdo->prepare("SELECT body FROM notes WHERE id=?"); $cur->execute([$nid]);
            $pdo->prepare("INSERT INTO note_revisions (note_id, body) VALUES (?,?)")->execute([$nid, (string)$cur->fetchColumn()]);
            $pdo->prepare("UPDATE notes SET body=?, updated_at=datetime('now') WHERE id=?")->execute([$body, $nid]);
            sync_note_links($nid, $body);
            flash('Revision restored.');
        }
        redirect('notes.php?id=' . $nid);

    } elseif ($a === 'save_template') {
        $n = trim($_POST['name'] ?? '');
        $b = (string)($_POST['body'] ?? '');
        if ($n !== '' && $b !== '') {
            $pdo->prepare("INSERT INTO templates (name, body, kind) VALUES (?,?,'note')")->execute([$n, $b]);
            flash('Template saved.');
        }
        redirect('notes.php');

    } elseif ($a === 'upload' && !empty($_FILES['file']['tmp_name'])) {
        $nid = (int)$_POST['id'];
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','gif','webp','pdf','txt','md','csv','svg'];
        if (in_array($ext, $allowed, true) && $f['size'] < 12 * 1024 * 1024) {
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $safe)) {
                $pdo->prepare("INSERT INTO attachments (note_id, filename, path, mime, size) VALUES (?,?,?,?,?)")
                    ->execute([$nid ?: null, $f['name'], 'uploads/' . $safe, $f['type'] ?? '', (int)$f['size']]);
                flash('File uploaded — insert it with the link shown below.');
            }
        } else {
            flash('Unsupported file type or file too large (max 12 MB).');
        }
        redirect('notes.php?id=' . $nid);
    }
    redirect('notes.php');
}

/* ------------------------------------------------------------------ read --- */
$folders = $pdo->query("SELECT * FROM folders ORDER BY name")->fetchAll();
$titleMap = note_title_map();
$q = trim($_GET['q'] ?? '');
$folderId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;
$showTrash = isset($_GET['trash']);

// Daily note shortcut
if (isset($_GET['daily'])) {
    $d = date('Y-m-d');
    $ex = $pdo->prepare("SELECT id FROM notes WHERE daily_date = ? AND deleted_at IS NULL");
    $ex->execute([$d]);
    if ($existing = $ex->fetchColumn()) {
        redirect('notes.php?id=' . (int)$existing);
    }
    $tpl = $pdo->query("SELECT body FROM templates WHERE kind='daily' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO notes (title, body, daily_date, updated_at) VALUES (?,?,?,datetime('now'))")
        ->execute([date('l, j F Y', strtotime($d)), $tpl ?: "## Notes\n\n\n## Wins\n\n\n## Tomorrow\n", $d]);
    redirect('notes.php?id=' . (int)$pdo->lastInsertId());
}

$editing = null;
if (isset($_GET['id'])) {
    $st = $pdo->prepare("SELECT * FROM notes WHERE id=?");
    $st->execute([(int)$_GET['id']]);
    $editing = $st->fetch() ?: null;
} elseif (isset($_GET['new'])) {
    $editing = ['id' => 0, 'title' => (string)$_GET['new'], 'body' => '', 'folder_id' => null,
                'format' => 'md', 'pinned' => 0, 'daily_date' => null, 'deleted_at' => null,
                'updated_at' => null, 'created_at' => null];
}

if ($q !== '') {
    $list = search_notes($q);
} else {
    $sql = "SELECT id, title, updated_at, folder_id, pinned, substr(body,1,140) AS snip
            FROM notes WHERE " . ($showTrash ? "deleted_at IS NOT NULL" : "deleted_at IS NULL");
    $args = [];
    if ($folderId !== null) { $sql .= " AND folder_id = ?"; $args[] = $folderId; }
    $sql .= " ORDER BY pinned DESC, COALESCE(updated_at, created_at) DESC LIMIT 200";
    $ls = $pdo->prepare($sql);
    $ls->execute($args);
    $list = $ls->fetchAll();
}

$noteTags = tags_for('note', array_column($list, 'id'));
$templates = $pdo->query("SELECT * FROM templates ORDER BY name")->fetchAll();

page_header('notes.php');
?>
<h1>Notes</h1>
<p class="sub">Markdown with live preview. Link notes with <code>[[Note Title]]</code> — backlinks build themselves.</p>

<div class="row" style="margin-bottom:16px;align-items:flex-end">
    <form class="row" method="get" style="margin:0;flex:1;min-width:220px">
        <div class="field" style="flex:1"><label>Search notes<?= has_fts5() ? ' (full-text)' : '' ?></label>
            <input name="q" value="<?= e($q) ?>" placeholder="search titles and content…"></div>
        <button type="submit">Search</button>
        <?php if ($q !== ''): ?><a class="pill" href="notes.php">Clear</a><?php endif; ?>
    </form>
    <a class="pill ok" href="notes.php?new=">+ New note</a>
    <a class="pill" href="notes.php?daily=1">Today's note</a>
    <a class="pill <?= $showTrash ? 'danger' : '' ?>" href="notes.php?trash=1">Trash</a>
    <a class="pill" href="graph.php">Graph</a>
</div>

<div style="display:grid;grid-template-columns:minmax(220px,270px) 1fr;gap:18px;align-items:start" class="notes-layout">

    <!-- sidebar: folders + list -->
    <div>
        <div class="card" style="margin-bottom:12px">
            <h2>Folders</h2>
            <div style="display:flex;flex-direction:column;gap:3px">
                <a class="pill <?= $folderId === null && !$showTrash ? 'ok' : '' ?>" href="notes.php">All notes</a>
                <?php foreach ($folders as $f): ?>
                    <div style="display:flex;gap:4px;align-items:center">
                        <a class="pill <?= $folderId === (int)$f['id'] ? 'ok' : '' ?>" style="flex:1"
                           href="notes.php?folder=<?= (int)$f['id'] ?>"><?= e($f['name']) ?></a>
                        <form method="post" onsubmit="return confirm('Delete folder? Notes inside are kept.')" style="margin:0">
                            <?= csrf_field() ?><input type="hidden" name="action" value="del_folder">
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <button class="ghost mini" type="submit" style="padding:2px 6px">✕</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
            <form class="row" method="post" style="margin:8px 0 0;gap:5px">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_folder">
                <input name="name" placeholder="New folder" style="min-width:100px;flex:1">
                <button class="ghost mini" type="submit">+</button>
            </form>
        </div>

        <div class="list">
            <?php foreach ($list as $n): ?>
                <a class="item" style="text-decoration:none;color:inherit;padding:10px 12px"
                   href="notes.php?id=<?= (int)$n['id'] ?>">
                    <div class="grow">
                        <div class="title" style="font-size:14px">
                            <?= !empty($n['pinned']) ? '📌 ' : '' ?><?= e($n['title']) ?>
                        </div>
                        <div class="meta" style="font-size:12px">
                            <?= isset($n['snip']) ? ($q !== '' ? $n['snip'] : e(mb_strimwidth(strip_tags($n['snip']), 0, 70, '…'))) : '' ?>
                        </div>
                        <?php if (!empty($noteTags[$n['id']])): ?>
                            <div class="meta" style="font-size:11px">#<?= e($noteTags[$n['id']]) ?></div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if (!$list): ?>
                <div class="empty"><?= $q !== '' ? 'No notes match.' : ($showTrash ? 'Trash is empty.' : 'No notes yet.') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- editor / reader -->
    <div>
        <?php if ($editing !== null): ?>
            <?php
            $isNew = empty($editing['id']);
            $curTags = $isNew ? '' : ($noteTags[$editing['id']] ?? (tags_for('note', [$editing['id']])[$editing['id']] ?? ''));
            $links = $isNew ? [] : backlinks((int)$editing['id'], $editing['title']);
            $revs = [];
            $atts = [];
            if (!$isNew) {
                $rv = $pdo->prepare("SELECT id, created_at, length(body) len FROM note_revisions WHERE note_id=? ORDER BY id DESC LIMIT 10");
                $rv->execute([$editing['id']]); $revs = $rv->fetchAll();
                $at = $pdo->prepare("SELECT * FROM attachments WHERE note_id=? ORDER BY id DESC");
                $at->execute([$editing['id']]); $atts = $at->fetchAll();
            }
            ?>
            <form method="post" id="noteform">
                <?= csrf_field() ?><input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <input type="hidden" name="daily_date" value="<?= e((string)($editing['daily_date'] ?? '')) ?>">
                <input type="hidden" name="format" id="fmt" value="<?= e($editing['format'] ?? 'md') ?>">

                <div class="row" style="margin-bottom:10px">
                    <div class="field" style="flex:1;min-width:200px"><label>Title</label>
                        <input name="title" id="ntitle" value="<?= e($editing['title']) ?>" required></div>
                    <div class="field"><label>Folder</label>
                        <select name="folder_id">
                            <option value="">— none —</option>
                            <?php foreach ($folders as $f): ?>
                                <option value="<?= (int)$f['id'] ?>" <?= (int)($editing['folder_id'] ?? 0) === (int)$f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>Tags</label><input name="tags" value="<?= e($curTags) ?>" placeholder="ref, ideas"></div>
                    <div class="field"><label>&nbsp;</label><button type="submit">Save</button></div>
                </div>

                <div class="row" style="margin-bottom:8px;gap:6px">
                    <button class="ghost mini" type="button" id="btn-split">Split view</button>
                    <button class="ghost mini" type="button" id="btn-edit">Edit only</button>
                    <button class="ghost mini" type="button" id="btn-read">Reading mode</button>
                    <?php if ($templates): ?>
                        <select id="tplpick" style="min-width:130px">
                            <option value="">Insert template…</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= e($t['body']) ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <span style="flex:1"></span>
                    <?php if (!$isNew): ?>
                        <span class="muted" style="font-size:12px">
                            <?= reading_time($editing['body']) ?> min read ·
                            <?= $editing['updated_at'] ? 'saved ' . e($editing['updated_at']) : '' ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div id="editorwrap" class="editor split">
                    <textarea name="body" id="nbody" spellcheck="true" placeholder="# Heading&#10;&#10;Write in **markdown**. Link with [[Another Note]]."><?= e($editing['body']) ?></textarea>
                    <div id="preview" class="preview markdown"></div>
                </div>
            </form>

            <?php if (!$isNew): ?>
                <div class="row" style="margin-top:12px;gap:6px">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pin">
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                        <button class="ghost mini" type="submit"><?= !empty($editing['pinned']) ? 'Unpin' : 'Pin' ?></button></form>
                    <?php if (empty($editing['deleted_at'])): ?>
                        <form method="post" onsubmit="return confirm('Move to trash?')"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                            <button class="ghost mini" type="submit">Delete</button></form>
                    <?php else: ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                            <button class="mini" type="submit">Restore from trash</button></form>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" class="row" style="margin:0;gap:6px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                        <input type="file" name="file" style="min-width:150px">
                        <button class="ghost mini" type="submit">Attach</button>
                    </form>
                </div>

                <?php if ($atts): ?>
                    <div class="card" style="margin-top:12px">
                        <h2>Attachments</h2>
                        <?php foreach ($atts as $at): $isImg = preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $at['path']); ?>
                            <div class="item" style="padding:8px 10px">
                                <span class="grow" style="font-size:13px"><?= e($at['filename']) ?>
                                    <span class="muted">(<?= number_format($at['size'] / 1024, 0) ?> KB)</span></span>
                                <button class="ghost mini" type="button"
                                    data-copy="<?= $isImg ? '!' : '' ?>[<?= e($at['filename']) ?>](<?= e($at['path']) ?>)">Copy markdown</button>
                                <a class="pill" href="<?= e($at['path']) ?>" target="_blank">Open</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($links): ?>
                    <div class="card" style="margin-top:12px">
                        <h2>Linked references (<?= count($links) ?>)</h2>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ($links as $l): ?>
                                <a class="pill" href="notes.php?id=<?= (int)$l['id'] ?>"><?= e($l['title']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($revs): ?>
                    <div class="card" style="margin-top:12px">
                        <h2>Version history</h2>
                        <?php foreach ($revs as $r): ?>
                            <div class="item" style="padding:7px 10px">
                                <span class="grow" style="font-size:13px"><?= e($r['created_at']) ?>
                                    <span class="muted">· <?= (int)$r['len'] ?> chars</span></span>
                                <form method="post" onsubmit="return confirm('Restore this version? Current text is saved to history first.')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="restore_rev">
                                    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                                    <input type="hidden" name="rev_id" value="<?= (int)$r['id'] ?>">
                                    <button class="ghost mini" type="submit">Restore</button></form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty">Select a note on the left, or create a new one.</div>
        <?php endif; ?>
    </div>
</div>

<script>window.NOTE_TITLES = <?= json_encode(array_keys($titleMap), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/notes.js"></script>
<?php page_footer();
