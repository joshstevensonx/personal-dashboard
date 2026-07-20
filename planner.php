<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/productivity.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

$date = $_GET['d'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    $d = $_POST['date'] ?? $date;
    if ($a === 'save_plan') {
        save_daily_plan($d, [
            'intention' => trim($_POST['intention'] ?? ''),
            'plan'      => trim($_POST['plan'] ?? ''),
            'review'    => trim($_POST['review'] ?? ''),
            'energy'    => $_POST['energy'] ?? '',
            'mood'      => $_POST['mood'] ?? '',
        ]);
        flash('Day saved.');
    } elseif ($a === 'complete_task') {
        complete_task((int)$_POST['id']);
    } elseif ($a === 'schedule_today') {
        $pdo->prepare("UPDATE tasks SET due_at = ? WHERE id = ?")->execute([$d . ' 00:00:00', (int)$_POST['id']]);
    }
    redirect('planner.php?d=' . urlencode($d));
}

$plan = daily_plan($date);
$isToday = $date === date('Y-m-d');
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));

$due = $pdo->prepare("SELECT * FROM tasks WHERE date(due_at) = ? ORDER BY priority, position");
$due->execute([$date]);
$dueTasks = $due->fetchAll();

$overdue = $pdo->query("SELECT * FROM tasks WHERE status IN ('open','doing') AND due_at IS NOT NULL AND date(due_at) < date('now') ORDER BY due_at LIMIT 10")->fetchAll();
$unscheduled = $pdo->query("SELECT * FROM tasks WHERE status IN ('open','doing') AND due_at IS NULL ORDER BY priority, id DESC LIMIT 10")->fetchAll();

$ev = $pdo->prepare("SELECT * FROM events WHERE date(start_at) = ? ORDER BY start_at");
$ev->execute([$date]);
$events = $ev->fetchAll();

$habits = $pdo->query("SELECT * FROM habits WHERE archived=0 ORDER BY id")->fetchAll();
$hdone = [];
$hst = $pdo->prepare("SELECT habit_id FROM habit_entries WHERE date = ?");
$hst->execute([$date]);
foreach ($hst as $r) { $hdone[(int)$r['habit_id']] = true; }

$fs = $pdo->prepare("SELECT COALESCE(SUM(duration_sec),0) FROM focus_sessions WHERE date(started_at)=? AND kind<>'break' AND ended_at IS NOT NULL");
$fs->execute([$date]);
$focusSecs = (int)$fs->fetchColumn();

$doneCount = count(array_filter($dueTasks, fn($t) => $t['status'] === 'done'));

