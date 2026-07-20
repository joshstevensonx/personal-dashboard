<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

/* --------------------------------------------------------------- writes --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    $back = $_POST['back'] ?? 'tasks.php';

    if ($a === 'add_task') {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $due = trim($_POST['due_at'] ?? '');
            $st = $pdo->prepare(
                "INSERT INTO tasks (project_id, column_id, parent_id, title, notes, priority, due_at, recurrence, estimate_min)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([
                ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null,
                ($_POST['column_id'] ?? '') !== '' ? (int)$_POST['column_id'] : null,
                ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null,
                $title,
                trim($_POST['notes'] ?? ''),
                (int)($_POST['priority'] ?? 2),
                $due !== '' ? $due . ' 00:00:00' : null,
                trim($_POST['recurrence'] ?? ''),
                ($_POST['estimate_min'] ?? '') !== '' ? (int)$_POST['estimate_min'] : null,
            ]);
            $id = (int)$pdo->lastInsertId();
            sync_tags('task', $id, $_POST['tags'] ?? '');
            if (!empty($_POST['remind_at'])) {
                $pdo->prepare("INSERT INTO reminders (item_type,item_id,remind_at) VALUES ('task',?,?)")
                    ->execute([$id, $_POST['remind_at'] . ' 09:00:00']);
            }
            flash('Task added.');
        }
    } elseif ($a === 'complete') {
        $newId = complete_task((int)$_POST['id']);
        flash($newId ? 'Completed — next occurrence scheduled.' : 'Task completed.');
    } elseif ($a === 'reopen') {
        $pdo->prepare("UPDATE tasks SET status='open', completed_at=NULL, updated_at=datetime('now') WHERE id=?")
            ->execute([(int)$_POST['id']]);
    } elseif ($a === 'update') {
        $id = (int)$_POST['id'];
        $due = trim($_POST['due_at'] ?? '');
        $pdo->prepare("UPDATE tasks SET title=?, notes=?, priority=?, status=?, due_at=?, recurrence=?, estimate_min=?, project_id=?, updated_at=datetime('now') WHERE id=?")
            ->execute([
                trim($_POST['title'] ?? ''), trim($_POST['notes'] ?? ''),
                (int)($_POST['priority'] ?? 2),
                array_key_exists($_POST['status'] ?? '', STATUSES) ? $_POST['status'] : 'open',
                $due !== '' ? $due . ' 00:00:00' : null,
                trim($_POST['recurrence'] ?? ''),
                ($_POST['estimate_min'] ?? '') !== '' ? (int)$_POST['estimate_min'] : null,
                ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null,
                $id,
            ]);
        sync_tags('task', $id, $_POST['tags'] ?? '');
        flash('Task updated.');
    } elseif ($a === 'delete') {
        $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Task deleted.');
    } elseif ($a === 'move') {                       // kanban drag-drop
        $pdo->prepare("UPDATE tasks SET column_id=?, position=?, updated_at=datetime('now') WHERE id=?")
            ->execute([(int)$_POST['column_id'], (int)($_POST['position'] ?? 0), (int)$_POST['id']]);
        if (!empty($_POST['ajax'])) { http_response_code(204); exit; }
    } elseif ($a === 'add_project') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT INTO projects (name, color, view) VALUES (?,?,?)")
                ->execute([$name, $_POST['color'] ?? '#6ea8fe', $_POST['view'] ?? 'list']);
            ensure_project_columns((int)$pdo->lastInsertId());
            flash('Project created.');
        }
    } elseif ($a === 'delete_project') {
        $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Project deleted.');
        $back = 'tasks.php';
    }
    redirect($back);
}

/* ---------------------------------------------------------------- reads --- */
$projects = $pdo->query("SELECT * FROM projects WHERE archived=0 ORDER BY position, name")->fetchAll();
$projectId = isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null;
$view = $_GET['view'] ?? ($projectId ? 'board' : 'list');
$filter = $_GET['filter'] ?? 'open';
$tagFilter = trim($_GET['tag'] ?? '');

