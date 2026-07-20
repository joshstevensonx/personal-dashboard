<?php
/**
 * Sample configuration — safe to commit.
 *
 * On the server, copy this to config.php and fill in the real values.
 * config.php itself is gitignored so your password hash and email never
 * end up in the repository, and a deploy never overwrites your live settings.
 */

// ---- Login ------------------------------------------------------------------
// Generate a hash in the browser with setup.php, or on the CLI:
//   php -r "echo password_hash('YourStrongPassword', PASSWORD_DEFAULT), PHP_EOL;"
define('APP_USERNAME', 'admin');
define('APP_PASSWORD_HASH', 'PASTE_YOUR_BCRYPT_HASH_HERE');

// ---- App --------------------------------------------------------------------
define('APP_NAME', 'Personal Dashboard');
define('APP_TIMEZONE', 'Africa/Johannesburg');
define('APP_DEBUG', false);

define('DB_PATH', __DIR__ . '/data/dashboard.sqlite');

// ---- Email alerts (cron.php) -------------------------------------------------
define('ALERT_EMAIL_TO', 'you@example.com');
define('ALERT_EMAIL_FROM', 'dashboard@example.com');
define('ALERT_LEAD_DAYS', 7);

// -----------------------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
