<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $date = $_POST['date'] ?? '';
        $cat = in_array($_POST['category'] ?? '', ['birthday', 'expiry', 'deadline', 'general'], true) ? $_POST['category'] : 'general';
        $rec = isset($_POST['recurring']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');
        if ($title !== '' && $date !== '') {
            $st = $pdo->prepare("INSERT INTO important_dates (title, date, category, recurring, notes) VALUES (?,?,?,?,?)");
            $st->execute([$title, $date, $cat, $rec, $notes]);
            flash('Date added.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM important_dates WHERE id = ?")->execute([(int)$_POST['id']]);
        flash('Removed.');
    }
    redirect('dates.php');
}

$rows = $pdo->query("SELECT * FROM important_dates")->fetchAll();
foreach ($rows as &$r) {
    $r['effective'] = next_occurrence($r['date'], (bool)$r['recurring']);
    $r['days'] = days_until($r['effective']);
}
unset($r);
usort($rows, fn($a, $b) => $a['days'] <=> $b['days']);

$catPill = ['birthday' => 'ok', 'expiry' => 'danger', 'deadline' => 'warn', 'general' => ''];

page_header('dates.php');
?>
<h1>Important dates &amp; countdowns</h1>
<p class="sub">Birthdays, expiries, deadlines. Recurring dates roll forward each year automatically.</p>

<form class="row" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field" style="flex:1;min-width:200px"><label>Title</label><input name="title" placeholder="Passport expires" required></div>
    <div class="field"><label>Date</label><input name="date" type="date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label>Category</label>
        <select name="category">
            <option value="general">General</option><option value="birthday">Birthday</option>
            <option value="expiry">Expiry</option><option value="deadline">Deadline</option>
        </select>
    </div>
    <div class="field"><label>&nbsp;</label><label style="flex-direction:row;display:flex;gap:6px;align-items:center;color:var(--text)"><input type="checkbox" name="recurring" style="min-width:auto"> Repeats yearly</label></div>
    <button type="submit">Add</button>
</form>

<div class="list">
<?php foreach ($rows as $r):
    $cls = $r['days'] < 0 ? 'danger' : ($r['days'] <= ALERT_LEAD_DAYS ? 'warn' : ''); ?>
    <div class="item">
        <div class="grow">
            <div class="title"><?= e($r['title']) ?>
                <span class="pill <?= $catPill[$r['category']] ?? '' ?>"><?= e($r['category']) ?></span>
                <?= $r['recurring'] ? '<span class="pill">yearly</span>' : '' ?>
            </div>
            <div class="meta"><?= e($r['effective']) ?> <span class="pill <?= $cls ?>"><?= e(countdown_label($r['days'])) ?></span><?= $r['notes'] ? ' · ' . e($r['notes']) : '' ?></div>
        </div>
        <form method="post" onsubmit="return confirm('Delete this date?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="ghost mini" type="submit">✕</button></form>
    </div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="empty">No dates tracked yet.</div><?php endif; ?>
</div>
<?php page_footer();
