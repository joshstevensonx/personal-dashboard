<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/productivity.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'log') {
        // Sessions are timed in the browser and posted here on completion.
        $kind = array_key_exists($_POST['kind'] ?? '', FOCUS_KINDS) ? $_POST['kind'] : 'pomodoro';
        $dur = max(0, (int)($_POST['duration_sec'] ?? 0));
        $started = $_POST['started_at'] ?? date('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO focus_sessions (task_id, kind, label, started_at, ended_at, duration_sec, interruptions, notes)
             VALUES (?,?,?,?,datetime('now'),?,?,?)"
        )->execute([
            ($_POST['task_id'] ?? '') !== '' ? (int)$_POST['task_id'] : null,
            $kind, trim($_POST['label'] ?? ''), $started, $dur,
            (int)($_POST['interruptions'] ?? 0), trim($_POST['notes'] ?? ''),
        ]);
        if (!empty($_POST['ajax'])) { http_response_code(204); exit; }
        flash('Session logged.');
    } elseif ($a === 'manual') {
        $mins = max(1, (int)($_POST['minutes'] ?? 25));
        $pdo->prepare(
            "INSERT INTO focus_sessions (task_id, kind, label, started_at, ended_at, duration_sec, notes)
             VALUES (?,?,?, datetime('now', ?), datetime('now'), ?, ?)"
        )->execute([
            ($_POST['task_id'] ?? '') !== '' ? (int)$_POST['task_id'] : null,
            array_key_exists($_POST['kind'] ?? '', FOCUS_KINDS) ? $_POST['kind'] : 'deep',
            trim($_POST['label'] ?? ''), "-$mins minute", $mins * 60, trim($_POST['notes'] ?? ''),
        ]);
        flash('Session added.');
    } elseif ($a === 'delete') {
        $pdo->prepare("DELETE FROM focus_sessions WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Session removed.');
    }
    redirect('focus.php');
}

$openTasks = $pdo->query("SELECT id, title FROM tasks WHERE status IN ('open','doing') ORDER BY (due_at IS NULL), due_at LIMIT 60")->fetchAll();
$week = focus_totals('-7 day');
$today = focus_totals('-0 day');
$byDay = focus_by_day(14);
$recent = $pdo->query(
    "SELECT f.*, t.title AS task_title FROM focus_sessions f
     LEFT JOIN tasks t ON t.id = f.task_id
     WHERE f.ended_at IS NOT NULL ORDER BY f.started_at DESC LIMIT 15"
)->fetchAll();

$weekSecs = array_sum(array_map(fn($k) => $k === 'break' ? 0 : ($week[$k]['secs'] ?? 0), array_keys($week)));
$todaySecs = array_sum(array_map(fn($k) => $k === 'break' ? 0 : ($today[$k]['secs'] ?? 0), array_keys($today)));
$maxDay = $byDay ? max($byDay) : 0;

