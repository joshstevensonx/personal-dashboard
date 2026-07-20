<?php
/**
 * SQLite connection + schema management.
 * Schema is applied by the migration runner (lib/migrations.php) — forward-only
 * and additive, so existing data is never dropped or rewritten.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/migrations.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    try {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite PHP extension is not enabled.');
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // WAL improves concurrency; harmless if the filesystem refuses it.
        try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Throwable $e) { /* ignore */ }

        run_migrations($pdo);
    } catch (Throwable $ex) {
        db_fail($ex, $dir);
    }
    return $pdo;
}

/** Show a clear, safe message instead of a blank 500 when the DB can't open. */
function db_fail(Throwable $ex, string $dir): void
{
    http_response_code(500);
    $writable = is_dir($dir) && is_writable($dir) ? 'yes' : 'NO — chmod it to 775 in Plesk File Manager';
    $detail = defined('APP_DEBUG') && APP_DEBUG ? '<pre>' . htmlspecialchars($ex->getMessage()) . '</pre>' : '';
    echo "<!doctype html><meta charset='utf-8'><title>Database error</title>"
       . "<div style='max-width:640px;margin:12vh auto;font:15px/1.6 system-ui;padding:0 20px'>"
       . "<h1>The dashboard can't open its database</h1>"
       . "<p>This is a setup issue on the server, not a bug. Most likely one of:</p>"
       . "<ul>"
       . "<li>The <code>data/</code> folder isn't writable. Writable now: <strong>$writable</strong></li>"
       . "<li>The <code>pdo_sqlite</code> PHP extension is off (Plesk → PHP Settings, or use a PHP 8.x handler).</li>"
       . "</ul>"
       . "<p>Upload <code>health.php</code> and open it in your browser for an exact diagnosis.</p>"
       . $detail
       . "</div>";
    exit;
}
