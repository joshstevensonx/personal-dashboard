<?php
/**
 * diag.php — standalone diagnostic. Deliberately has NO require statements,
 * so it still runs when config.php or a lib file is missing and every other
 * page is returning a blank 500.
 *
 * Visit https://yourdomain/diag.php   —   DELETE IT once the site is healthy.
 */
header('Content-Type: text/plain; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

function ok($b) { return $b ? '[ OK ]  ' : '[FAIL]  '; }

echo "Dashboard diagnostic\n" . str_repeat('=', 52) . "\n\n";

echo "PHP " . PHP_VERSION . "   " . php_uname('s') . "\n";
echo "docroot: " . __DIR__ . "\n\n";

/* ---- 1. required extensions --------------------------------------------- */
echo "EXTENSIONS\n";
foreach (['pdo_sqlite' => 'database (required)',
          'dom'        => 'Notion import (required for import.php)',
          'libxml'     => 'Notion import (required for import.php)',
          'curl'       => 'Notion API sync (import.php uses it)',
          'mbstring'   => 'text handling',
          'zip'        => 'zip upload/export (optional)',
          'openssl'    => 'encrypted backups (optional)'] as $ext => $why) {
    printf("  %s%-12s %s\n", ok(extension_loaded($ext)), $ext, $why);
}

/* ---- 2. files the app needs --------------------------------------------- */
echo "\nFILES\n";
$needed = [
    'config.php', 'db.php', 'lib.php', 'partials.php', 'index.php', 'login.php',
    'page.php', 'import.php', 'settings.php',
    'lib/migrations.php', 'lib/settings.php', 'lib/pages.php', 'lib/tasks.php',
    'lib/db_advanced.php', 'lib/page_ops.php', 'lib/notes.php',
    'lib/notion_import.php', 'lib/notion_api.php', 'lib/backup.php', 'lib/ics.php',
    'lib/productivity.php',
    'assets/tokens.css', 'assets/base.css', 'assets/app.css',
    'assets/responsive.css', 'assets/notion-render.css',
    'assets/app.js', 'assets/blocks.js',
];
$missing = [];
foreach ($needed as $f) {
    $exists = is_file(__DIR__ . '/' . $f);
    if (!$exists) { $missing[] = $f; }
    printf("  %s%s\n", ok($exists), $f);
}

/* ---- 3. writable data locations ----------------------------------------- */
echo "\nWRITABLE PATHS\n";
foreach (['data', 'uploads', '../dashboard-data'] as $d) {
    $p = __DIR__ . '/' . $d;
    $exists = is_dir($p);
    printf("  %s%-22s %s\n", ok($exists && is_writable($p)), $d,
        $exists ? (is_writable($p) ? 'writable' : 'NOT writable — chmod 775') : 'does not exist');
}

/* ---- 4. try to load config, and report the real error -------------------- */
echo "\nCONFIG\n";
if (!is_file(__DIR__ . '/config.php')) {
    echo "  [FAIL]  config.php is MISSING.\n";
    echo "          This alone makes every page return 500, because every page\n";
    echo "          includes it. A git deploy will delete it if config.php is\n";
    echo "          gitignored but not present on the server.\n";
    echo "          Fix: create config.php (copy config.sample.php) in " . __DIR__ . "\n";
} else {
    try {
        ob_start();
        include_once __DIR__ . '/config.php';
        ob_end_clean();
        echo "  [ OK ]  config.php parsed\n";
        foreach (['APP_USERNAME', 'APP_PASSWORD_HASH', 'DB_PATH', 'APP_TIMEZONE'] as $c) {
            printf("  %s%s%s\n", ok(defined($c)), $c,
                defined($c) && $c === 'DB_PATH' ? ' = ' . constant($c) : '');
        }
        if (defined('DB_PATH')) {
            $dir = dirname(DB_PATH);
            printf("  %sdatabase folder %s (%s)\n", ok(is_dir($dir) && is_writable($dir)),
                $dir, is_dir($dir) ? (is_writable($dir) ? 'writable' : 'NOT writable') : 'missing');
            if (extension_loaded('pdo_sqlite')) {
                try {
                    $pdo = new PDO('sqlite:' . DB_PATH);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $v = $pdo->query("SELECT COALESCE(MAX(version),0) FROM migrations")->fetchColumn();
                    $n = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
                    echo "  [ OK ]  database opens — schema v$v, $n tables\n";
                } catch (Throwable $e) {
                    echo "  [FAIL]  database: " . $e->getMessage() . "\n";
                }
            }
        }
    } catch (Throwable $e) {
        echo "  [FAIL]  config.php threw: " . $e->getMessage() . "\n";
    }
}

/* ---- 5. syntax-check every PHP file ------------------------------------- */
echo "\nPARSE CHECK (files that would fatal on include)\n";
$bad = 0;
foreach ($needed as $f) {
    if (!str_ends_with($f, '.php') || !is_file(__DIR__ . '/' . $f)) continue;
    $src = file_get_contents(__DIR__ . '/' . $f);
    // Cheap heuristic: unbalanced braces usually means a truncated upload.
    if (substr_count($src, '{') !== substr_count($src, '}')) {
        echo "  [WARN]  $f — braces unbalanced, file may be truncated\n";
        $bad++;
    }
}
if (!$bad) { echo "  [ OK ]  no truncated files detected\n"; }

/* ---- 6. verdict ---------------------------------------------------------- */
echo "\n" . str_repeat('=', 52) . "\nVERDICT\n";
if ($missing) {
    echo "  " . count($missing) . " file(s) missing from the server:\n";
    foreach ($missing as $m) { echo "    - $m\n"; }
    echo "  Upload these, then reload. A missing require_once is a fatal error,\n";
    echo "  which is why the page is blank rather than showing a message.\n";
} elseif (!is_file(__DIR__ . '/config.php')) {
    echo "  Recreate config.php — see above.\n";
} else {
    echo "  Files and config look fine. If pages still 500, check the PHP error\n";
    echo "  log in Plesk: Websites & Domains -> Logs -> error_log. The last few\n";
    echo "  lines will name the exact file and line.\n";
}
echo "\nDelete diag.php when you're done.\n";
