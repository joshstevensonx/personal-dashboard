<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/ics.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $start = trim($_POST['start_at'] ?? '');
        if ($title !== '' && $start !== '') {
            $allDay = isset($_POST['all_day']) ? 1 : 0;
            $startVal = $allDay ? $start . ' 00:00:00' : str_replace('T', ' ', $start) . ':00';
            $end = trim($_POST['end_at'] ?? '');
            $endVal = $end !== '' ? ($allDay ? $end . ' 00:00:00' : str_replace('T', ' ', $end) . ':00') : null;
            $pdo->prepare("INSERT INTO events (title, notes, location, start_at, end_at, all_day) VALUES (?,?,?,?,?,?)")
                ->execute([$title, trim($_POST['notes'] ?? ''), trim($_POST['location'] ?? ''), $startVal, $endVal, $allDay]);
            flash('Event added.');
        }
    } elseif ($a === 'delete_event') {
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Event deleted.');
    } elseif ($a === 'regen_token') {
        $pdo->exec("DELETE FROM calendar_feeds");
        calendar_token();
        flash('Calendar link regenerated — re-subscribe with the new URL.');
    }
    redirect('calendar.php' . (isset($_POST['m']) ? '?m=' . urlencode($_POST['m']) : ''));
}

/* month grid */
$month = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = date('Y-m'); }
$first = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: new DateTime('first day of this month');
$first->setTime(0, 0);
$monthStart = (clone $first)->modify('first day of this month');
$monthEnd   = (clone $first)->modify('last day of this month');

// Grid starts on Monday.
$gridStart = (clone $monthStart);
$dow = (int)$gridStart->format('N');
$gridStart->modify('-' . ($dow - 1) . ' days');
$gridEnd = (clone $monthEnd);
$dowEnd = (int)$gridEnd->format('N');
if ($dowEnd < 7) { $gridEnd->modify('+' . (7 - $dowEnd) . ' days'); }

$from = $gridStart->format('Y-m-d');
$to   = $gridEnd->format('Y-m-d');

$evStmt = $pdo->prepare("SELECT * FROM events WHERE date(start_at) BETWEEN ? AND ? ORDER BY start_at");
$evStmt->execute([$from, $to]);
$events = $evStmt->fetchAll();

$tkStmt = $pdo->prepare("SELECT * FROM tasks WHERE due_at IS NOT NULL AND date(due_at) BETWEEN ? AND ? ORDER BY due_at");
$tkStmt->execute([$from, $to]);
$tasks = $tkStmt->fetchAll();

$byDay = [];
foreach ($events as $ev) {
    $byDay[substr($ev['start_at'], 0, 10)][] = ['type' => 'event', 'row' => $ev];
}
foreach ($tasks as $t) {
    $byDay[substr($t['due_at'], 0, 10)][] = ['type' => 'task', 'row' => $t];
}

$prev = (clone $monthStart)->modify('-1 month')->format('Y-m');
$next = (clone $monthStart)->modify('+1 month')->format('Y-m');
$token = calendar_token();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
      . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$feedUrl = $base . '/ics.php?token=' . $token;

page_header('calendar.php');
?>
<h1>Calendar</h1>
<p class="sub">Events plus every task with a due date. Subscribe from Apple or Google Calendar using the link below.</p>

<div class="row" style="align-items:center;margin-bottom:14px">
    <a class="pill" href="?m=<?= e($prev) ?>">← <?= e((clone $monthStart)->modify('-1 month')->format('M')) ?></a>
    <strong style="font-size:17px"><?= e($monthStart->format('F Y')) ?></strong>
    <a class="pill" href="?m=<?= e($next) ?>"><?= e((clone $monthStart)->modify('+1 month')->format('M')) ?> →</a>
    <a class="pill ok" href="calendar.php">Today</a>
</div>

