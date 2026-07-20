<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/productivity.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

$range = $_GET['range'] ?? '30';
$days = in_array($range, ['7', '30', '90', '365'], true) ? (int)$range : 30;
$since = "-{$days} day";

/* ------------------------------------------------------------- CSV export -- */
if (isset($_GET['csv'])) {
    $what = $_GET['csv'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/\W+/', '_', $what) . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($what === 'focus') {
        fputcsv($out, ['started_at', 'kind', 'label', 'task', 'minutes', 'interruptions'], ',', '"', '\\');
        $st = $pdo->prepare("SELECT f.started_at, f.kind, f.label, t.title task, f.duration_sec, f.interruptions
                             FROM focus_sessions f LEFT JOIN tasks t ON t.id=f.task_id
                             WHERE f.ended_at IS NOT NULL AND date(f.started_at) >= date('now', ?)
                             ORDER BY f.started_at");
        $st->execute([$since]);
        foreach ($st as $r) {
            fputcsv($out, [$r['started_at'], $r['kind'], $r['label'], $r['task'],
                           round(((int)$r['duration_sec']) / 60, 1), $r['interruptions']], ',', '"', '\\');
        }
    } elseif ($what === 'tasks') {
        fputcsv($out, ['title', 'project', 'priority', 'status', 'due_at', 'completed_at', 'estimate_min'], ',', '"', '\\');
        $st = $pdo->prepare("SELECT t.title, p.name project, t.priority, t.status, t.due_at, t.completed_at, t.estimate_min
                             FROM tasks t LEFT JOIN projects p ON p.id=t.project_id
                             WHERE date(COALESCE(t.completed_at, t.created_at)) >= date('now', ?)
                             ORDER BY t.created_at");
        $st->execute([$since]);
        foreach ($st as $r) { fputcsv($out, $r, ',', '"', '\\'); }
    } elseif ($what === 'habits') {
        fputcsv($out, ['habit', 'date', 'count'], ',', '"', '\\');
        $st = $pdo->prepare("SELECT h.name, e.date, e.count FROM habit_entries e
                             JOIN habits h ON h.id=e.habit_id
                             WHERE e.date >= date('now', ?) ORDER BY h.name, e.date");
        $st->execute([$since]);
        foreach ($st as $r) { fputcsv($out, $r, ',', '"', '\\'); }
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------ data --- */
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) {
    $st = $pdo->prepare($sql); $st->execute($a); return $st;
})();

$created   = (int)$q("SELECT COUNT(*) FROM tasks WHERE date(created_at) >= date('now', ?)", [$since])->fetchColumn();
$completed = (int)$q("SELECT COUNT(*) FROM tasks WHERE completed_at IS NOT NULL AND date(completed_at) >= date('now', ?)", [$since])->fetchColumn();
$openNow   = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('open','doing')")->fetchColumn();
$overdue   = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('open','doing') AND date(due_at) < date('now')")->fetchColumn();
$focusSecs = (int)$q("SELECT COALESCE(SUM(duration_sec),0) FROM focus_sessions WHERE ended_at IS NOT NULL AND kind<>'break' AND date(started_at) >= date('now', ?)", [$since])->fetchColumn();
$sessions  = (int)$q("SELECT COUNT(*) FROM focus_sessions WHERE ended_at IS NOT NULL AND kind<>'break' AND date(started_at) >= date('now', ?)", [$since])->fetchColumn();
$notesMade = (int)$q("SELECT COUNT(*) FROM notes WHERE deleted_at IS NULL AND date(created_at) >= date('now', ?)", [$since])->fetchColumn();

// Completion by day
$compByDay = [];
foreach ($q("SELECT date(completed_at) d, COUNT(*) c FROM tasks
             WHERE completed_at IS NOT NULL AND date(completed_at) >= date('now', ?)
             GROUP BY d ORDER BY d", [$since]) as $r) { $compByDay[$r['d']] = (int)$r['c']; }

// Focus by day
$focusDay = [];
foreach ($q("SELECT date(started_at) d, COALESCE(SUM(duration_sec),0) s FROM focus_sessions
             WHERE ended_at IS NOT NULL AND kind<>'break' AND date(started_at) >= date('now', ?)
             GROUP BY d ORDER BY d", [$since]) as $r) { $focusDay[$r['d']] = (int)$r['s']; }

// Time by project / tag
$byProject = $q("SELECT COALESCE(p.name,'(no project)') name, COUNT(*) n, COALESCE(SUM(f.duration_sec),0) secs
                 FROM focus_sessions f LEFT JOIN tasks t ON t.id=f.task_id
                 LEFT JOIN projects p ON p.id=t.project_id
                 WHERE f.ended_at IS NOT NULL AND f.kind<>'break' AND date(f.started_at) >= date('now', ?)
                 GROUP BY name ORDER BY secs DESC", [$since])->fetchAll();

$byPriority = $q("SELECT priority, COUNT(*) c FROM tasks
                  WHERE completed_at IS NOT NULL AND date(completed_at) >= date('now', ?)
                  GROUP BY priority ORDER BY priority", [$since])->fetchAll();

$habits = $pdo->query("SELECT * FROM habits WHERE archived=0 ORDER BY name")->fetchAll();

// Busiest weekday
$byDow = [];
foreach ($q("SELECT strftime('%w', started_at) w, COALESCE(SUM(duration_sec),0) s
             FROM focus_sessions WHERE ended_at IS NOT NULL AND kind<>'break' AND date(started_at) >= date('now', ?)
             GROUP BY w", [$since]) as $r) { $byDow[(int)$r['w']] = (int)$r['s']; }
$dowNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

$maxComp = $compByDay ? max($compByDay) : 0;
$maxFocus = $focusDay ? max($focusDay) : 0;
$maxProj = $byProject ? max(array_column($byProject, 'secs')) : 0;
$maxDow = $byDow ? max($byDow) : 0;

page_header('reports.php');
?>
<h1>Reports</h1>
<div class="row" style="align-items:center;margin-bottom:18px">
    <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'] as $r => $l): ?>
        <a class="pill <?= (string)$days === $r ? 'ok' : '' ?>" href="?range=<?= $r ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <span style="flex:1"></span>
    <a class="pill" href="?range=<?= $days ?>&csv=focus">CSV: focus</a>
    <a class="pill" href="?range=<?= $days ?>&csv=tasks">CSV: tasks</a>
    <a class="pill" href="?range=<?= $days ?>&csv=habits">CSV: habits</a>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:22px">
    <div class="card"><h2>Tasks completed</h2><div class="big"><?= $completed ?></div>
        <div class="muted" style="font-size:13px"><?= $created ?> created · <?= $openNow ?> still open</div></div>
    <div class="card"><h2>Focus time</h2><div class="big"><?= e(hhmm($focusSecs)) ?></div>
        <div class="muted" style="font-size:13px"><?= $sessions ?> sessions · avg <?= $sessions ? e(hhmm((int)round($focusSecs / $sessions))) : '0m' ?></div></div>
    <div class="card"><h2>Daily average</h2><div class="big"><?= e(hhmm((int)round($focusSecs / max(1, $days)))) ?></div>
        <div class="muted" style="font-size:13px"><?= round($completed / max(1, $days), 1) ?> tasks/day</div></div>
    <div class="card"><h2>Notes written</h2><div class="big"><?= $notesMade ?></div>
        <div class="muted" style="font-size:13px"><?= $overdue ?> tasks overdue now</div></div>
</div>

<h2 style="margin:0 0 10px">Tasks completed per day</h2>
<div class="card" style="margin-bottom:20px">
    <div style="display:flex;align-items:flex-end;gap:2px;height:120px">
        <?php for ($i = $days - 1; $i >= 0; $i--):
            $d = date('Y-m-d', strtotime("-$i day")); $c = $compByDay[$d] ?? 0;
            $h = $maxComp > 0 ? max(2, (int)round($c / $maxComp * 110)) : 2; ?>
            <div title="<?= e("$d — $c completed") ?>" style="flex:1;height:<?= $h ?>px;border-radius:2px 2px 0 0;
                 background:<?= $c ? 'var(--ok)' : 'var(--line)' ?>"></div>
        <?php endfor; ?>
    </div>
    <div class="muted" style="font-size:12px;display:flex;justify-content:space-between">
        <span><?= $days ?>d ago</span><span>peak <?= $maxComp ?>/day</span><span>today</span></div>
</div>

<h2 style="margin:0 0 10px">Focus time per day</h2>
<div class="card" style="margin-bottom:20px">
    <div style="display:flex;align-items:flex-end;gap:2px;height:120px">
        <?php for ($i = $days - 1; $i >= 0; $i--):
            $d = date('Y-m-d', strtotime("-$i day")); $s = $focusDay[$d] ?? 0;
            $h = $maxFocus > 0 ? max(2, (int)round($s / $maxFocus * 110)) : 2; ?>
            <div title="<?= e("$d — " . hhmm($s)) ?>" style="flex:1;height:<?= $h ?>px;border-radius:2px 2px 0 0;
                 background:<?= $s ? 'var(--accent)' : 'var(--line)' ?>"></div>
        <?php endfor; ?>
    </div>
    <div class="muted" style="font-size:12px;display:flex;justify-content:space-between">
        <span><?= $days ?>d ago</span><span>peak <?= e(hhmm($maxFocus)) ?></span><span>today</span></div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:start">

    <div class="card">
        <h2>Time by project</h2>
        <?php if (!$byProject): ?><div class="muted" style="font-size:13px">No focus sessions in range.</div><?php endif; ?>
        <?php foreach ($byProject as $p): $w = $maxProj ? (int)round($p['secs'] / $maxProj * 100) : 0; ?>
            <div style="margin-bottom:8px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span><?= e($p['name']) ?></span><span class="muted"><?= e(hhmm((int)$p['secs'])) ?></span></div>
                <div style="background:var(--panel2);border-radius:999px;height:7px;margin-top:3px">
                    <div style="width:<?= $w ?>%;height:100%;background:var(--accent);border-radius:999px"></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Completed by priority</h2>
        <?php if (!$byPriority): ?><div class="muted" style="font-size:13px">Nothing completed in range.</div><?php endif; ?>
        <?php foreach ($byPriority as $p): $pr = PRIORITIES[$p['priority']] ?? PRIORITIES[2];
            $pct = $completed ? (int)round($p['c'] / $completed * 100) : 0; ?>
            <div style="margin-bottom:8px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span class="pill <?= $pr['class'] ?>"><?= $pr['short'] ?></span>
                    <span class="muted"><?= (int)$p['c'] ?> · <?= $pct ?>%</span></div>
                <div style="background:var(--panel2);border-radius:999px;height:7px;margin-top:3px">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--ok);border-radius:999px"></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Focus by weekday</h2>
        <?php for ($w = 1; $w <= 7; $w++): $idx = $w % 7; $s = $byDow[$idx] ?? 0;
            $pct = $maxDow ? (int)round($s / $maxDow * 100) : 0; ?>
            <div style="margin-bottom:7px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span><?= $dowNames[$idx] ?></span><span class="muted"><?= e(hhmm($s)) ?></span></div>
                <div style="background:var(--panel2);border-radius:999px;height:7px;margin-top:3px">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--warn);border-radius:999px"></div></div>
            </div>
        <?php endfor; ?>
    </div>

    <div class="card">
        <h2>Habit streaks</h2>
        <?php if (!$habits): ?><div class="muted" style="font-size:13px">No habits tracked.</div><?php endif; ?>
        <?php foreach ($habits as $h): $s = habit_streaks((int)$h['id']);
            $hist = habit_history((int)$h['id'], $days);
            $rate = $days ? (int)round(count($hist) / $days * 100) : 0; ?>
            <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;font-size:13px;align-items:center">
                    <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= e($h['color'] ?: '#54d19a') ?>"></span>
                        <?= e($h['name']) ?></span>
                    <span class="muted"><?= $s['current'] ?>d streak · <?= $rate ?>%</span></div>
                <div style="display:flex;gap:1px;margin-top:4px;flex-wrap:wrap">
                    <?php for ($i = min($days, 60) - 1; $i >= 0; $i--):
                        $d = date('Y-m-d', strtotime("-$i day")); $on = isset($hist[$d]); ?>
                        <span style="width:7px;height:7px;border-radius:1px;background:<?= $on ? e($h['color'] ?: '#54d19a') : 'var(--line)' ?>"></span>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>
<?php page_footer();