page_header('focus.php');
?>
<h1>Focus</h1>
<p class="sub">Pomodoro and deep work timer. Sessions log automatically when the timer finishes.</p>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:22px">
    <div class="card" style="align-items:center;text-align:center">
        <h2>Timer</h2>
        <div id="clock" style="font-size:52px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.1">25:00</div>
        <div id="phase" class="muted" style="font-size:13px">Pomodoro · ready</div>
        <div class="row" style="justify-content:center;margin:6px 0 0;gap:6px">
            <button id="startbtn" type="button">Start</button>
            <button id="pausebtn" class="ghost" type="button">Pause</button>
            <button id="resetbtn" class="ghost" type="button">Reset</button>
        </div>
        <div class="row" style="justify-content:center;margin:0;gap:6px">
            <button class="ghost mini preset" type="button" data-min="25" data-kind="pomodoro">25m Pomodoro</button>
            <button class="ghost mini preset" type="button" data-min="50" data-kind="deep">50m Deep</button>
            <button class="ghost mini preset" type="button" data-min="90" data-kind="deep">90m Deep</button>
            <button class="ghost mini preset" type="button" data-min="5"  data-kind="break">5m Break</button>
        </div>
        <div class="field" style="width:100%;margin-top:8px">
            <label>Working on (optional)</label>
            <select id="taskpick">
                <option value="">— no task —</option>
                <?php foreach ($openTasks as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= e(mb_strimwidth($t['title'], 0, 44, '…')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="width:100%">
            <label>Label (optional)</label><input id="label" placeholder="e.g. write report">
        </div>
        <div class="muted" style="font-size:12px">Interruptions: <span id="intr">0</span>
            <button class="ghost mini" type="button" id="intrbtn">+1</button></div>
    </div>

    <div class="card">
        <h2>Today</h2>
        <div class="big"><?= e(hhmm($todaySecs)) ?></div>
        <ul>
            <?php foreach (FOCUS_KINDS as $k => $l): if ($k === 'break') continue; ?>
                <li><span class="grow"><?= e($l) ?></span><span class="pill"><?= (int)($today[$k]['sessions'] ?? 0) ?> · <?= e(hhmm((int)($today[$k]['secs'] ?? 0))) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <div class="muted" style="font-size:13px">This week: <strong><?= e(hhmm($weekSecs)) ?></strong></div>
    </div>

    <div class="card">
        <h2>Last 14 days</h2>
        <div style="display:flex;align-items:flex-end;gap:3px;height:96px;margin-top:6px">
            <?php
            for ($i = 13; $i >= 0; $i--):
                $d = date('Y-m-d', strtotime("-$i day"));
                $s = $byDay[$d] ?? 0;
                $h = $maxDay > 0 ? max(3, (int)round(($s / $maxDay) * 88)) : 3;
            ?>
                <div title="<?= e($d . ' — ' . hhmm($s)) ?>"
                     style="flex:1;height:<?= $h ?>px;border-radius:3px 3px 0 0;background:<?= $s > 0 ? 'var(--accent)' : 'var(--line)' ?>"></div>
            <?php endfor; ?>
        </div>
        <div class="muted" style="font-size:12px;display:flex;justify-content:space-between">
            <span>14d ago</span><span>today</span>
        </div>
    </div>
</div>

<h2 style="margin:0 0 10px">Log a session manually</h2>
<form class="row" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="manual">
    <div class="field"><label>Kind</label>
        <select name="kind">
            <?php foreach (FOCUS_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'deep' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label>Minutes</label><input type="number" name="minutes" value="25" min="1" max="600" style="min-width:90px"></div>
    <div class="field" style="flex:1;min-width:150px"><label>Label</label><input name="label" placeholder="what you worked on"></div>
    <div class="field"><label>Task</label>
        <select name="task_id">
            <option value="">— none —</option>
            <?php foreach ($openTasks as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e(mb_strimwidth($t['title'], 0, 34, '…')) ?></option><?php endforeach; ?>
        </select></div>
    <button type="submit">Add</button>
</form>

<h2 style="margin:26px 0 10px">Recent sessions</h2>
<div class="list">
    <?php foreach ($recent as $s): ?>
        <div class="item">
            <div class="grow">
                <div class="title"><?= e($s['label'] ?: ($s['task_title'] ?: FOCUS_KINDS[$s['kind']] ?? $s['kind'])) ?></div>
                <div class="meta">
                    <span class="pill"><?= e(FOCUS_KINDS[$s['kind']] ?? $s['kind']) ?></span>
                    <span class="pill ok"><?= e(hhmm((int)$s['duration_sec'])) ?></span>
                    <?= e(date('D j M, H:i', strtotime($s['started_at']))) ?>
                    <?= $s['task_title'] ? ' · ' . e($s['task_title']) : '' ?>
                    <?= (int)$s['interruptions'] > 0 ? ' · ' . (int)$s['interruptions'] . ' interruptions' : '' ?>
                </div>
            </div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="ghost mini" type="submit">✕</button></form>
        </div>
    <?php endforeach; ?>
    <?php if (!$recent): ?><div class="empty">No sessions yet. Start the timer above.</div><?php endif; ?>
</div>

<script src="assets/focus.js"></script>
<?php page_footer();
