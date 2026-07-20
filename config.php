<?php
/**
 * Personal Utility Dashboard — configuration
 * -----------------------------------------------------------------------------
 * Edit the values below after uploading. Nothing else needs changing to run.
 */

// ---- Login ------------------------------------------------------------------
// Single-user login. Change the username, then generate a password hash:
//   php -r "echo password_hash('YourStrongPassword', PASSWORD_DEFAULT), PHP_EOL;"
// Paste the result into APP_PASSWORD_HASH.
define('APP_USERNAME', 'admin');
define('APP_PASSWORD_HASH', '$2y$10$WWfrPeO7JKicKohZJvsjyOO.bxD1VAhgxcK9tqr8vYKqLKF5FqlJW'); // default: "changeme"

// ---- App --------------------------------------------------------------------
define('APP_NAME', 'Personal Dashboard');
define('APP_TIMEZONE', 'UTC'); // e.g. 'America/New_York', 'Europe/London'

// Set to true only while troubleshooting — shows PHP errors on screen instead
// of a blank 500. Turn it back to false once the app works.
define('APP_DEBUG', false);

// Where the SQLite database file lives. Default: /data/dashboard.sqlite next to
// this file. The /data folder is blocked from web access by data/.htaccess.
define('DB_PATH', __DIR__ . '/data/dashboard.sqlite');

// ---- Email alerts (used by cron.php) ----------------------------------------
// Alerts for upcoming renewals and important dates are emailed here.
// On Plesk, PHP mail() works out of the box with the server's mail service.
define('ALERT_EMAIL_TO', 'you@example.com');
define('ALERT_EMAIL_FROM', 'dashboard@example.com');

// How many days ahead to warn about renewals / dates.
define('ALERT_LEAD_DAYS', 7);

// -----------------------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
