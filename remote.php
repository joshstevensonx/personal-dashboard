<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

/* ---- method metadata -------------------------------------------------------
 * Each method knows how to turn a stored address into a launch action.
 * type: 'scheme'  -> clickable app deep-link (href built from tpl)
 *       'url'     -> address is itself a URL, link straight to it
 *       'rdp'     -> generate a downloadable .rdp file (Mac + Windows)
 *       'copy'    -> no reliable launcher; show a copy-the-ID button
 */
const METHODS = [
    'rustdesk' => ['label' => 'RustDesk',              'kind' => 'scheme', 'tpl' => 'rustdesk://%s', 'ph' => 'RustDesk ID (e.g. 123 456 789)'],
    'chrome'   => ['label' => 'Chrome Remote Desktop', 'kind' => 'url',    'tpl' => '%s',           'ph' => 'https://remotedesktop.google.com/access'],
    'vnc'      => ['label' => 'VNC / Screen Sharing',  'kind' => 'scheme', 'tpl' => 'vnc://%s',     'ph' => 'host or host:5900'],
    'rdp'      => ['label' => 'Microsoft Remote Desktop (RDP)', 'kind' => 'rdp', 'tpl' => '%s',     'ph' => 'host or host:3389'],
    'jump'     => ['label' => 'Jump Desktop',          'kind' => 'scheme', 'tpl' => 'jump://%s',    'ph' => 'jump address / id'],
    'other'    => ['label' => 'Other / link',          'kind' => 'auto',   'tpl' => '%s',           'ph' => 'address, id, or https:// link'],
];

const PLATFORMS = ['mac' => 'macOS', 'windows' => 'Windows', 'iphone' => 'iPhone'];

/* ---- .rdp file download ---------------------------------------------------- */
if (isset($_GET['rdp'])) {
    $st = $pdo->prepare("SELECT * FROM devices WHERE id = ? AND method = 'rdp'");
    $st->execute([(int)$_GET['rdp']]);
    $d = $st->fetch();
    if ($d && trim((string)$d['address']) !== '') {
        $host = trim($d['address']);
        $fname = preg_replace('/[^A-Za-z0-9_-]+/', '_', $d['name']) ?: 'connection';
        header('Content-Type: application/x-rdp');
        header('Content-Disposition: attachment; filename="' . $fname . '.rdp"');
        // Minimal, widely-compatible .rdp (opens in Microsoft Remote Desktop on Mac + Windows).
        echo "full address:s:$host\r\n";
        echo "prompt for credentials:i:1\r\n";
        echo "screen mode id:i:2\r\n";
        exit;
    }
    http_response_code(404);
    exit('No such RDP device.');
}

/* ---- writes ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $platform = array_key_exists($_POST['platform'] ?? '', PLATFORMS) ? $_POST['platform'] : 'mac';
        $method = array_key_exists($_POST['method'] ?? '', METHODS) ? $_POST['method'] : 'rustdesk';
        $address = trim($_POST['address'] ?? '');
        $share = trim($_POST['share_note'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($name !== '') {
            $st = $pdo->prepare("INSERT INTO devices (name, platform, method, address, share_note, notes) VALUES (?,?,?,?,?,?)");
            $st->execute([$name, $platform, $method, $address, $share, $notes]);
            flash('Device added.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM devices WHERE id = ?")->execute([(int)$_POST['id']]);
        flash('Removed.');
    }
    redirect('remote.php');
}

/* ---- build a launch action for a device ------------------------------------ */
function connect_action(array $d): array
{
    $m = METHODS[$d['method']] ?? METHODS['other'];
    $addr = trim((string)$d['address']);
    if ($addr === '') {
        return ['kind' => 'none'];
    }
    $kind = $m['kind'];
    if ($kind === 'auto') {
        $kind = preg_match('~^https?://~i', $addr) ? 'url' : 'copy';
    }
    if ($kind === 'rdp') {
        return ['kind' => 'rdp', 'href' => 'remote.php?rdp=' . (int)$d['id']];
    }
    if ($kind === 'url') {
        $href = preg_match('~^https?://~i', $addr) ? $addr : 'https://' . $addr;
        return ['kind' => 'link', 'href' => $href];
    }
    if ($kind === 'scheme') {
        // Keep host:port literal; strip only spaces. e() handles attribute safety.
        return ['kind' => 'link', 'href' => sprintf($m['tpl'], str_replace(' ', '', $addr)), 'raw' => $addr];
    }
    return ['kind' => 'copy', 'value' => $addr];
}

$rows = $pdo->query("SELECT * FROM devices ORDER BY platform, name")->fetchAll();

page_header('remote.php');
?>
<h1>Remote access hub</h1>
<p class="sub">Your Mac, Windows, and iPhone in one place — launch a session to control a device, or grab the details to share access with someone else. The connection runs through the remote-access app you pick; this hub just stores and launches it.</p>

