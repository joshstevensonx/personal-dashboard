<?php
/**
 * Configuration — DEPLOY-SAFE.
 *
 * This file contains NO secrets, so it is committed to git and can be
 * overwritten by a deploy without consequence.
 *
 * Real secrets and the database live OUTSIDE the document root, in the domain
 * folder one level above httpdocs. Git deployments only ever write inside
 * httpdocs, so nothing there can be deleted or overwritten by a push.
 *
 *   /var/www/vhosts/stevie-portal.co.za/
 *   ├── dashboard-secrets.php     ← your password hash + email (never in git)
 *   ├── dashboard-data/           ← SQLite database + backups (never in git)
 *   │   ├── dashboard.sqlite
 *   │   └── backups/
 *   └── httpdocs/                 ← everything git deploys
 *       ├── config.php            ← this file
 *       └── ...
 *
 * Create the two items above with Plesk File Manager once; see README.
 */

/* -- 1. Load secrets from outside the web root, if present ------------------ */
$__secrets = dirname(__DIR__) . '/dashboard-secrets.php';
if (is_file($__secrets)) {
    require_once $__secrets;
}

/* -- 2. Fall back to safe defaults so the app still boots ------------------- */
// Login. Default password is "changeme" — override it in dashboard-secrets.php.
if (!defined('APP_USERNAME'))      define('APP_USERNAME', 'admin');
if (!defined('APP_PASSWORD_HASH')) define('APP_PASSWORD_HASH', '$2y$10$WWfrPeO7JKicKohZJvsjyOO.bxD1VAhgxcK9tqr8vYKqLKF5FqlJW');

// App
if (!defined('APP_NAME'))     define('APP_NAME', 'Personal Dashboard');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Africa/Johannesburg');
if (!defined('APP_DEBUG'))    define('APP_DEBUG', false);

/**
 * Database location. Preferred: outside httpdocs so deploys can never touch it.
 * Falls back to the old in-webroot path if the external folder doesn't exist
 * yet, so an existing install keeps working until you move it.
 */
if (!defined('DB_PATH')) {
    $__ext = dirname(__DIR__) . '/dashboard-data';
    if (is_dir($__ext)) {
        define('DB_PATH', $__ext . '/dashboard.sqlite');
    } else {
        define('DB_PATH', __DIR__ . '/data/dashboard.sqlite');
    }
}

// Uploads: same idea — outside the web root when available.
if (!defined('UPLOAD_PATH')) {
    $__up = dirname(__DIR__) . '/dashboard-data/uploads';
    define('UPLOAD_PATH', is_dir($__up) ? $__up : __DIR__ . '/uploads');
}

// Email alerts (cron.php)
if (!defined('ALERT_EMAIL_TO'))   define('ALERT_EMAIL_TO', 'joshua.stevenson.100703@gmail.com');
if (!defined('ALERT_EMAIL_FROM')) define('ALERT_EMAIL_FROM', 'dashboard@stevie-portal.co.za');
if (!defined('ALERT_LEAD_DAYS'))  define('ALERT_LEAD_DAYS', 7);

/* -------------------------------------------------------------------------- */
date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
