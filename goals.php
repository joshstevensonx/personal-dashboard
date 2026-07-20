<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/productivity.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'add') {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $pdo->prepare("INSERT INTO goals (parent_id, title, description, target_value, unit, due_date) VALUES (?,?,?,?,?,?)")
                ->execute([
                    ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null,
                    $title, trim($_POST['description'] ?? ''),
                    ($_POST['target_value'] ?? '') !== '' ? (float)$_POST['target_value'] : null,
                    trim($_POST['unit'] ?? ''),
                    ($_POST['due_date'] ?? '') !== '' ? $_POST['due_date'] : null,
                ]);
            flash('Goal added.');
        }
    } elseif ($a === 'progress') {
        $gid = (int)$_POST['goal_id'];
        $val = (float)($_POST['value'] ?? 0);
        if ($val != 0.0) {
            $pdo->prepare("INSERT INTO goal_progress (goal_id, date, value, note) VALUES (?,?,?,?)")
                ->execute([$gid, $_POST['date'] ?: date('Y-m-d'), $val, trim($_POST['note'] ?? '')]);
            recalc_goal($gid);
            flash('Progress logged.');
        }
    } elseif ($a === 'status') {
        $st = in_array($_POST['status'] ?? '', ['active','done','paused','dropped'], true) ? $_POST['status'] : 'active';
        $pdo->prepare("UPDATE goals SET status=? WHERE id=?")->execute([$st, (int)$_POST['id']]);
    } elseif ($a === 'delete') {
        $pdo->prepare("DELETE FROM goals WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Goal deleted.');
    } elseif ($a === 'del_progress') {
        $gid = (int)$_POST['goal_id'];
        $pdo->prepare("DELETE FROM goal_progress WHERE id=?")->execute([(int)$_POST['id']]);
        recalc_goal($gid);
    }
    redirect('goals.php');
}

$all = $pdo->query("SELECT * FROM goals ORDER BY (status<>'active'), (due_date IS NULL), due_date, id")->fetchAll();
$byParent = [];
foreach ($all as $g) { $byParent[$g['parent_id'] ?? 0][] = $g; }
$roots = $byParent[0] ?? [];

page_header('goals.php');

function render_goal(array $g, array $byParent, PDO $pdo, int $depth = 0): void
{
    $pct = goal_percent($g);
    $kids = $byParent[$g['id']] ?? [];
    $stCls = ['active' => '', 'done' => 'ok', 'paused' => 'warn', 'dropped' => 'danger'][$g['status']] ?? '';
    $overdue = $g['due_date'] && $g['status'] === 'active' && strtotime($g['due_date']) < strtotime('today');
    ?>
    <div class="card" style="margin-bottom:12px;margin-left:<?= $depth * 22 ?>px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <strong style="font-size:16px"><?= e($g['title']) ?></strong>
            <span class="pill <?= $stCls ?>"><?= e($g['status']) ?></span>
            <?php if ($g['due_date']): ?>
                <span class="pill <?= $overdue ? 'danger' : '' ?>">due <?= e(date('j M Y', strtotime($g['due_date']))) ?></span>
            <?php endif; ?>
            <span style="flex:1"></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <select name="status" onchange="this.form.submit()" style="min-width:100px">
                    <?php foreach (['active','done','paused','dropped'] as $s): ?>
                        <option value="<?= $s ?>" <?= $g['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select></form>
            <form method="post" onsubmit="return confirm('Delete this goal and its sub-goals?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button class="ghost mini" type="submit">✕</button></form>
        </div>

        <?php if ($g['description']): ?><div class="muted" style="font-size:13px"><?= e($g['description']) ?></div><?php endif; ?>

        <?php if ($g['target_value'] > 0): ?>
            <div style="background:var(--panel2);border-radius:999px;height:9px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:var(--accent)"></div>
            </div>
            <div class="muted" style="font-size:13px">
                <?= rtrim(rtrim(number_format((float)$g['current_value'], 2, '.', ''), '0'), '.') ?>
                / <?= rtrim(rtrim(number_format((float)$g['target_value'], 2, '.', ''), '0'), '.') ?>
                <?= e($g['unit']) ?> · <strong><?= $pct ?>%</strong>
            </div>
        <?php endif; ?>

        <form class="row" method="post" style="margin:6px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="progress">
            <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
            <div class="field"><label>Log progress</label>
                <input type="number" step="any" name="value" placeholder="+5" style="min-width:90px"></div>
            <div class="field"><label>Date</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="field" style="flex:1;min-width:120px"><label>Note</label><input name="note"></div>
            <button class="ghost" type="submit">Log</button>
        </form>

        <?php
        $ps = $pdo->prepare("SELECT * FROM goal_progress WHERE goal_id=? ORDER BY date DESC, id DESC LIMIT 5");
        $ps->execute([$g['id']]);
        $entries = $ps->fetchAll();
        if ($entries): ?>
            <div class="muted" style="font-size:12.5px">
                <?php foreach ($entries as $p): ?>
                    <div style="display:flex;gap:8px;align-items:center;padding:2px 0">
                        <span><?= e($p['date']) ?></span>
                        <strong><?= $p['value'] > 0 ? '+' : '' ?><?= rtrim(rtrim(number_format((float)$p['value'], 2, '.', ''), '0'), '.') ?></strong>
                        <span class="grow"><?= e($p['note']) ?></span>
                        <form method="post" style="margin:0"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="del_progress">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                            <button class="ghost mini" type="submit" style="padding:1px 6px">✕</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    foreach ($kids as $k) { render_goal($k, $byParent, $pdo, $depth + 1); }
}
?>
<h1>Goals</h1>
<p class="sub">Set a target, log progress against it, and break big goals into sub-goals.</p>

<form class="row" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field" style="flex:1;min-width:180px"><label>Goal</label>
        <input name="title" placeholder="Read 24 books" required></div>
    <div class="field"><label>Target</label><input type="number" step="any" name="target_value" placeholder="24" style="min-width:90px"></div>
    <div class="field"><label>Unit</label><input name="unit" placeholder="books" style="min-width:90px"></div>
    <div class="field"><label>Due</label><input type="date" name="due_date"></div>
    <div class="field"><label>Sub-goal of</label>
        <select name="parent_id">
            <option value="">— top level —</option>
            <?php foreach ($all as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e(mb_strimwidth($g['title'], 0, 30, '…')) ?></option><?php endforeach; ?>
        </select></div>
    <button type="submit">Add goal</button>
</form>

<?php foreach ($roots as $g) { render_goal($g, $byParent, $pdo); } ?>
<?php if (!$roots): ?><div class="empty">No goals yet.</div><?php endif; ?>

<?php page_footer();