<form class="row" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field" style="flex:1;min-width:150px"><label>Device name</label><input name="name" placeholder="MacBook Pro" required></div>
    <div class="field"><label>Platform</label>
        <select name="platform">
            <?php foreach (PLATFORMS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label>Method</label>
        <select name="method" id="method">
            <?php foreach (METHODS as $k => $m): ?><option value="<?= $k ?>"><?= e($m['label']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="flex:1;min-width:180px"><label>Address / ID</label><input name="address" id="address" placeholder="RustDesk ID (e.g. 123 456 789)"></div>
    <div class="field" style="flex:1;min-width:160px"><label>Share note <span class="muted">(optional)</span></label><input name="share_note" placeholder="what to send someone you give access to"></div>
    <div class="field" style="flex:1;min-width:140px"><label>Notes <span class="muted">(optional)</span></label><input name="notes" placeholder="e.g. home office"></div>
    <button type="submit">Add device</button>
</form>

<?php
// Group by platform for display.
$byPlat = ['mac' => [], 'windows' => [], 'iphone' => []];
foreach ($rows as $r) { $byPlat[$r['platform']][] = $r; }
?>

<?php foreach (PLATFORMS as $pk => $plabel): if (!$byPlat[$pk]) continue; ?>
    <h2 style="font-size:16px;margin:22px 0 10px"><?= e($plabel) ?></h2>
    <div class="list">
    <?php foreach ($byPlat[$pk] as $d): $act = connect_action($d); $m = METHODS[$d['method']] ?? METHODS['other']; ?>
        <div class="item">
            <div class="grow">
                <div class="title"><?= e($d['name']) ?> <span class="pill"><?= e($m['label']) ?></span></div>
                <div class="meta">
                    <?= $d['address'] !== '' ? e($d['address']) : '<span class="muted">no address set</span>' ?>
                    <?= $d['notes'] ? ' · ' . e($d['notes']) : '' ?>
                    <?php if ($d['share_note']): ?><br><span class="muted">Share: </span><?= e($d['share_note']) ?> <button class="ghost mini" type="button" data-copy="<?= e($d['share_note']) ?>">Copy share note</button><?php endif; ?>
                </div>
            </div>

            <?php if ($act['kind'] === 'link'): ?>
                <a class="pill ok" href="<?= e($act['href']) ?>" style="padding:7px 14px">Connect</a>
                <?php if (isset($act['raw'])): ?><button class="ghost mini" type="button" data-copy="<?= e($act['raw']) ?>">Copy ID</button><?php endif; ?>
            <?php elseif ($act['kind'] === 'rdp'): ?>
                <a class="pill ok" href="<?= e($act['href']) ?>" style="padding:7px 14px">Download .rdp</a>
            <?php elseif ($act['kind'] === 'copy'): ?>
                <button class="ghost mini" type="button" data-copy="<?= e($act['value']) ?>">Copy ID</button>
            <?php else: ?>
                <span class="muted" style="font-size:13px">add an address</span>
            <?php endif; ?>

            <form method="post" onsubmit="return confirm('Remove this device?')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="ghost mini" type="submit">✕</button></form>
        </div>
    <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if (!$rows): ?><div class="empty">No devices yet. Add one above.</div><?php endif; ?>

<h2 style="font-size:16px;margin:30px 0 10px">Setup guide by platform</h2>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));align-items:start">

    <div class="card">
        <h2>Control / share a macOS</h2>
        <p style="margin:0;font-size:14px">Easiest cross-platform: install <strong>RustDesk</strong> (free, open source) or <strong>Chrome Remote Desktop</strong> on the Mac and add its ID/link here. Native option: System Settings → General → Sharing → turn on <strong>Screen Sharing</strong>, then use a <code>vnc://</code> address (works Mac-to-Mac and from many clients).</p>
    </div>

    <div class="card">
        <h2>Control / share a Windows</h2>
        <p style="margin:0;font-size:14px">Windows <strong>Pro/Enterprise</strong>: enable <strong>Remote Desktop</strong> (Settings → System → Remote Desktop) and add it here as an <strong>RDP</strong> device — the hub generates a <code>.rdp</code> file you can open from Mac or Windows. Windows <strong>Home</strong> (no RDP host): use <strong>RustDesk</strong> or <strong>Chrome Remote Desktop</strong> instead.</p>
    </div>

    <div class="card">
        <h2>iPhone</h2>
        <p style="margin:0;font-size:14px">As a <strong>controller</strong>: install the RustDesk, Microsoft Remote Desktop, or Chrome Remote Desktop app and connect out to your Mac/Windows from the iPhone. Being <strong>controlled</strong> is limited by iOS — Apple doesn't allow full remote control of an iPhone by third-party apps. You can <em>share</em> your iPhone screen (view-only) via its built-in broadcast to FaceTime/Zoom/Teams; store that meeting link here as the "share note".</p>
    </div>

</div>

<script>
// Update the address placeholder to match the chosen method.
(function(){
    var ph = <?php echo json_encode(array_map(fn($m) => $m['ph'], METHODS)); ?>;
    var m = document.getElementById('method'), a = document.getElementById('address');
    if (m && a) m.addEventListener('change', function(){ a.placeholder = ph[m.value] || 'address / id'; });
})();
</script>
<?php page_footer();