$where = [];
$args = [];
if ($projectId) { $where[] = 't.project_id = ?'; $args[] = $projectId; }
if ($filter === 'open')      { $where[] = "t.status IN ('open','doing')"; }
elseif ($filter === 'done')  { $where[] = "t.status = 'done'"; }
elseif ($filter === 'today') { $where[] = "t.status IN ('open','doing') AND date(t.due_at) <= date('now')"; }
elseif ($filter === 'week')  { $where[] = "t.status IN ('open','doing') AND date(t.due_at) <= date('now','+7 day')"; }
elseif ($filter === 'overdue') { $where[] = "t.status IN ('open','doing') AND date(t.due_at) < date('now')"; }
if ($tagFilter !== '') {
    $where[] = "t.id IN (SELECT tg.item_id FROM taggables tg JOIN tags g ON g.id=tg.tag_id
                         WHERE tg.item_type='task' AND g.name = ?)";
    $args[] = $tagFilter;
}
$sql = "SELECT t.*, p.name AS project_name, p.color AS project_color
        FROM tasks t LEFT JOIN projects p ON p.id = t.project_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY (t.due_at IS NULL), t.due_at, t.priority, t.position, t.id DESC";
$st = $pdo->prepare($sql);
$st->execute($args);
$tasks = $st->fetchAll();

$taskTags = tags_for('task', array_column($tasks, 'id'));
$tree = task_tree($tasks);
$roots = $tree[0] ?? [];

