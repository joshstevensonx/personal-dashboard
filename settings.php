<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();

$presets = theme_presets();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $theme = in_array($_POST['theme'] ?? '', ['auto', 'dark', 'light'], true) ? $_POST['theme'] : 'auto';
        $preset = array_key_exists($_POST['preset'] ?? '', $presets) ? $_POST['preset'] : 'midnight';
        $accent = $_POST['accent'] ?? '#6ea8fe';
        if (!preg_match('/^#[0-9a-f]{6}$/i', $accent)) { $accent = '#6ea8fe'; }
        $density = in_array($_POST['density'] ?? '', ['comfortable', 'compact'], true) ? $_POST['density'] : 'comfortable';
        $start = $_POST['start_page'] ?? 'index.php';

        set_setting('theme', $theme);
        set_setting('preset', $preset);
        set_setting('accent', $accent);
        set_setting('density', $density);
        set_setting('start_page', $start);
        flash('Appearance saved.');
    } elseif ($action === 'shortcuts') {
        // Map single keys (pressed after "g") to a destination page.
        $keys = $_POST['key'] ?? [];
        $dests = $_POST['dest'] ?? [];
        $map = [];
        foreach ($keys as $i => $k) {
            $k = trim((string)$k);
            $d = trim((string)($dests[$i] ?? ''));
            if ($k !== '' && $d !== '' && preg_match('/^[a-z0-9,.\/]$/i', $k)) {
                $map[$k] = $d;
            }
        }
        set_setting('shortcuts', $map);
        flash('Shortcuts saved.');
    } elseif ($action === 'reset') {
        foreach (setting_defaults() as $k => $v) { set_setting($k, $v); }
        flash('Reset to defaults.');
    }
    redirect('settings.php');
}

$curTheme   = (string)setting('theme');
$curPreset  = (string)setting('preset');
$curAccent  = (string)setting('accent');
$curDensity = (string)setting('density');
$curStart   = (string)setting('start_page');
$shortcuts  = setting_json('shortcuts', []);

$pages = [];
foreach (nav_model() as $group => $items) {
    foreach ($items as $file => [$label, $icon]) { $pages[$file] = $label; }
}

// Schema version, for reassurance that migrations ran.
$schemaVersion = (int)db()->query("SELECT COALESCE(MAX(version),0) FROM migrations")->fetchColumn();

page_header('settings.php');
?>
<h1>Settings</h1>
<p class="sub">Appearance, layout, and keyboard shortcuts. Changes apply immediately.</p>

<form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">

    <h2 style="margin:22px 0 10px">Appearance</h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">

        <div class="card">
            <h2>Mode</h2>
            <div class="field">
                <select name="theme">
                    <option value="auto"  <?= $curTheme === 'auto' ? 'selected' : '' ?>>Auto (follow system)</option>
                    <option value="dark"  <?= $curTheme === 'dark' ? 'selected' : '' ?>>Dark</option>
                    <option value="light" <?= $curTheme === 'light' ? 'selected' : '' ?>>Light</option>
                </select>
            </div>
            <p class="muted" style="margin:0;font-size:13px">Toggle any time with <kbd>⇧</kbd><kbd>D</kbd>.</p>
        </div>

        <div class="card">
            <h2>Theme preset</h2>
            <div class="field">
                <select name="preset">
                    <?php foreach ($presets as $k => $p): ?>
                        <option value="<?= e($k) ?>" <?= $curPreset === $k ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:6px">
                <?php foreach ($presets as $p): ?>
                    <span title="<?= e($p['label']) ?>" style="width:22px;height:22px;border-radius:6px;border:1px solid var(--line);background:<?= e($p['vars']['panel']) ?>"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>Accent colour</h2>
            <div class="row" style="margin:0;gap:8px;align-items:center">
                <input type="color" name="accent" value="<?= e($curAccent) ?>">
                <span class="muted" style="font-size:13px"><?= e($curAccent) ?></span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (['#6ea8fe', '#54d19a', '#f2b45a', '#f2726f', '#b98cf5', '#4ecdc4'] as $sw): ?>
                    <button type="button" class="swatch" data-accent="<?= $sw ?>"
                        style="width:22px;height:22px;padding:0;border-radius:6px;background:<?= $sw ?>;border:1px solid var(--line)"></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>Density</h2>
            <div class="field">
                <select name="density">
                    <option value="comfortable" <?= $curDensity === 'comfortable' ? 'selected' : '' ?>>Comfortable</option>
                    <option value="compact"     <?= $curDensity === 'compact' ? 'selected' : '' ?>>Compact</option>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Start page</h2>
            <div class="field">
                <select name="start_page">
                    <?php foreach ($pages as $file => $label): ?>
                        <option value="<?= e($file) ?>" <?= $curStart === $file ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

    </div>
    <div style="margin-top:16px"><button type="submit">Save appearance</button></div>
