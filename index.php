<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();

$pdo = db();

// --- Inbox summary -----------------------------------------------------------
$inboxOpen = (int)$pdo->query("SELECT COUNT(*) FROM inbox WHERE done = 0")->fetchColumn();
$inboxRecent = $pdo->query("SELECT body, kind FROM inbox WHERE done = 0 ORDER BY id DESC LIMIT 4")->fetchAll();

// --- Subscriptions summary ---------------------------------------------------
$subs = $pdo->query("SELECT * FROM subscriptions WHERE active = 1")->fetchAll();
$monthlyTotal = 0.0;
foreach ($subs as $s) {
    $factor = ['weekly' => 52 / 12, 'monthly' => 1, 'yearly' => 1 / 12][$s['cycle']] ?? 1;
    $monthlyTotal += (float)$s['amount'] * $factor;
}
usort($subs, fn($a, $b) => strcmp($a['next_renewal'], $b['next_renewal']));
$subsSoon = array_slice($subs, 0, 4);

// --- Important dates summary -------------------------------------------------
$datesRaw = $pdo->query("SELECT * FROM important_dates")->fetchAll();
foreach ($datesRaw as &$d) {
    $d['effective'] = next_occurrence($d['date'], (bool)$d['recurring']);
    $d['days'] = days_until($d['effective']);
}
unset($d);
$upcoming = array_filter($datesRaw, fn($d) => $d['days'] >= 0);
usort($upcoming, fn($a, $b) => $a['days'] <=> $b['days']);
$datesSoon = array_slice($upcoming, 0, 4);

// --- Bookmarks / snippets summary --------------------------------------------
$bmCount = (int)$pdo->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();
$snCount = (int)$pdo->query("SELECT COUNT(*) FROM snippets")->fetchColumn();

// --- Devices summary ---------------------------------------------------------
$devCount = (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$devByPlat = $pdo->query("SELECT platform, COUNT(*) c FROM devices GROUP BY platform")->fetchAll();
$platLabels = ['mac' => 'macOS', 'windows' => 'Windows', 'iphone' => 'iPhone'];

page_header('index.php');
?>
<h1>Dashboard</h1>
<p class="sub"><?= date('l, F j, Y') ?></p>

<div class="grid">

    <div class="card">
        <h2>Quick-capture inbox</h2>
        <div class="big"><?= $inboxOpen ?><span class="muted" style="font-size:14px"> open</span></div>
        <ul>
            <?php foreach ($inboxRecent as $r): ?>
                <li><span class="grow" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_strimwidth($r['body'], 0, 40, '…')) ?></span><span class="pill"><?= e($r['kind']) ?></span></li>
            <?php endforeach; ?>
            <?php if (!$inboxRecent): ?><li class="muted">Nothing captured yet.</li><?php endif; ?>
        </ul>
        <a class="cta" href="inbox.php">Open inbox →</a>
    </div>

    <div class="card">
        <h2>Subscriptions</h2>
        <div class="big">~<?= number_format($monthlyTotal, 0) ?><span class="muted" style="font-size:14px"> /mo</span></div>
        <ul>
            <?php foreach ($subsSoon as $s): $dd = days_until($s['next_renewal']); ?>
                <li><span class="grow"><?= e($s['name']) ?></span><span class="pill <?= $dd <= ALERT_LEAD_DAYS ? 'warn' : '' ?>"><?= e(countdown_label($dd)) ?></span></li>
            <?php endforeach; ?>
            <?php if (!$subsSoon): ?><li class="muted">No active subscriptions.</li><?php endif; ?>
        </ul>
        <a class="cta" href="subscriptions.php">Manage →</a>
    </div>

    <div class="card">
        <h2>Upcoming dates</h2>
        <div class="big"><?= count($upcoming) ?><span class="muted" style="font-size:14px"> tracked</span></div>
        <ul>
            <?php foreach ($datesSoon as $d): ?>
                <li><span class="grow"><?= e($d['title']) ?></span><span class="pill <?= $d['days'] <= ALERT_LEAD_DAYS ? 'warn' : '' ?>"><?= e(countdown_label($d['days'])) ?></span></li>
            <?php endforeach; ?>
            <?php if (!$datesSoon): ?><li class="muted">No upcoming dates.</li><?php endif; ?>
        </ul>
        <a class="cta" href="dates.php">View all →</a>
    </div>

    <div class="card">
        <h2>Bookmarks &amp; snippets</h2>
        <div class="big"><?= $bmCount ?> <span class="muted" style="font-size:14px">links</span> &middot; <?= $snCount ?> <span class="muted" style="font-size:14px">snippets</span></div>
        <ul>
            <li class="muted">Your links and reusable text, one search away.</li>
        </ul>
        <a class="cta" href="bookmarks.php">Open vault →</a>
    </div>

    <div class="card">
        <h2>Remote access</h2>
        <div class="big"><?= $devCount ?><span class="muted" style="font-size:14px"> devices</span></div>
        <ul>
            <?php foreach ($devByPlat as $p): ?>
                <li><span class="grow"><?= e($platLabels[$p['platform']] ?? $p['platform']) ?></span><span class="pill"><?= (int)$p['c'] ?></span></li>
            <?php endforeach; ?>
            <?php if (!$devByPlat): ?><li class="muted">No devices registered.</li><?php endif; ?>
        </ul>
        <a class="cta" href="remote.php">Open hub →</a>
    </div>

</div>
<?php page_footer();
