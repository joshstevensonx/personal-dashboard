<?php
/**
 * Bootstrap — the one file every entry point loads.
 *
 * Why this exists: config.php has twice been deleted from the server by a git
 * deploy (it was gitignored, so the repo had no copy to restore). When that
 * happened, every single page fataled on a missing require and the whole site
 * returned 500.
 *
 * Now the defaults live HERE, in a file that is always committed. config.php
 * and dashboard-secrets.php are optional overrides. If they vanish, the app
 * still boots — it just uses defaults and says so.
 */

/* -- 1. Secrets from outside the web root (never in git) -------------------- */
$__secrets = dirname(__DIR__, 2) . '/dashboard-secrets.php';
if (is_file($__secrets)) {
    require_once $__secrets;
}

/* -- 2. Optional local overrides ------------------------------------------- */
$__cfg = dirname(__DIR__) . '/config.php';
if (is_file($__cfg)) {
    require_once $__cfg;
}

/* -- 3. Defaults for anything still undefined ------------------------------ */
// Login. Default password is "changeme" — override it in dashboard-secrets.php.
if (!defined('APP_USERNAME'))      define('APP_USERNAME', 'admin');
if (!defined('APP_PASSWORD_HASH')) define('APP_PASSWORD_HASH', '$2y$10$WWfrPeO7JKicKohZJvsjyOO.bxD1VAhgxcK9tqr8vYKqLKF5FqlJW');

if (!defined('APP_NAME'))     define('APP_NAME', 'Personal Dashboard');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Africa/Johannesburg');
if (!defined('APP_DEBUG'))    define('APP_DEBUG', false);

/**
 * Storage. Preferred location is outside httpdocs so deploys can't touch it;
 * falls back to the in-webroot folder when that doesn't exist yet.
 */
if (!defined('DB_PATH')) {
    $__ext = dirname(__DIR__, 2) . '/dashboard-data';
    define('DB_PATH', is_dir($__ext)
        ? $__ext . '/dashboard.sqlite'
        : dirname(__DIR__) . '/data/dashboard.sqlite');
}
if (!defined('UPLOAD_PATH')) {
    $__up = dirname(__DIR__, 2) . '/dashboard-data/uploads';
    define('UPLOAD_PATH', is_dir($__up) ? $__up : dirname(__DIR__) . '/uploads');
}

if (!defined('ALERT_EMAIL_TO'))   define('ALERT_EMAIL_TO', 'joshua.stevenson.100703@gmail.com');
if (!defined('ALERT_EMAIL_FROM')) define('ALERT_EMAIL_FROM', 'dashboard@stevie-portal.co.za');
if (!defined('ALERT_LEAD_DAYS'))  define('ALERT_LEAD_DAYS', 7);

/** True when config.php was missing — surfaced in Settings, not fatal. */
if (!defined('CONFIG_PRESENT')) { define('CONFIG_PRESENT', is_file($__cfg)); }

/* -------------------------------------------------------------------------- */
date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    // Never leak stack traces to visitors, but do record them.
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
