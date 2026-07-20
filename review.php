<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/productivity.php';
require_once __DIR__ . '/lib/notes.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

// Week starts Monday. ?w=YYYY-MM-DD (any day in the week)
$anchor = $_GET['w'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) { $anchor = date('Y-m-d'); }
$start = date('Y-m-d', strtotime($anchor . ' monday this week'));
$end   = date('Y-m-d', strtotime($start . ' +6 days'));
$prevW = date('Y-m-d', strtotime($start . ' -7 days'));
$nextW = date('Y-m-d', strtotime($start . ' +7 days'));

// The weekly review is stored as a note tagged with its week.
$reviewTitle = 'Weekly review — week of ' . date('j M Y', strtotime($start));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'save_review') {
        $body = (string)($_POST['body'] ?? '');
        $ex = $pdo->prepare("SELECT id FROM notes WHERE title = ? AND deleted_at IS NULL");
        $ex->execute([$reviewTitle]);
        if ($id = $ex->fetchColumn()) {
            $cur = $pdo->prepare("SELECT body FROM notes WHERE id=?"); $cur->execute([$id]);
            $pdo->prepare("INSERT INTO note_revisions (note_id, body) VALUES (?,?)")->execute([$id, (string)$cur->fetchColumn()]);
            $pdo->prepare("UPDATE notes SET body=?, updated_at=datetime('now') WHERE id=?")->execute([$body, $id]);
        } else {
            $pdo->prepare("INSERT INTO notes (title, body, updated_at) VALUES (?,?,datetime('now'))")
                ->execute([$reviewTitle, $body]);
            $id = (int)$pdo->lastInsertId();
        }
        sync_tags('note', (int)$id, 'weekly-review');
        reindex_fts();
        flash('Weekly review saved to Notes.');
        redirect('review.php?w=' . urlencode($start));
    }
}

$g = function (string $sql, array $a) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st; };

