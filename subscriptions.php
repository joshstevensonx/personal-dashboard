<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD')) ?: 'USD';
        $cycle = in_array($_POST['cycle'] ?? '', ['weekly', 'monthly', 'yearly'], true) ? $_POST['cycle'] : 'monthly';
        $next = $_POST['next_renewal'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        if ($name !== '') {
            $st = $pdo->prepare("INSERT INTO subscriptions (name, amount, currency, cycle, next_renewal, notes) VALUES (?,?,?,?,?,?)");
            $st->execute([$name, $amount, $currency, $cycle, $next, $notes]);
            flash('Subscription added.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare("UPDATE subscriptions SET active = 1 - active WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'renewed') {
        // Push next renewal forward by one cycle.
        $row = $pdo->prepare("SELECT next_renewal, cycle FROM subscriptions WHERE id = ?");
        $row->execute([(int)$_POST['id']]);
        if ($r = $row->fetch()) {
            $step = ['weekly' => '+1 week', 'monthly' => '+1 month', 'yearly' => '+1 year'][$r['cycle']] ?? '+1 month';
            $d = DateTime::createFromFormat('Y-m-d', $r['next_renewal']) ?: new DateTime('today');
            $d->modify($step);
            $pdo->prepare("UPDATE subscriptions SET next_renewal = ? WHERE id = ?")->execute([$d->format('Y-m-d'), (int)$_POST['id']]);
            flash('Renewal date advanced.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM subscriptions WHERE id = ?")->execute([(int)$_POST['id']]);
        flash('Removed.');
    }
    redirect('subscriptions.php');
}

$rows = $pdo->query("SELECT * FROM subscriptions ORDER BY active DESC, next_renewal ASC")->fetchAll();
$monthly = 0.0; $yearly = 0.0;
foreach ($rows as $r) {
    if (!$r['active']) continue;
    $mFactor = ['weekly' => 52 / 12, 'monthly' => 1, 'yearly' => 1 / 12][$r['cycle']] ?? 1;
    $monthly += (float)$r['amount'] * $mFactor;
    $yearly += (float)$r['amount'] * $mFactor * 12;
}

page_header('subscriptions.php');
?>
<h1>Subscriptions &amp; renewals</h1>
<p class="sub">Est. <strong><?= number_format($monthly, 2) ?></strong>/mo · <strong><?= number_format($yearly, 2) ?></strong>/yr across active subscriptions. Renewals within <?= ALERT_LEAD_DAYS ?> days are flagged.</p>

<form class="row" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label>Name</label><input name="name" placeholder="Netflix" required></div>
    <div class="field"><label>Amount</label><input name="amount" type="number" step="0.01" min="0" style="min-width:90px" placeholder="9.99"></div>
    <div class="field"><label>Currency</label><input name="currency" value="USD" style="min-width:70px"></div>
    <div class="field"><label>Cycle</label>
        <select name="cycle"><option value="monthly">Monthly</option><option value="yearly">Yearly</option><option value="weekly">Weekly</option></select>
    </div>
    <div class="field"><label>Next renewal</label><input name="next_renewal" type="date" value="<?= date('Y-m-d') ?>"></div>
    <button type="submit">Add</button>
</form>

<div class="list">
<?php foreach ($rows as $r): $dd = days_until($r['next_renewal']);
    $cls = $dd < 0 ? 'danger' : ($dd <= ALERT_LEAD_DAYS ? 'warn' : ''); ?>
    <div class="item" style="<?= $r['active'] ? '' : 'opacity:.5' ?>">
        <div class="grow">
            <div class="title"><?= e($r['name']) ?> <span class="muted">· <?= money((float)$r['amount'], $r['currency']) ?> / <?= e($r['cycle']) ?></span></div>
            <div class="meta">Next: <?= e($r['next_renewal']) ?> <span class="pill <?= $cls ?>"><?= e(countdown_label($dd)) ?></span><?= $r['notes'] ? ' · ' . e($r['notes']) : '' ?><?= $r['active'] ? '' : ' · <span class="pill">paused</span>' ?></div>
        </div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="renewed"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="ghost mini" type="submit" title="Mark renewed / advance date">↻</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="ghost mini" type="submit"><?= $r['active'] ? 'Pause' : 'Resume' ?></button></form>
        <form method="post" onsubmit="return confirm('Delete this subscription?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="ghost mini" type="submit">✕</button></form>
    </div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="empty">No subscriptions tracked yet.</div><?php endif; ?>
</div>
<?php page_footer();
