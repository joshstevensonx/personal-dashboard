<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/notes.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

$error = '';

/* --------------------------------------------------------------- actions --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';

    if ($a === 'export_md') {
        $files = export_notes_markdown(($_POST['folder_id'] ?? '') !== '' ? (int)$_POST['folder_id'] : null);
        if (!$files) { flash('No notes to export.'); redirect('export.php'); }
        $zip = build_zip($files);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="notes-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . strlen($zip));
        echo $zip;
        exit;

    } elseif ($a === 'export_json') {
        $json = export_all_json();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="dashboard-export-' . date('Ymd-His') . '.json"');
        echo $json;
        exit;

    } elseif ($a === 'backup') {
        $pass = (string)($_POST['passphrase'] ?? '');
        if (strlen($pass) < 8) {
            $error = 'Use a passphrase of at least 8 characters.';
        } else {
            try {
                $payload = export_all_json();
                // Include note attachments list; the DB file itself is the source of truth.
                $blob = encrypt_blob($payload, $pass);
                $name = 'dashboard-backup-' . date('Ymd-His') . '.pdbk';
                $pdo->prepare("INSERT INTO backups (filename, size, encrypted) VALUES (?,?,1)")
                    ->execute([$name, strlen($blob)]);
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . strlen($blob));
                echo $blob;
                exit;
            } catch (Throwable $ex) {
                $error = 'Backup failed: ' . $ex->getMessage();
            }
        }

    } elseif ($a === 'verify') {
        $pass = (string)($_POST['passphrase'] ?? '');
        if (empty($_FILES['file']['tmp_name'])) {
            $error = 'Choose a .pdbk file to verify.';
        } else {
            try {
                $blob = (string)file_get_contents($_FILES['file']['tmp_name']);
                $plain = decrypt_blob($blob, $pass);
                $data = json_decode($plain, true);
                if (!is_array($data)) { throw new RuntimeException('Decrypted, but the contents are not valid JSON.'); }
                $counts = [];
                foreach ($data['tables'] ?? [] as $t => $rows) {
                    if (is_array($rows) && count($rows)) { $counts[$t] = count($rows); }
                }
                arsort($counts);
                flash('Backup verified — exported ' . ($data['exported_at'] ?? '?') . ' · '
                    . array_sum($counts) . ' rows across ' . count($counts) . ' tables.');
                redirect('export.php');
            } catch (Throwable $ex) {
                $error = $ex->getMessage();
            }
        }

    } elseif ($a === 'ocr_save') {
        $id = (int)($_POST['attachment_id'] ?? 0);
        $text = trim((string)($_POST['text'] ?? ''));
        if ($id && $text !== '') {
            $pdo->prepare("UPDATE attachments SET ocr_text = ? WHERE id = ?")->execute([$text, $id]);
            // Index OCR text into the note body's search coverage via a hidden note link.
            reindex_fts();
            if (!empty($_POST['ajax'])) { http_response_code(204); exit; }
            flash('OCR text saved and indexed.');
        }
        redirect('export.php');
    }
}

$folders = $pdo->query("SELECT * FROM folders ORDER BY name")->fetchAll();
$noteCount = (int)$pdo->query("SELECT COUNT(*) FROM notes WHERE deleted_at IS NULL")->fetchColumn();
$backupLog = $pdo->query("SELECT * FROM backups ORDER BY id DESC LIMIT 8")->fetchAll();
$autoBackups = is_dir(dirname(DB_PATH) . '/backups') ? glob(dirname(DB_PATH) . '/backups/*.sqlite') : [];
rsort($autoBackups);
$attachments = $pdo->query("SELECT a.*, n.title note_title FROM attachments a
                            LEFT JOIN notes n ON n.id=a.note_id ORDER BY a.id DESC LIMIT 40")->fetchAll();
$zipMode = class_exists('ZipArchive') ? 'ZipArchive' : 'built-in writer';

page_header('export.php');
?>
<h1>Export &amp; backup</h1>
<p class="sub">Get your data out in open formats, and keep encrypted backups you control.</p>

<?php if ($error): ?><div class="flash" style="background:color-mix(in srgb, var(--danger) 14%, transparent);
    border-color:var(--danger);color:var(--danger)"><?= e($error) ?></div><?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(290px,1fr));align-items:start">

    <div class="card">
        <h2>Markdown export</h2>
        <p class="muted" style="margin:0;font-size:13.5px">
            All <?= $noteCount ?> notes as <code>.md</code> files with YAML front-matter, zipped
            and foldered. Opens directly in Obsidian, iA Writer, or any editor.
        </p>
        <form method="post" class="row" style="margin:6px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="export_md">
            <div class="field"><label>Scope</label>
                <select name="folder_id">
                    <option value="">All notes</option>
                    <?php foreach ($folders as $f): ?>
                        <option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button type="submit">Download .zip</button>
        </form>
        <div class="muted" style="font-size:12px">Zip built with: <?= e($zipMode) ?></div>
    </div>

    <div class="card">
        <h2>Full data export (JSON)</h2>
        <p class="muted" style="margin:0;font-size:13.5px">
            Every table — tasks, notes, habits, goals, focus sessions, settings — as one
            readable JSON file. Unencrypted, so store it somewhere safe.
        </p>
        <form method="post" style="margin-top:auto">
            <?= csrf_field() ?><input type="hidden" name="action" value="export_json">
            <button type="submit">Download .json</button>
        </form>
    </div>

    <div class="card">
        <h2>Encrypted backup</h2>
        <p class="muted" style="margin:0;font-size:13.5px">
            AES-256-CBC with PBKDF2 (200k iterations) and an HMAC-SHA256 integrity check.
            The passphrase is never stored — <strong>lose it and the backup is unrecoverable.</strong>
        </p>
        <form method="post" class="row" style="margin:6px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="backup">
            <div class="field" style="flex:1;min-width:150px"><label>Passphrase (min 8 chars)</label>
                <input type="password" name="passphrase" required minlength="8" autocomplete="new-password"></div>
            <button type="submit">Download .pdbk</button>
        </form>
    </div>

    <div class="card">
        <h2>Verify a backup</h2>
        <p class="muted" style="margin:0;font-size:13.5px">
            Check that a <code>.pdbk</code> file really decrypts and its contents are intact.
            Worth doing once after your first backup — an untested backup isn't a backup.
        </p>
        <form method="post" enctype="multipart/form-data" class="row" style="margin:6px 0 0">
            <?= csrf_field() ?><input type="hidden" name="action" value="verify">
            <div class="field"><label>File</label><input type="file" name="file" accept=".pdbk" required></div>
            <div class="field"><label>Passphrase</label><input type="password" name="passphrase" required></div>
            <button class="ghost" type="submit">Verify</button>
        </form>
    </div>

    <div class="card">
        <h2>PDF export</h2>
        <p class="muted" style="margin:0;font-size:13.5px">
            Any page prints cleanly — the sidebar, top bar and controls are hidden by a print
            stylesheet. Open a note in <strong>Reading mode</strong>, then Print → Save as PDF.
        </p>
        <button class="ghost" type="button" onclick="window.print()">Print this page</button>
    </div>

    <div class="card">
        <h2>Automatic snapshots</h2>
        <p class="muted" style="margin:0;font-size:13.5px">
            The app copies its database to <code>data/backups/</code> before every schema change,
            and weekly via cron. Copies older than 30 days are pruned.
        </p>
        <div class="muted" style="font-size:12.5px">
            <?php if ($autoBackups): foreach (array_slice($autoBackups, 0, 5) as $f): ?>
                <div><?= e(basename($f)) ?> · <?= number_format(filesize($f) / 1024, 0) ?> KB</div>
            <?php endforeach; else: ?>
                <div>No snapshots yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<h2 style="margin:28px 0 10px">OCR — make images and PDFs searchable</h2>
<div class="card">
    <p class="muted" style="margin:0 0 10px;font-size:13.5px">
        OCR runs <strong>in your browser</strong> using tesseract.js — the file never leaves your
        machine, and nothing extra needs installing on the server. Pick an attachment, run OCR,
        and the extracted text is saved and included in note search.
    </p>
    <?php if (!$attachments): ?>
        <div class="muted" style="font-size:13px">No attachments yet — add one from a note.</div>
    <?php else: ?>
        <div class="list">
            <?php foreach ($attachments as $at): $img = preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $at['path']); ?>
                <div class="item">
                    <div class="grow">
                        <div class="title" style="font-size:14px"><?= e($at['filename']) ?></div>
                        <div class="meta">
                            <?= $at['note_title'] ? e($at['note_title']) . ' · ' : '' ?>
                            <?= number_format($at['size'] / 1024, 0) ?> KB
                            <?php if (!empty($at['ocr_text'])): ?>
                                <span class="pill ok">OCR done · <?= strlen($at['ocr_text']) ?> chars</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($img): ?>
                        <button class="ghost mini ocr-btn" type="button"
                                data-id="<?= (int)$at['id'] ?>" data-src="<?= e(attachment_url($at['path'])) ?>">
                            <?= !empty($at['ocr_text']) ? 'Re-run OCR' : 'Run OCR' ?></button>
                    <?php else: ?>
                        <span class="muted" style="font-size:12px">images only</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="ocr-status" class="muted" style="font-size:13px;margin-top:8px"></div>
        <form method="post" id="ocr-form" style="display:none">
            <?= csrf_field() ?><input type="hidden" name="action" value="ocr_save">
            <input type="hidden" name="attachment_id" id="ocr-id">
            <input type="hidden" name="text" id="ocr-text">
        </form>
    <?php endif; ?>
</div>

<?php if ($backupLog): ?>
    <h2 style="margin:28px 0 10px;font-size:14px" class="muted">BACKUP LOG</h2>
    <div class="list">
        <?php foreach ($backupLog as $bl): ?>
            <div class="item" style="padding:8px 12px">
                <span class="grow" style="font-size:13px"><?= e($bl['filename']) ?></span>
                <span class="pill"><?= number_format($bl['size'] / 1024, 0) ?> KB</span>
                <span class="muted" style="font-size:12px"><?= e($bl['created_at']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script src="assets/ocr.js"></script>
<?php page_footer();
