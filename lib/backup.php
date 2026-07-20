<?php
/**
 * Export and encrypted backup helpers.
 *
 * Encryption: AES-256-CBC via openssl, key derived from a passphrase with
 * PBKDF2 (200k iterations, random salt), authenticated with HMAC-SHA256
 * (encrypt-then-MAC). File layout:
 *
 *   "PDBK1" | salt(16) | iv(16) | hmac(32) | ciphertext
 *
 * The passphrase is never stored — if you lose it, the backup is unreadable.
 */

const BACKUP_MAGIC = 'PDBK1';

function encrypt_blob(string $plain, string $passphrase): string
{
    $salt = random_bytes(16);
    $iv   = random_bytes(16);
    // 64 bytes: 32 for AES key, 32 for the HMAC key.
    $keys = hash_pbkdf2('sha256', $passphrase, $salt, 200000, 64, true);
    $encKey = substr($keys, 0, 32);
    $macKey = substr($keys, 32, 32);

    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
    return BACKUP_MAGIC . $salt . $iv . $mac . $cipher;
}

function decrypt_blob(string $blob, string $passphrase): string
{
    $magicLen = strlen(BACKUP_MAGIC);
    if (substr($blob, 0, $magicLen) !== BACKUP_MAGIC) {
        throw new RuntimeException('Not a dashboard backup file.');
    }
    $p = $magicLen;
    $salt = substr($blob, $p, 16); $p += 16;
    $iv   = substr($blob, $p, 16); $p += 16;
    $mac  = substr($blob, $p, 32); $p += 32;
    $cipher = substr($blob, $p);

    $keys = hash_pbkdf2('sha256', $passphrase, $salt, 200000, 64, true);
    $encKey = substr($keys, 0, 32);
    $macKey = substr($keys, 32, 32);

    $calc = hash_hmac('sha256', $iv . $cipher, $macKey, true);
    if (!hash_equals($calc, $mac)) {
        throw new RuntimeException('Wrong passphrase, or the file is corrupt.');
    }
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        throw new RuntimeException('Decryption failed.');
    }
    return $plain;
}

/* ------------------------------------------------------------- markdown ---- */

function safe_filename(string $s): string
{
    $s = preg_replace('/[^\p{L}\p{N} _.-]+/u', '', $s);
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return $s !== '' ? mb_substr($s, 0, 80) : 'untitled';
}

/** Export notes as markdown files inside a zip (or a single .md string). */
function export_notes_markdown(?int $folderId = null): array
{
    $pdo = db();
    $sql = "SELECT n.*, f.name AS folder FROM notes n LEFT JOIN folders f ON f.id = n.folder_id
            WHERE n.deleted_at IS NULL";
    $args = [];
    if ($folderId !== null) { $sql .= " AND n.folder_id = ?"; $args[] = $folderId; }
    $sql .= " ORDER BY n.title";
    $st = $pdo->prepare($sql);
    $st->execute($args);

    $files = [];
    foreach ($st as $n) {
        $tagList = tags_for('note', [$n['id']])[$n['id']] ?? '';
        $front = "---\ntitle: " . $n['title'] . "\n"
               . ($tagList !== '' ? "tags: [" . $tagList . "]\n" : '')
               . "created: " . $n['created_at'] . "\n"
               . ($n['updated_at'] ? "updated: " . $n['updated_at'] . "\n" : '')
               . "---\n\n";
        $path = ($n['folder'] ? safe_filename($n['folder']) . '/' : '') . safe_filename($n['title']) . '.md';
        // Avoid collisions on duplicate titles.
        $i = 2;
        while (isset($files[$path])) {
            $path = ($n['folder'] ? safe_filename($n['folder']) . '/' : '') . safe_filename($n['title']) . "-$i.md";
            $i++;
        }
        $files[$path] = $front . $n['body'];
    }
    return $files;
}

/**
 * Build a ZIP archive in memory. Uses ZipArchive when available, otherwise
 * writes a minimal store-only (uncompressed) zip by hand — Plesk hosts don't
 * always have the zip extension enabled.
 */
function build_zip(array $files): string
{
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'pdz');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            foreach ($files as $path => $content) {
                $zip->addFromString($path, $content);
            }
            $zip->close();
            $data = (string)file_get_contents($tmp);
            @unlink($tmp);
            return $data;
        }
        @unlink($tmp);
    }
    return build_zip_store($files);
}

/** Minimal ZIP writer (store method, no compression, no dependencies). */
function build_zip_store(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', $name);
        $crc = crc32($content);
        $len = strlen($content);
        $dosTime = 0x2100; // fixed timestamp keeps this simple and deterministic
        $dosDate = 0x5800;

        $header = "\x50\x4b\x03\x04"
                . pack('v', 20) . pack('v', 0) . pack('v', 0)
                . pack('v', $dosTime) . pack('v', $dosDate)
                . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                . pack('v', strlen($name)) . pack('v', 0)
                . $name;
        $local .= $header . $content;

        $central .= "\x50\x4b\x01\x02"
                 . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
                 . pack('v', $dosTime) . pack('v', $dosDate)
                 . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                 . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
                 . pack('v', 0) . pack('v', 0) . pack('V', 32)
                 . pack('V', $offset) . $name;

        $offset += strlen($header) + $len;
        $count++;
    }

    $end = "\x50\x4b\x05\x06" . pack('v', 0) . pack('v', 0)
         . pack('v', $count) . pack('v', $count)
         . pack('V', strlen($central)) . pack('V', $offset) . pack('v', 0);

    return $local . $central . $end;
}

/** Everything as JSON — the machine-readable full backup. */
function export_all_json(): string
{
    $pdo = db();
    $tables = ['inbox','subscriptions','important_dates','bookmarks','snippets','devices','settings',
               'projects','board_columns','tasks','tags','taggables','reminders','events','calendar_feeds',
               'focus_sessions','habits','habit_entries','goals','goal_progress','daily_plans',
               'folders','notes','note_links','note_revisions','attachments','templates','smart_collections'];
    $out = ['app' => APP_NAME, 'exported_at' => date('c'), 'schema_version' => 0, 'tables' => []];
    try {
        $out['schema_version'] = (int)$pdo->query("SELECT MAX(version) FROM migrations")->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    foreach ($tables as $t) {
        try {
            $out['tables'][$t] = $pdo->query("SELECT * FROM $t")->fetchAll();
        } catch (Throwable $e) {
            $out['tables'][$t] = [];   // table may not exist on older schemas
        }
    }
    return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
