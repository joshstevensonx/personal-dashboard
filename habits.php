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
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT INTO habits (name, color, cadence, target) VALUES (?,?,?,?)")
                ->execute([
                    $name, $_POST['color'] ?? '#54d19a',
                    array_key_exists($_POST['cadence'] ?? '', CADENCES) ? $_POST['cadence'] : 'daily',
                    max(1, (int)($_POST['target'] ?? 1)),
                ]);
            flash('Habit added.');
        }
    } elseif ($a === 'toggle') {
        $hid = (int)$_POST['habit_id'];
        $date = $_POST['date'] ?? date('Y-m-d');
        $ex = $pdo->prepare("SELECT id, count FROM habit_entries WHERE habit_id=? AND date=?");
        $ex->execute([$hid, $date]);
        if ($row = $ex->fetch()) {
            $pdo->prepare("DELETE FROM habit_entries WHERE id=?")->execute([$row['id']]);
        } else {
            $pdo->prepare("INSERT INTO habit_entries (habit_id, date, count) VALUES (?,?,1)")
                ->execute([$hid, $date]);
        }
        if (!empty($_POST['ajax'])) { http_response_code(204); exit; }
    } elseif ($a === 'archive') {
        $pdo->prepare("UPDATE habits SET archived = 1 - archived WHERE id=?")->execute([(int)$_POST['id']]);
    } elseif ($a === 'delete') {
        $pdo->prepare("DELETE FROM habits WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Habit deleted.');
    }
    redirect('habits.php');
}

$habits = $pdo->query("SELECT * FROM habits WHERE archived=0 ORDER BY id")->fetchAll();
$archived = $pdo->query("SELECT * FROM habits WHERE archived=1 ORDER BY name")->fetchAll();
$today = date('Y-m-d');

page_header('habits.php');
?>
<h1>Habits</h1>
<p class="sub">Tick each day you do it. Streaks count consecutive days — a day isn't broken until it's actually missed.</p>

<form class="row" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field" style="flex:1;min-width:180px"><label>New habit</label>
        <input name="name" placeholder="Read 20 minutes" required></div>
    <div class="field"><label>Cadence</label>
        <select name="cadence">
            <?php foreach (CADENCES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label>Target / week</label><input type="number" name="target" value="7" min="1" max="7" style="min-width:80px"></div>
    <div class="field"><label>Colour</label><input type="color" name="color" value="#54d19a"></div>
    <button type="submit">Add habit</button>
</form>

<?php if (!$habits): ?>
    <div class="empty">No habits yet. Add one above.</div>
<?php endif; ?>

<?php foreach ($habits as $h):
    $s = habit_streaks((int)$h['id']);
    $hist = habit_history((int)$h['id'], 90);
    $doneToday = isset($hist[$today]);
    $last7 = 0;
    for ($i = 0; $i < 7; $i++) { if (isset($hist[date('Y-m-d', strtotime("-$i day"))])) $last7++; }
    $color = $h['color'] ?: '#54d19a';
?>
    <div class="card" style="margin-bottom:14px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="width:12px;height:12px;border-radius:50%;background:<?= e($color) ?>"></span>
            <strong style="font-size:16px"><?= e($h['name']) ?></strong>
            <span class="pill <?= $s['current'] > 0 ? 'ok' : '' ?>">🔥 <?= $s['current'] ?> day streak</span>
            <span class="pill">best <?= $s['longest'] ?></span>
            <span class="pill"><?= $last7 ?>/<?= (int)$h['target'] ?> this week</span>
            <span class="pill"><?= $s['total'] ?> total</span>
            <span style="flex:1"></span>
            <form method="post" class="habit-toggle" data-habit="<?= (int)$h['id'] ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
                <input type="hidden" name="habit_id" value="<?= (int)$h['id'] ?>">
                <input type="hidden" name="date" value="<?= $today ?>">
                <button type="submit" class="<?= $doneToday ? '' : 'ghost' ?>"><?= $doneToday ? '✓ Done today' : 'Mark today' ?></button>
            </form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
                <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                <button class="ghost mini" type="submit">Archive</button></form>
            <form method="post" onsubmit="return confirm('Delete this habit and all its history?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                <button class="ghost mini" type="submit">✕</button></form>
        </div>

        <!-- 90-day heatmap -->
        <div style="display:flex;gap:2px;flex-wrap:wrap;margin-top:6px">
            <?php for ($i = 89; $i >= 0; $i--):
                $d = date('Y-m-d', strtotime("-$i day"));
                $on = isset($hist[$d]); ?>
                <span title="<?= e($d . ($on ? ' — done' : '')) ?>"
                      style="width:9px;height:9px;border-radius:2px;background:<?= $on ? e($color) : 'var(--line)' ?>;opacity:<?= $on ? 1 : .55 ?>"></span>
            <?php endfor; ?>
        </div>
        <div class="muted" style="font-size:12px">Last 90 days</div>
    </div>
<?php endforeach; ?>

<?php if ($archived): ?>
    <h2 style="margin:26px 0 10px;font-size:14px" class="muted">ARCHIVED</h2>
    <div class="list">
        <?php foreach ($archived as $h): ?>
            <div class="item">
                <div class="grow"><span class="muted"><?= e($h['name']) ?></span></div>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                    <button class="ghost mini" type="submit">Restore</button></form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
// Toggle today's tick without a full page reload.
document.addEventListener('submit', function (ev) {
    var f = ev.target.closest('.habit-toggle');
    if (!f) return;
    ev.preventDefault();
    var body = new URLSearchParams(new FormData(f));
    body.set('ajax', '1');
    fetch('habits.php', { method: 'POST', body: body.toString(), credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
      .then(function () { location.reload(); })
      .catch(function () { f.submit(); });
});
</script>
<?php page_footer();