</form>

<h2 style="margin:30px 0 10px">Keyboard shortcuts</h2>
<p class="sub">Press <kbd>g</kbd> then a key to jump to a page. Defaults: <kbd>g</kbd><kbd>d</kbd> dashboard,
   <kbd>g</kbd><kbd>i</kbd> inbox, <kbd>g</kbd><kbd>s</kbd> subscriptions, <kbd>g</kbd><kbd>t</kbd> dates,
   <kbd>g</kbd><kbd>b</kbd> bookmarks, <kbd>g</kbd><kbd>r</kbd> remote. Add overrides below.</p>

<form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="shortcuts">
    <div class="list" id="sc-list">
        <?php $i = 0; foreach ($shortcuts as $k => $dest): ?>
            <div class="item">
                <div class="field"><label>Key</label><input name="key[]" value="<?= e($k) ?>" maxlength="1" style="min-width:64px"></div>
                <div class="field grow"><label>Goes to</label>
                    <select name="dest[]">
                        <?php foreach ($pages as $file => $label): ?>
                            <option value="<?= e($file) ?>" <?= $dest === $file ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php $i++; endforeach; ?>
        <div class="item">
            <div class="field"><label>Key</label><input name="key[]" maxlength="1" placeholder="e.g. n" style="min-width:64px"></div>
            <div class="field grow"><label>Goes to</label>
                <select name="dest[]">
                    <?php foreach ($pages as $file => $label): ?>
                        <option value="<?= e($file) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div style="margin-top:14px;display:flex;gap:8px">
        <button type="submit">Save shortcuts</button>
        <button type="button" class="ghost" id="addsc">Add another</button>
    </div>
</form>

<h2 style="margin:30px 0 10px">Install as an app</h2>
<div class="card">
    <p style="margin:0;font-size:14px">
        <strong>macOS (Chrome/Edge):</strong> click the install icon in the address bar, or menu → Cast, Save &amp; Share → Install page as app. You'll get a Dock icon and its own window.<br>
        <strong>iPhone (Safari):</strong> Share → Add to Home Screen.<br>
        <strong>Windows:</strong> address-bar install icon, or menu → Apps → Install this site as an app.
    </p>
</div>

<h2 style="margin:30px 0 10px">Maintenance</h2>
<div class="card">
    <p style="margin:0 0 10px;font-size:14px" class="muted">
        Database schema version <strong><?= $schemaVersion ?></strong>.
        A backup is written to <code>data/backups/</code> automatically before any schema change.
    </p>
    <form method="post" onsubmit="return confirm('Reset all appearance settings to defaults?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset">
        <button class="ghost mini" type="submit">Reset appearance to defaults</button>
    </form>
</div>

<script>
document.addEventListener('click', function (ev) {
    var s = ev.target.closest('.swatch');
    if (s) {
        ev.preventDefault();
        var input = document.querySelector('input[name="accent"]');
        if (input) { input.value = s.getAttribute('data-accent'); input.dispatchEvent(new Event('input')); }
    }
    if (ev.target.id === 'addsc') {
        var list = document.getElementById('sc-list');
        var last = list.lastElementChild.cloneNode(true);
        last.querySelector('input').value = '';
        list.appendChild(last);
    }
});
// Live-preview the accent colour as you pick it.
var ac = document.querySelector('input[name="accent"]');
if (ac) ac.addEventListener('input', function () {
    document.documentElement.style.setProperty('--accent', ac.value);
});
</script>
<?php page_footer();