page_header('planner.php');
?>
<h1>Daily plan</h1>
<div class="row" style="align-items:center;margin-bottom:16px">
    <a class="pill" href="?d=<?= e($prev) ?>">←</a>
    <strong style="font-size:17px"><?= e(date('l, j F Y', strtotime($date))) ?></strong>
    <a class="pill" href="?d=<?= e($next) ?>">→</a>
    <?php if (!$isToday): ?><a class="pill ok" href="planner.php">Today</a><?php endif; ?>
    <span style="flex:1"></span>
    <span class="pill"><?= $doneCount ?>/<?= count($dueTasks) ?> tasks</span>
    <span class="pill"><?= e(hhmm($focusSecs)) ?> focused</span>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">

    <div>
        <form method="post" class="card" style="margin-bottom:14px">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_plan">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <h2>Intention</h2>
            <input name="intention" value="<?= e($plan['intention'] ?? '') ?>" placeholder="The one thing that would make today a win">
            <h2 style="margin-top:6px">Plan / time blocks</h2>
            <textarea name="plan" placeholder="09:00 deep work — report&#10;11:00 email&#10;14:00 gym" style="min-height:120px"><?= e($plan['plan'] ?? '') ?></textarea>
            <h2 style="margin-top:6px">Evening review</h2>
            <textarea name="review" placeholder="What went well? What got in the way?" style="min-height:80px"><?= e($plan['review'] ?? '') ?></textarea>
            <div class="row" style="margin:6px 0 0">
                <div class="field"><label>Energy (1–5)</label>
                    <input type="number" name="energy" min="1" max="5" value="<?= e((string)($plan['energy'] ?? '')) ?>" style="min-width:80px"></div>
                <div class="field"><label>Mood (1–5)</label>
                    <input type="number" name="mood" min="1" max="5" value="<?= e((string)($plan['mood'] ?? '')) ?>" style="min-width:80px"></div>
                <div class="field"><label>&nbsp;</label><button type="submit">Save day</button></div>
            </div>
        </form>

        <?php if ($habits): ?>
            <div class="card">
                <h2>Habits for this day</h2>
                <div class="list">
                    <?php foreach ($habits as $h): $on = isset($hdone[(int)$h['id']]); ?>
                        <form method="post" action="habits.php" style="display:flex;align-items:center;gap:10px;
                             background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:8px 11px">
                            <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="habit_id" value="<?= (int)$h['id'] ?>">
                            <input type="hidden" name="date" value="<?= e($date) ?>">
                            <span style="width:10px;height:10px;border-radius:50%;background:<?= e($h['color'] ?: '#54d19a') ?>"></span>
                            <span class="grow" style="<?= $on ? 'opacity:.6;text-decoration:line-through' : '' ?>"><?= e($h['name']) ?></span>
                            <button class="<?= $on ? '' : 'ghost' ?> mini" type="submit"><?= $on ? '✓' : 'Mark' ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($events): ?>
            <div class="card" style="margin-bottom:14px">
                <h2>Schedule</h2>
                <div class="list">
                    <?php foreach ($events as $evr): ?>
                        <div class="item" style="padding:8px 11px">
                            <span class="pill"><?= e($evr['all_day'] ? 'all day' : date('H:i', strtotime($evr['start_at']))) ?></span>
                            <span class="grow"><?= e($evr['title']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:14px">
            <h2>Due this day</h2>
            <div class="list">
                <?php foreach ($dueTasks as $t): $pr = PRIORITIES[$t['priority']] ?? PRIORITIES[2];
                    $done = in_array($t['status'], ['done','cancelled'], true); ?>
                    <div class="item" style="padding:8px 11px">
                        <?php if (!$done): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="complete_task">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="date" value="<?= e($date) ?>">
                                <button class="ghost mini" type="submit">✓</button></form>
                        <?php else: ?><span class="pill ok">✓</span><?php endif; ?>
                        <span class="grow <?= $done ? 'done' : '' ?>"><?= e($t['title']) ?></span>
                        <span class="pill <?= $pr['class'] ?>"><?= $pr['short'] ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$dueTasks): ?><div class="muted" style="font-size:13px">Nothing due.</div><?php endif; ?>
            </div>
        </div>

        <?php if ($overdue): ?>
            <div class="card" style="margin-bottom:14px">
                <h2>Overdue</h2>
                <div class="list">
                    <?php foreach ($overdue as $t): ?>
                        <div class="item" style="padding:8px 11px">
                            <span class="grow"><?= e($t['title']) ?></span>
                            <span class="pill danger"><?= e(substr($t['due_at'], 0, 10)) ?></span>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="schedule_today">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="date" value="<?= e($date) ?>">
                                <button class="ghost mini" type="submit">Move here</button></form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($unscheduled): ?>
            <div class="card">
                <h2>Unscheduled — pull into today</h2>
                <div class="list">
                    <?php foreach ($unscheduled as $t): ?>
                        <div class="item" style="padding:8px 11px">
                            <span class="grow"><?= e($t['title']) ?></span>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="schedule_today">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="date" value="<?= e($date) ?>">
                                <button class="ghost mini" type="submit">+ Add</button></form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php page_footer();
