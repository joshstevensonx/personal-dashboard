<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/partials.php';
require_login();

$presets = theme_presets();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        // Each appearance setting is a small enum; anything unexpected falls
        // back to the default rather than being written through.
        $enums = [
            'theme'         => ['auto', 'light', 'dark'],
            'font'          => ['sans', 'serif', 'mono'],
            'font_size'     => ['sm', 'md', 'lg', 'xl'],
            'radius'        => ['square', 'soft', 'round', 'pill'],
            'page_width'    => ['narrow', 'normal', 'wide', 'full'],
            'density'       => ['comfortable', 'compact'],
            'sidebar_width' => ['sm', 'md', 'lg'],
        ];
        $defaults = setting_defaults();
        foreach ($enums as $key => $allowed) {
            $val = $_POST[$key] ?? '';
            set_setting($key, in_array($val, $allowed, true) ? $val : $defaults[$key]);
        }

        $accent = $_POST['accent'] ?? '';
        set_setting('accent', preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : $defaults['accent']);

        $start = $_POST['start_page'] ?? 'index.php';
        set_setting('start_page', preg_match('/^[a-z0-9_.-]+\.php$/i', $start) ? $start : 'index.php');

        flash('Appearance saved.');

    } elseif ($action === 'preset') {
        // A preset just writes several appearance settings at once.
        $presets = theme_presets();
        $p = $presets[$_POST['preset'] ?? ''] ?? null;
        if ($p) {
            set_setting('accent', $p['accent']);
            set_setting('font',   $p['font']);
            set_setting('radius', $p['radius']);
            set_setting('theme',  $p['theme']);
            flash('Applied the ' . $p['label'] . ' preset.');
        }

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

$cur = [];
foreach (array_keys(setting_defaults()) as $k) { $cur[$k] = (string)setting($k); }
$shortcuts = setting_json('shortcuts', []);

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

<h2 style="margin:24px 0 10px">Presets</h2>
<p class="sub">A starting point — every control below stays adjustable afterwards.</p>
<div class="row" style="margin-bottom:26px">
    <?php foreach ($presets as $k => $p): ?>
        <form method="post" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="preset">
            <input type="hidden" name="preset" value="<?= e($k) ?>">
            <button class="ghost" type="submit" style="gap:8px">
                <span style="width:14px;height:14px;border-radius:50%;background:<?= e($p['accent']) ?>;
                             display:inline-block;flex:0 0 auto"></span>
                <?= e($p['label']) ?>
            </button>
        </form>
    <?php endforeach; ?>
</div>

<form method="post" id="appearance">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">

    <h2 style="margin:22px 0 10px">Appearance</h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(215px,1fr))">

        <div class="card">
            <h2>Colour mode</h2>
            <div class="field">
                <select name="theme" data-live="data-theme">
                    <option value="auto"  <?= $cur['theme'] === 'auto'  ? 'selected' : '' ?>>Auto (follow system)</option>
                    <option value="light" <?= $cur['theme'] === 'light' ? 'selected' : '' ?>>Light</option>
                    <option value="dark"  <?= $cur['theme'] === 'dark'  ? 'selected' : '' ?>>Dark</option>
                </select>
            </div>
            <p class="muted" style="margin:0;font-size:var(--fs-sm)">Toggle any time with <kbd>⇧</kbd><kbd>D</kbd>.</p>
        </div>

        <div class="card">
            <h2>Accent</h2>
            <div class="row" style="margin:0;gap:8px;align-items:center">
                <input type="color" name="accent" id="accentinput" value="<?= e($cur['accent']) ?>">
                <span class="muted" id="accentval" style="font-size:var(--fs-sm)"><?= e($cur['accent']) ?></span>
            </div>
            <div class="row" style="gap:6px">
                <?php foreach (['#2383e2','#0f7b6c','#d9730d','#e03e3e','#9065b0','#337ea9','#5f5e5b'] as $sw): ?>
                    <button type="button" class="swatch" data-accent="<?= $sw ?>" title="<?= $sw ?>"
                        style="width:22px;height:22px;padding:0;border-radius:50%;background:<?= $sw ?>;
                               border:1px solid var(--border)"></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>Typeface</h2>
            <div class="field">
                <select name="font" data-live="data-font">
                    <option value="sans"  <?= $cur['font'] === 'sans'  ? 'selected' : '' ?>>Sans — clean UI</option>
                    <option value="serif" <?= $cur['font'] === 'serif' ? 'selected' : '' ?>>Serif — editorial</option>
                    <option value="mono"  <?= $cur['font'] === 'mono'  ? 'selected' : '' ?>>Mono — technical</option>
                </select>
            </div>
            <p class="muted" style="margin:0;font-size:var(--fs-sm)">Applies to page content; chrome stays sans.</p>
        </div>

        <div class="card">
            <h2>Text size</h2>
            <div class="field">
                <select name="font_size" data-live="data-size">
                    <option value="sm" <?= $cur['font_size'] === 'sm' ? 'selected' : '' ?>>Small</option>
                    <option value="md" <?= $cur['font_size'] === 'md' ? 'selected' : '' ?>>Medium</option>
                    <option value="lg" <?= $cur['font_size'] === 'lg' ? 'selected' : '' ?>>Large (default)</option>
                    <option value="xl" <?= $cur['font_size'] === 'xl' ? 'selected' : '' ?>>Extra large</option>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Corners</h2>
            <div class="field">
                <select name="radius" data-live="data-radius">
                    <option value="square" <?= $cur['radius'] === 'square' ? 'selected' : '' ?>>Square</option>
                    <option value="soft"   <?= $cur['radius'] === 'soft'   ? 'selected' : '' ?>>Soft</option>
                    <option value="round"  <?= $cur['radius'] === 'round'  ? 'selected' : '' ?>>Rounded</option>
                    <option value="pill"   <?= $cur['radius'] === 'pill'   ? 'selected' : '' ?>>Very rounded</option>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Content width</h2>
            <div class="field">
                <select name="page_width" data-live="data-width">
                    <option value="narrow" <?= $cur['page_width'] === 'narrow' ? 'selected' : '' ?>>Narrow — 680px</option>
                    <option value="normal" <?= $cur['page_width'] === 'normal' ? 'selected' : '' ?>>Normal — 900px</option>
                    <option value="wide"   <?= $cur['page_width'] === 'wide'   ? 'selected' : '' ?>>Wide — 1140px</option>
                    <option value="full"   <?= $cur['page_width'] === 'full'   ? 'selected' : '' ?>>Full width</option>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Density</h2>
            <div class="field">
                <select name="density" data-live="data-density">
                    <option value="comfortable" <?= $cur['density'] === 'comfortable' ? 'selected' : '' ?>>Comfortable</option>
                    <option value="compact"     <?= $cur['density'] === 'compact'     ? 'selected' : '' ?>>Compact</option>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Sidebar</h2>
            <div class="field">
                <select name="sidebar_width" data-live="data-sidebar">
                    <option value="sm" <?= $cur['sidebar_width'] === 'sm' ? 'selected' : '' ?>>Narrow</option>
                    <option value="md" <?= $cur['sidebar_width'] === 'md' ? 'selected' : '' ?>>Normal</option>
                    <option value="lg" <?= $cur['sidebar_width'] === 'lg' ? 'selected' : '' ?>>Wide</option>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Start page</h2>
            <div class="field">
                <select name="start_page">
                    <?php foreach ($pages as $file => $label): ?>
                        <option value="<?= e($file) ?>" <?= $cur['start_page'] === $file ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

    </div>

    <h2 style="margin:26px 0 10px">Preview</h2>
    <div class="card" style="gap:14px">
        <div class="row" style="align-items:center;gap:10px">
            <span class="pill ok">Done</span><span class="pill warn">Due soon</span>
            <span class="pill danger">Overdue</span><span class="pill">Neutral</span>
            <span class="tag" style="background:var(--tag5)">Tag</span>
        </div>
        <div class="row" style="gap:8px">
            <button type="button">Primary</button>
            <button type="button" class="ghost">Secondary</button>
            <button type="button" class="nbtn">Tertiary</button>
        </div>
        <div class="item">
            <span class="grow"><span class="title">Example row</span>
                <span class="meta">Secondary metadata sits underneath</span></span>
            <span class="pill">now</span>
        </div>
        <p style="margin:0;font-family:var(--font)">
            Body text renders in your chosen typeface and size. <strong>Bold</strong>,
            <em>italic</em>, and <code>inline code</code> all inherit the same scale.
        </p>
    </div>

    <div style="margin-top:16px;display:flex;gap:8px">
        <button type="submit">Save appearance</button>
        <span class="muted" style="align-self:center;font-size:var(--fs-sm)">
            Changes preview live — save to keep them.</span>
    </div>
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
/* Live preview: every control writes straight to the <html> attribute the
   token layer reads, so the whole page restyles before you hit save. */
(function () {
  document.querySelectorAll('[data-live]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      document.documentElement.setAttribute(sel.dataset.live, sel.value);
      // Colour mode needs re-resolving because "auto" depends on the OS.
      if (sel.dataset.live === 'data-theme') {
        var m = sel.value === 'auto'
          ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
          : sel.value;
        document.documentElement.setAttribute('data-mode', m);
      }
    });
  });

  var accent = document.getElementById('accentinput');
  var label  = document.getElementById('accentval');
  function paintAccent(v) {
    document.documentElement.style.setProperty('--accent', v);
    if (label) label.textContent = v;
  }
  if (accent) accent.addEventListener('input', function () { paintAccent(accent.value); });

  document.addEventListener('click', function (ev) {
    var sw = ev.target.closest('.swatch');
    if (sw) {
      ev.preventDefault();
      if (accent) { accent.value = sw.dataset.accent; }
      paintAccent(sw.dataset.accent);
      return;
    }
    if (ev.target.id === 'addsc') {
      var list = document.getElementById('sc-list');
      var last = list.lastElementChild.cloneNode(true);
      last.querySelector('input').value = '';
      list.appendChild(last);
    }
  });
})();
</script>
<?php page_footer();