$done = $g("SELECT t.*, p.name project FROM tasks t LEFT JOIN projects p ON p.id=t.project_id
            WHERE t.completed_at IS NOT NULL AND date(t.completed_at) BETWEEN ? AND ?
            ORDER BY t.completed_at", [$start, $end])->fetchAll();
$createdN = (int)$g("SELECT COUNT(*) FROM tasks WHERE date(created_at) BETWEEN ? AND ?", [$start, $end])->fetchColumn();
$stillOpen = $g("SELECT t.* FROM tasks t WHERE t.status IN ('open','doing')
                 AND t.due_at IS NOT NULL AND date(t.due_at) <= ? ORDER BY t.due_at", [$end])->fetchAll();
$focusSecs = (int)$g("SELECT COALESCE(SUM(duration_sec),0) FROM focus_sessions
                      WHERE ended_at IS NOT NULL AND kind<>'break' AND date(started_at) BETWEEN ? AND ?", [$start, $end])->fetchColumn();
$sessions = (int)$g("SELECT COUNT(*) FROM focus_sessions WHERE ended_at IS NOT NULL AND kind<>'break'
                     AND date(started_at) BETWEEN ? AND ?", [$start, $end])->fetchColumn();
$notesW = $g("SELECT id, title FROM notes WHERE deleted_at IS NULL AND date(created_at) BETWEEN ? AND ? ORDER BY created_at", [$start, $end])->fetchAll();
$events = $g("SELECT title, start_at FROM events WHERE date(start_at) BETWEEN ? AND ? ORDER BY start_at", [$start, $end])->fetchAll();
$plans = $g("SELECT * FROM daily_plans WHERE date BETWEEN ? AND ? ORDER BY date", [$start, $end])->fetchAll();

$habits = $pdo->query("SELECT * FROM habits WHERE archived=0 ORDER BY name")->fetchAll();
$habitStats = [];
foreach ($habits as $h) {
    $c = $g("SELECT COUNT(*) FROM habit_entries WHERE habit_id=? AND date BETWEEN ? AND ?", [$h['id'], $start, $end])->fetchColumn();
    $habitStats[] = ['name' => $h['name'], 'done' => (int)$c, 'target' => (int)$h['target'], 'color' => $h['color']];
}

$goals = $pdo->query("SELECT * FROM goals WHERE status='active' ORDER BY (due_date IS NULL), due_date LIMIT 8")->fetchAll();

// Existing review note for this week
$rv = $pdo->prepare("SELECT id, body FROM notes WHERE title = ? AND deleted_at IS NULL");
$rv->execute([$reviewTitle]);
$existing = $rv->fetch();

// Pre-fill template with the week's facts.
$prefill = $existing['body'] ?? (
    "## What went well\n\n- \n\n## What didn't\n\n- \n\n## What I learned\n\n- \n\n"
  . "## Next week's focus\n\n- \n\n---\n\n**By the numbers**\n\n"
  . "- Tasks completed: " . count($done) . " (created " . $createdN . ")\n"
  . "- Focus time: " . hhmm($focusSecs) . " across " . $sessions . " sessions\n"
  . "- Notes written: " . count($notesW) . "\n"
);

page_header('review.php');
?>
<h1>Weekly review</h1>
<div class="row" style="align-items:center;margin-bottom:18px">
    <a class="pill" href="?w=<?= e($prevW) ?>">← previous</a>
    <strong style="font-size:16px"><?= e(date('j M', strtotime($start))) ?> – <?= e(date('j M Y', strtotime($end))) ?></strong>
    <a class="pill" href="?w=<?= e($nextW) ?>">next →</a>
    <a class="pill ok" href="review.php">This week</a>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:20px">
    <div class="card"><h2>Completed</h2><div class="big"><?= count($done) ?></div>
        <div class="muted" style="font-size:13px">of <?= $createdN ?> created</div></div>
    <div class="card"><h2>Focus</h2><div class="big"><?= e(hhmm($focusSecs)) ?></div>
        <div class="muted" style="font-size:13px"><?= $sessions ?> sessions</div></div>
    <div class="card"><h2>Notes</h2><div class="big"><?= count($notesW) ?></div>
        <div class="muted" style="font-size:13px">written this week</div></div>
    <div class="card"><h2>Carrying over</h2><div class="big"><?= count($stillOpen) ?></div>
        <div class="muted" style="font-size:13px">due but not done</div></div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start;margin-bottom:20px">

    <div class="card">
        <h2>Completed this week</h2>
        <?php if (!$done): ?><div class="muted" style="font-size:13px">Nothing completed.</div><?php endif; ?>
        <?php foreach ($done as $t): ?>
            <div style="display:flex;gap:8px;font-size:13.5px;padding:3px 0">
                <span class="pill ok">✓</span>
                <span class="grow"><?= e($t['title']) ?></span>
                <?php if ($t['project']): ?><span class="pill"><?= e($t['project']) ?></span><?php endif; ?>
                <span class="muted"><?= e(date('D', strtotime($t['completed_at']))) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Carrying into next week</h2>
        <?php if (!$stillOpen): ?><div class="muted" style="font-size:13px">Nothing outstanding — clean week.</div><?php endif; ?>
        <?php foreach ($stillOpen as $t): [$dl, $dc] = due_state($t['due_at']); ?>
            <div style="display:flex;gap:8px;font-size:13.5px;padding:3px 0">
                <span class="grow"><?= e($t['title']) ?></span>
                <span class="pill <?= $dc ?>"><?= e($dl) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Habits</h2>
        <?php if (!$habitStats): ?><div class="muted" style="font-size:13px">No habits tracked.</div><?php endif; ?>
        <?php foreach ($habitStats as $h): $pct = $h['target'] ? min(100, (int)round($h['done'] / $h['target'] * 100)) : 0; ?>
            <div style="margin-bottom:7px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span><?= e($h['name']) ?></span>
                    <span class="muted"><?= $h['done'] ?>/<?= $h['target'] ?></span></div>
                <div style="background:var(--panel2);border-radius:999px;height:7px;margin-top:3px">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= e($h['color'] ?: '#54d19a') ?>;border-radius:999px"></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Goals</h2>
        <?php if (!$goals): ?><div class="muted" style="font-size:13px">No active goals.</div><?php endif; ?>
        <?php foreach ($goals as $gl): $pct = goal_percent($gl); ?>
            <div style="margin-bottom:7px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span><?= e($gl['title']) ?></span><span class="muted"><?= $pct ?>%</span></div>
                <div style="background:var(--panel2);border-radius:999px;height:7px;margin-top:3px">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--accent);border-radius:999px"></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($plans): ?>
    <div class="card">
        <h2>Daily intentions</h2>
        <?php foreach ($plans as $p): if (!trim((string)$p['intention'])) continue; ?>
            <div style="font-size:13px;padding:3px 0">
                <span class="pill"><?= e(date('D', strtotime($p['date']))) ?></span>
                <?= e($p['intention']) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($notesW): ?>
    <div class="card">
        <h2>Notes written</h2>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($notesW as $n): ?>
                <a class="pill" href="notes.php?id=<?= (int)$n['id'] ?>"><?= e($n['title']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<h2 style="margin:0 0 10px">Your reflection</h2>
<form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_review">
    <textarea name="body" style="min-height:280px;font:14px/1.65 ui-monospace,SFMono-Regular,Menlo,monospace"><?= e($prefill) ?></textarea>
    <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
        <button type="submit">Save review to Notes</button>
        <?php if ($existing): ?>
            <a class="pill" href="notes.php?id=<?= (int)$existing['id'] ?>">Open saved review</a>
            <span class="muted" style="font-size:13px">Already saved — saving again keeps a version history.</span>
        <?php endif; ?>
    </div>
</form>
<?php page_footer();
