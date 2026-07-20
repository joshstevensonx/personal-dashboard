<?php
/**
 * Import Notion content: HTML export (folder or .zip) and, once a token is
 * configured, live sync through the official Notion API.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/notion_import.php';
require_once __DIR__ . '/lib/notion_api.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

$result = null;
$scan = null;
$error = '';

/** Where uploaded/extracted exports live (outside the web root when possible). */
function import_workdir(): string
{
    $base = defined('UPLOAD_PATH') ? dirname(UPLOAD_PATH) : dirname(__DIR__);
    $dir = $base . '/notion-imports';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';

    if ($a === 'scan') {
        $dir = trim($_POST['dir'] ?? '');
        $scan = notion_scan($dir);
        if (!$scan['exists']) { $error = 'No folder at that path. Check the path and try again.'; }

    } elseif ($a === 'import_dir') {
        $dir = trim($_POST['dir'] ?? '');
        $parent = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
        if (!is_dir($dir)) {
            $error = 'No folder at that path.';
        } else {
            $result = import_notion_export($dir, $parent);
            flash('Import finished — ' . $result['pages'] . ' pages, '
                . $result['databases'] . ' databases.');
        }

    } elseif ($a === 'import_zip' && !empty($_FILES['zip']['tmp_name'])) {
        if (!class_exists('ZipArchive')) {
            $error = 'The zip extension is not enabled on this server. '
                   . 'Unzip the export locally and import it by folder path instead.';
        } else {
            $work = import_workdir() . '/' . date('Ymd-His');
            @mkdir($work, 0775, true);
            $zip = new ZipArchive();
            if ($zip->open($_FILES['zip']['tmp_name']) === true) {
                $zip->extractTo($work);
                $zip->close();
                // Notion sometimes nests everything one level deep.
                $roots = glob($work . '/*.html') ?: [];
                if (!$roots) {
                    foreach (glob($work . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                        if (glob($sub . '/*.html')) { $work = $sub; break; }
                    }
                }
                $parent = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
                $result = import_notion_export($work, $parent);
                flash('Import finished — ' . $result['pages'] . ' pages, '
                    . $result['databases'] . ' databases.');
            } else {
                $error = 'Could not open that .zip file.';
            }
        }

    } elseif ($a === 'save_token') {
        set_setting('notion_token', trim($_POST['token'] ?? ''));
        flash('Notion token saved.');
        redirect('import.php');

    } elseif ($a === 'api_test') {
        try {
            $me = notion_api_get('users/me');
            flash('Connected to Notion as ' . ($me['name'] ?? $me['bot']['owner']['type'] ?? 'integration') . '.');
        } catch (Throwable $ex) {
            $error = 'Notion API: ' . $ex->getMessage();
        }
        redirect('import.php');

    } elseif ($a === 'api_pull') {
        try {
            $result = notion_api_pull(($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null);
            flash('Pulled ' . $result['pages'] . ' pages from Notion.');
        } catch (Throwable $ex) {
            $error = 'Notion API: ' . $ex->getMessage();
        }
    }
}

$token = (string)setting('notion_token', '');
$imported = $pdo->query("SELECT COUNT(*) FROM pages WHERE notion_id IS NOT NULL AND archived = 0")->fetchColumn();
$topPages = $pdo->query("SELECT id, title FROM pages WHERE parent_id IS NULL AND archived = 0 ORDER BY title")->fetchAll();

// A sensible default path: the folder the user most likely extracted to.
$guess = '';
foreach ([dirname(__DIR__) . '/notion-export', __DIR__ . '/notion-export',
          getenv('HOME') . '/notion-export'] as $g) {
    if (is_dir($g)) { $guess = $g; break; }
}

page_header('import.php');
?>
<h1>Import from Notion</h1>
<p class="sub">Bring your Notion workspace in as native pages and databases.
   Imported pages render with Notion's own stylesheet, so they look the same.</p>

<?php if ($error): ?>
    <div class="flash" style="background:var(--danger-soft);border-color:var(--danger);color:var(--danger)">
        <?= e($error) ?></div>
<?php endif; ?>

<?php if ($imported): ?>
    <div class="flash"><?= (int)$imported ?> pages in this workspace came from Notion.
        Re-importing updates them in place rather than duplicating.</div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">

    <div class="card">
        <h2>1 · Import an HTML export</h2>
        <p class="muted" style="margin:0;font-size:var(--fs-sm)">
            In Notion: <strong>⋯ → Export → HTML</strong>, tick <em>Include subpages</em>.
            Unzip it, then give the folder path here.
        </p>
        <form method="post" class="row" style="margin:6px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="scan">
            <div class="field" style="flex:1;min-width:180px"><label>Folder path</label>
                <input name="dir" value="<?= e($_POST['dir'] ?? $guess) ?>"
                       placeholder="/var/www/vhosts/…/notion-export" required></div>
            <button class="ghost" type="submit">Preview</button>
        </form>
    </div>

    <div class="card">
        <h2>Or upload the .zip</h2>
        <p class="muted" style="margin:0;font-size:var(--fs-sm)">
            Works for smaller exports. Large ones (yours is ~180 MB) will hit PHP's
            upload limit — use the folder path instead.
        </p>
        <form method="post" enctype="multipart/form-data" class="row" style="margin:6px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="import_zip">
            <div class="field" style="flex:1"><label>Export .zip</label>
                <input type="file" name="zip" accept=".zip" required></div>
            <div class="field"><label>Import under</label>
                <select name="parent_id">
                    <option value="">Top level</option>
                    <?php foreach ($topPages as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button type="submit">Upload &amp; import</button>
        </form>
        <div class="muted" style="font-size:var(--fs-xs)">
            Max upload here: <?= e(ini_get('upload_max_filesize') ?: '?') ?>
            · zip extension: <?= class_exists('ZipArchive') ? 'available' : 'NOT available' ?>
        </div>
    </div>
</div>

<?php if ($scan && $scan['exists']): ?>
    <h2 style="margin:26px 0 10px">Preview</h2>
    <div class="card">
        <div class="row" style="gap:18px">
            <div><div class="big"><?= (int)$scan['html'] ?></div><span class="muted">pages</span></div>
            <div><div class="big"><?= (int)$scan['csv'] ?></div><span class="muted">databases</span></div>
            <div><div class="big"><?= (int)$scan['assets'] ?></div><span class="muted">files</span></div>
        </div>
        <?php if ($scan['root_pages']): ?>
            <div class="muted" style="font-size:var(--fs-sm)">Top-level:
                <?= e(implode(' · ', array_slice($scan['root_pages'], 0, 8))) ?>
                <?= count($scan['root_pages']) > 8 ? ' …' : '' ?></div>
        <?php endif; ?>
        <form method="post" class="row" style="margin:10px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="import_dir">
            <input type="hidden" name="dir" value="<?= e($_POST['dir'] ?? '') ?>">
            <div class="field"><label>Import under</label>
                <select name="parent_id">
                    <option value="">Top level</option>
                    <?php foreach ($topPages as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button type="submit">Import <?= (int)$scan['html'] ?> pages</button>
        </form>
        <p class="muted" style="margin:0;font-size:var(--fs-xs)">
            Large imports can take a few minutes. Don't close the tab.</p>
    </div>
<?php endif; ?>

<?php if ($result): ?>
    <h2 style="margin:26px 0 10px">Import result</h2>
    <div class="card">
        <div class="row" style="gap:18px">
            <div><div class="big"><?= (int)$result['pages'] ?></div><span class="muted">pages</span></div>
            <div><div class="big"><?= (int)$result['databases'] ?></div><span class="muted">databases</span></div>
            <div><div class="big"><?= (int)$result['rows'] ?></div><span class="muted">rows</span></div>
            <div><div class="big"><?= (int)$result['blocks'] ?></div><span class="muted">blocks</span></div>
            <div><div class="big"><?= (int)$result['assets'] ?></div><span class="muted">files</span></div>
            <div><div class="big"><?= (int)$result['links'] ?></div><span class="muted">links rewired</span></div>
        </div>
        <?php if ($result['errors']): ?>
            <div class="muted" style="font-size:var(--fs-sm)">
                <strong><?= count($result['errors']) ?> warnings:</strong>
                <?= e(implode(' · ', array_slice($result['errors'], 0, 6))) ?></div>
        <?php endif; ?>
        <a class="cta" href="page.php">Open your pages →</a>
    </div>
<?php endif; ?>

<h2 style="margin:30px 0 10px">2 · Live sync via the Notion API</h2>
<div class="card">
    <p class="muted" style="margin:0;font-size:var(--fs-sm)">
        The HTML export is a one-time snapshot. For ongoing sync, connect the official API:
    </p>
    <ol class="muted" style="margin:6px 0;font-size:var(--fs-sm);padding-left:1.2em">
        <li>Go to <a href="https://www.notion.so/my-integrations" target="_blank" rel="noopener">notion.so/my-integrations</a>
            and create an internal integration.</li>
        <li>Copy its <strong>Internal Integration Secret</strong> (starts <code>ntn_</code> or <code>secret_</code>).</li>
        <li>In Notion, open the pages you want synced → <strong>⋯ → Connections →</strong> add your integration.
            <em>Without this step the API can't see them.</em></li>
    </ol>
    <form method="post" class="row" style="margin:6px 0 0">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_token">
        <div class="field" style="flex:1;min-width:200px"><label>Integration secret</label>
            <input type="password" name="token" value="<?= e($token) ?>"
                   placeholder="ntn_…" autocomplete="off"></div>
        <button class="ghost" type="submit">Save token</button>
    </form>
    <?php if ($token !== ''): ?>
        <div class="row" style="margin-top:8px">
            <form method="post" style="margin:0"><?= csrf_field() ?>
                <input type="hidden" name="action" value="api_test">
                <button class="ghost" type="submit">Test connection</button></form>
            <form method="post" style="margin:0" class="row"><?= csrf_field() ?>
                <input type="hidden" name="action" value="api_pull">
                <select name="parent_id" class="db-mini">
                    <option value="">Top level</option>
                    <?php foreach ($topPages as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Pull shared pages</button></form>
        </div>
    <?php endif; ?>
</div>

<h2 style="margin:30px 0 10px;font-size:var(--fs-md)" class="muted">A note on the published site</h2>
<p class="muted" style="font-size:var(--fs-sm);max-width:70ch">
    Your published site (<code>stevensonportal.notion.site</code>) renders entirely in JavaScript,
    so it can't be read by a plain server-side fetch — the HTML that comes back is just a
    "JavaScript must be enabled" shell. The HTML export above is the reliable route for
    content, and the API is the reliable route for staying in sync.
</p>
<?php page_footer();
