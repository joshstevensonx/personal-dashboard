<?php
/**
 * health.php — standalone deployment diagnostic. No login required.
 * Visit https://yourdomain/health.php after uploading to see what's wrong.
 * It checks PHP version, the pdo_sqlite driver, and whether the app can create
 * and write its database. DELETE THIS FILE once everything passes.
 */
header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/config.php';

function line($label, $ok, $detail = '') {
    echo ($ok ? '[ OK ]  ' : '[FAIL]  ') . $label . ($detail ? '  — ' . $detail : '') . "\n";
}

echo "Personal Dashboard — health check\n" . str_repeat('=', 40) . "\n\n";

// 1. PHP version
$ver = PHP_VERSION;
line("PHP version $ver (need 7.4+)", version_compare($ver, '7.4.0', '>='), $ver);

// 2. pdo_sqlite driver
$hasDriver = extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true);
line("pdo_sqlite driver present", $hasDriver,
    $hasDriver ? '' : 'Enable it in Plesk → PHP Settings, or pick a PHP 8.x handler.');

// 3. data/ directory
$dir = dirname(DB_PATH);
if (!is_dir($dir)) {
    $made = @mkdir($dir, 0775, true);
    line("data/ directory exists", $made, $made ? 'created it just now' : "could not create $dir");
} else {
    line("data/ directory exists", true, $dir);
}
$writable = is_dir($dir) && is_writable($dir);
line("data/ directory is writable by web user", $writable,
    $writable ? '' : "chmod this folder to 775 (or 755) in Plesk File Manager: $dir");

// 4. Try a real write to the folder
if ($writable) {
    $probe = $dir . '/.write-test';
    $wrote = @file_put_contents($probe, 'ok') !== false;
    line("can write a file into data/", $wrote);
    if ($wrote) { @unlink($probe); }
}

// 5. Try to actually open/create the SQLite database
if ($hasDriver) {
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS _health (x INTEGER)');
        $pdo->exec('DROP TABLE _health');
        line("opened + wrote to the SQLite database", true, DB_PATH);
    } catch (Throwable $ex) {
        line("opened + wrote to the SQLite database", false, $ex->getMessage());
    }
}

echo "\nIf every line says [ OK ], delete health.php and setup.php, then sign in normally.\n";