$counts = [
    'open'    => (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('open','doing')")->fetchColumn(),
    'overdue' => (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('open','doing') AND date(due_at) < date('now')")->fetchColumn(),
    'today'   => (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('open','doing') AND date(due_at) = date('now')")->fetchColumn(),
];

$editing = null;
if (isset($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM tasks WHERE id=?");
    $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch() ?: null;
}

$qs = function (array $over = []) use ($projectId, $view, $filter, $tagFilter) {
    $p = array_filter([
        'project' => $over['project'] ?? $projectId,
        'view'    => $over['view'] ?? $view,
        'filter'  => $over['filter'] ?? $filter,
        'tag'     => $over['tag'] ?? $tagFilter,
    ], fn($v) => $v !== null && $v !== '');
    return 'tasks.php' . ($p ? '?' . http_build_query($p) : '');
};

page_header('tasks.php');

/** Render one task row (recursive for subtasks). */
function render_task(array $t, array $tree, array $taskTags, string $back, int $depth = 0): void
{
    [$dueLabel, $dueCls] = due_state($t['due_at']);
    $pr = PRIORITIES[$t['priority']] ?? PRIORITIES[2];
    $done = in_array($t['status'], ['done', 'cancelled'], true);
    $kids = $tree[$t['id']] ?? [];
    echo "<div class='item' style='margin-left:" . ($depth * 26) . "px'>";
    echo "<form method='post' style='display:flex'>" . csrf_field()
       . "<input type='hidden' name='action' value='" . ($done ? 'reopen' : 'complete') . "'>"
       . "<input type='hidden' name='id' value='" . (int)$t['id'] . "'>"
       . "<input type='hidden' name='back' value='" . e($back) . "'>"
       . "<button class='ghost mini' type='submit' title='" . ($done ? 'Reopen' : 'Complete') . "'>"
       . ($done ? '↺' : '✓') . "</button></form>";

    echo "<div class='grow'><div class='title" . ($done ? ' done' : '') . "'>" . e($t['title']) . "</div><div class='meta'>";
    echo "<span class='pill " . $pr['class'] . "'>" . $pr['short'] . "</span> ";
    if ($t['project_name']) echo "<span class='pill'>" . e($t['project_name']) . "</span> ";
    if ($dueLabel) echo "<span class='pill $dueCls'>" . e($dueLabel) . "</span> ";
    if (!empty($t['recurrence'])) echo "<span class='pill'>↻ " . e(recurrence_label($t['recurrence'])) . "</span> ";
    if (!empty($t['estimate_min'])) echo "<span class='pill'>" . (int)$t['estimate_min'] . "m</span> ";
    if (!empty($taskTags[$t['id']])) echo "<span class='muted'>#" . e($taskTags[$t['id']]) . "</span> ";
    if (!empty($t['notes'])) echo "<br><span class='muted'>" . e(mb_strimwidth($t['notes'], 0, 120, '…')) . "</span>";
    echo "</div></div>";

    echo "<a class='ghost mini' style='padding:5px 10px;border:1px solid var(--line);border-radius:9px' href='"
       . e($back . (strpos($back, '?') !== false ? '&' : '?') . 'edit=' . (int)$t['id']) . "'>Edit</a>";
    echo "<form method='post' onsubmit=\"return confirm('Delete this task and its subtasks?')\">" . csrf_field()
       . "<input type='hidden' name='action' value='delete'><input type='hidden' name='id' value='" . (int)$t['id'] . "'>"
       . "<input type='hidden' name='back' value='" . e($back) . "'>"
       . "<button class='ghost mini' type='submit'>✕</button></form>";
    echo "</div>";

    foreach ($kids as $k) {
        render_task($k, $tree, $taskTags, $back, $depth + 1);
    }
}
?>
<h1>Tasks</h1>
<p class="sub">
    <?= $counts['open'] ?> open ·
    <span class="<?= $counts['overdue'] ? 'muted' : 'muted' ?>"><?= $counts['overdue'] ?> overdue</span> ·
    <?= $counts['today'] ?> due today
</p>

<!-- quick add -->
<form class="row" method="post" id="new">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_task">
    <input type="hidden" name="back" value="<?= e($qs()) ?>">
    <div class="field" style="flex:1;min-width:220px"><label>New task</label>
        <input name="title" placeholder="What needs doing?" required></div>
    <div class="field"><label>Priority</label>
        <select name="priority">
            <?php foreach (PRIORITIES as $k => $p): ?>
                <option value="<?= $k ?>" <?= $k === 2 ? 'selected' : '' ?>><?= e($p['label']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Due</label><input type="date" name="due_at"></div>
    <div class="field"><label>Repeat</label>
        <select name="recurrence">
            <?php foreach (recurrence_options() as $v => $l): ?>
                <option value="<?= e($v) ?>"><?= e($l) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Project</label>
        <select name="project_id">
            <option value="">— none —</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Tags</label><input name="tags" placeholder="work, admin" style="min-width:110px"></div>
    <button type="submit">Add</button>
</form>

<!-- filters -->
<div class="row" style="margin-bottom:16px;align-items:center">
    <?php foreach (['open' => 'Open', 'today' => 'Today', 'week' => 'Next 7 days', 'overdue' => 'Overdue', 'done' => 'Done', 'all' => 'All'] as $f => $l): ?>
        <a class="pill <?= $filter === $f ? 'ok' : '' ?>" href="<?= e($qs(['filter' => $f])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <span style="width:14px"></span>
    <a class="pill <?= $view === 'list' ? 'ok' : '' ?>" href="<?= e($qs(['view' => 'list'])) ?>">List</a>
    <a class="pill <?= $view === 'board' ? 'ok' : '' ?>" href="<?= e($qs(['view' => 'board'])) ?>">Board</a>
    <?php if ($tagFilter !== ''): ?>
        <a class="pill danger" href="<?= e($qs(['tag' => ''])) ?>">#<?= e($tagFilter) ?> ✕</a>
    <?php endif; ?>
</div>

<!-- projects strip -->
<div class="row" style="margin-bottom:18px;align-items:center">
    <a class="pill <?= $projectId === null ? 'ok' : '' ?>" href="<?= e($qs(['project' => null])) ?>">All projects</a>
    <?php foreach ($projects as $p): ?>
        <a class="pill <?= $projectId === (int)$p['id'] ? 'ok' : '' ?>" href="<?= e($qs(['project' => (int)$p['id']])) ?>"><?= e($p['name']) ?></a>
    <?php endforeach; ?>
    <form method="post" class="row" style="margin:0;gap:6px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_project">
        <input type="hidden" name="back" value="<?= e($qs()) ?>">
        <input name="name" placeholder="New project" style="min-width:130px">
        <button class="ghost mini" type="submit">+ Project</button>
    </form>
</div>

<?php if ($editing): ?>
    <div class="card" style="margin-bottom:20px">
        <h2>Edit task</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <input type="hidden" name="back" value="<?= e($qs()) ?>">
            <div class="row" style="margin-bottom:8px">
                <div class="field" style="flex:1;min-width:200px"><label>Title</label>
                    <input name="title" value="<?= e($editing['title']) ?>" required></div>
                <div class="field"><label>Status</label>
                    <select name="status">
                        <?php foreach (STATUSES as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $editing['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Priority</label>
                    <select name="priority">
                        <?php foreach (PRIORITIES as $k => $p): ?>
                            <option value="<?= $k ?>" <?= (int)$editing['priority'] === $k ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Due</label>
                    <input type="date" name="due_at" value="<?= e($editing['due_at'] ? substr($editing['due_at'], 0, 10) : '') ?>"></div>
                <div class="field"><label>Repeat</label>
                    <select name="recurrence">
                        <?php foreach (recurrence_options() as $v => $l): ?>
                            <option value="<?= e($v) ?>" <?= (string)$editing['recurrence'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Estimate (min)</label>
                    <input type="number" name="estimate_min" min="0" style="min-width:90px" value="<?= e((string)$editing['estimate_min']) ?>"></div>
                <div class="field"><label>Project</label>
                    <select name="project_id">
                        <option value="">— none —</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$editing['project_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Tags</label>
                    <input name="tags" value="<?= e($taskTags[$editing['id']] ?? '') ?>"></div>
            </div>
            <div class="field"><label>Notes</label><textarea name="notes"><?= e($editing['notes']) ?></textarea></div>
            <div style="margin-top:10px;display:flex;gap:8px">
                <button type="submit">Save</button>
                <a class="pill" href="<?= e($qs()) ?>">Cancel</a>
            </div>
        </form>
        <form method="post" class="row" style="margin:14px 0 0;border-top:1px solid var(--line);padding-top:14px">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_task">
            <input type="hidden" name="parent_id" value="<?= (int)$editing['id'] ?>">
            <input type="hidden" name="project_id" value="<?= e((string)$editing['project_id']) ?>">
            <input type="hidden" name="back" value="<?= e($qs()) ?>">
            <div class="field" style="flex:1;min-width:200px"><label>Add subtask</label>
                <input name="title" placeholder="Subtask title"></div>
            <button class="ghost" type="submit">Add subtask</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($view === 'board'): ?>
    <?php
    $boardProject = $projectId;
    if ($boardProject) { ensure_project_columns($boardProject); }
    $cols = $boardProject
        ? $pdo->prepare("SELECT * FROM board_columns WHERE project_id=? ORDER BY position")
        : null;
    if ($cols) { $cols->execute([$boardProject]); $columns = $cols->fetchAll(); }
    else { $columns = []; }
    ?>
    <?php if (!$boardProject): ?>
        <div class="empty">Pick a project above to use the board view — columns belong to a project.</div>
    <?php else: ?>
        <div class="board" id="board">
            <?php foreach ($columns as $col): ?>
                <div class="col" data-col="<?= (int)$col['id'] ?>">
                    <h3><?= e($col['name']) ?>
                        <span class="pill"><?= count(array_filter($tasks, fn($t) => (int)$t['column_id'] === (int)$col['id'])) ?></span>
                    </h3>
                    <div class="dropzone" data-col="<?= (int)$col['id'] ?>">
                        <?php foreach ($tasks as $t): if ((int)$t['column_id'] !== (int)$col['id']) continue;
                            [$dl, $dc] = due_state($t['due_at']); $pr = PRIORITIES[$t['priority']] ?? PRIORITIES[2]; ?>
                            <div class="kcard" draggable="true" data-id="<?= (int)$t['id'] ?>">
                                <div class="ktitle <?= in_array($t['status'], ['done','cancelled'], true) ? 'done' : '' ?>"><?= e($t['title']) ?></div>
                                <div class="meta">
                                    <span class="pill <?= $pr['class'] ?>"><?= $pr['short'] ?></span>
                                    <?php if ($dl): ?><span class="pill <?= $dc ?>"><?= e($dl) ?></span><?php endif; ?>
                                    <?php if (!empty($taskTags[$t['id']])): ?><span class="muted">#<?= e($taskTags[$t['id']]) ?></span><?php endif; ?>
                                </div>
                                <a class="klink" href="<?= e($qs()) ?>&edit=<?= (int)$t['id'] ?>">Edit</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php $unassigned = array_filter($tasks, fn($t) => !$t['column_id']); ?>
            <?php if ($unassigned): ?>
                <div class="col">
                    <h3>Unassigned <span class="pill"><?= count($unassigned) ?></span></h3>
                    <div class="dropzone" data-col="0">
                        <?php foreach ($unassigned as $t): ?>
                            <div class="kcard" draggable="true" data-id="<?= (int)$t['id'] ?>">
                                <div class="ktitle"><?= e($t['title']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <p class="muted" style="font-size:13px;margin-top:10px">Drag cards between columns — moves save automatically.</p>
    <?php endif; ?>

<?php else: ?>
    <div class="list">
        <?php foreach ($roots as $t) { render_task($t, $tree, $taskTags, $qs()); } ?>
        <?php if (!$roots): ?><div class="empty">No tasks match this filter.</div><?php endif; ?>
    </div>
<?php endif; ?>

<?php $tagList = all_tags(); if ($tagList): ?>
    <h2 style="margin:28px 0 10px;font-size:14px" class="muted">TAGS</h2>
    <div class="row">
        <?php foreach ($tagList as $tg): if (!$tg['c']) continue; ?>
            <a class="pill <?= $tagFilter === $tg['name'] ? 'ok' : '' ?>" href="<?= e($qs(['tag' => $tg['name']])) ?>">#<?= e($tg['name']) ?> <?= (int)$tg['c'] ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script src="assets/tasks.js"></script>
<?php page_footer();