<div class="cal">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
        <div class="dow"><?= $d ?></div>
    <?php endforeach; ?>

    <?php
    $cursor = clone $gridStart;
    $today = date('Y-m-d');
    while ($cursor <= $gridEnd):
        $ds = $cursor->format('Y-m-d');
        $out = $cursor->format('Y-m') !== $monthStart->format('Y-m');
        $cls = 'day' . ($out ? ' out' : '') . ($ds === $today ? ' today' : '');
    ?>
        <div class="<?= $cls ?>">
            <div class="n"><?= (int)$cursor->format('j') ?></div>
            <?php foreach ($byDay[$ds] ?? [] as $entry):
                $r = $entry['row'];
                if ($entry['type'] === 'event'):
                    $t = $r['all_day'] ? '' : date('H:i', strtotime($r['start_at'])) . ' '; ?>
                    <div class="ev" title="<?= e($r['title']) ?>"><?= e($t . $r['title']) ?></div>
                <?php else:
                    $overdue = $r['status'] !== 'done' && $ds < $today; ?>
                    <div class="ev task <?= $overdue ? 'overdue' : '' ?>" title="<?= e($r['title']) ?>">
                        <?= $r['status'] === 'done' ? '✓ ' : '' ?><?= e($r['title']) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php $cursor->modify('+1 day'); endwhile; ?>
</div>

<h2 style="margin:28px 0 10px">Add an event</h2>
<form class="row" method="post" id="new">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_event">
    <input type="hidden" name="m" value="<?= e($month) ?>">
    <div class="field" style="flex:1;min-width:180px"><label>Title</label><input name="title" required></div>
    <div class="field"><label>Starts</label><input type="datetime-local" name="start_at" required></div>
    <div class="field"><label>Ends</label><input type="datetime-local" name="end_at"></div>
    <div class="field"><label>Location</label><input name="location" style="min-width:120px"></div>
    <div class="field"><label>&nbsp;</label>
        <label style="display:flex;gap:6px;align-items:center;color:var(--text)">
            <input type="checkbox" name="all_day" style="min-width:auto"> All day</label></div>
    <button type="submit">Add event</button>
</form>
<p class="muted" style="font-size:13px;margin-top:-10px">For an all-day event, the date part of "Starts" is used.</p>

<?php
$upcoming = $pdo->query("SELECT * FROM events WHERE datetime(start_at) >= datetime('now','-1 day') ORDER BY start_at LIMIT 12")->fetchAll();
if ($upcoming): ?>
    <h2 style="margin:28px 0 10px">Upcoming events</h2>
    <div class="list">
        <?php foreach ($upcoming as $ev): ?>
            <div class="item">
                <div class="grow">
                    <div class="title"><?= e($ev['title']) ?></div>
                    <div class="meta">
                        <?= e($ev['all_day'] ? date('D j M', strtotime($ev['start_at'])) . ' · all day'
                                             : date('D j M, H:i', strtotime($ev['start_at']))) ?>
                        <?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?>
                    </div>
                </div>
                <form method="post" onsubmit="return confirm('Delete this event?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                    <input type="hidden" name="m" value="<?= e($month) ?>">
                    <button class="ghost mini" type="submit">✕</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 style="margin:28px 0 10px">Calendar subscription (ICS)</h2>
<div class="card">
    <p style="margin:0 0 8px;font-size:14px">Subscribe once and your tasks and events appear in your calendar app, refreshing automatically.</p>
    <div class="item" style="align-items:flex-start">
        <code class="grow" style="word-break:break-all;font-size:12.5px"><?= e($feedUrl) ?></code>
        <button class="ghost mini" type="button" data-copy="<?= e($feedUrl) ?>">Copy</button>
    </div>
    <p class="muted" style="margin:10px 0 0;font-size:13px">
        <strong>Apple Calendar:</strong> File → New Calendar Subscription → paste.<br>
        <strong>Google Calendar:</strong> Other calendars → + → From URL → paste.<br>
        <strong>iPhone:</strong> Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar.
    </p>
    <p class="muted" style="margin:8px 0 0;font-size:13px">
        Anyone with this URL can read your event and task titles — treat it as a password.
    </p>
    <form method="post" onsubmit="return confirm('Regenerate the link? Existing subscriptions will stop updating.')" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="regen_token">
        <button class="ghost mini" type="submit">Regenerate link</button>
    </form>
</div>
<?php page_footer();
