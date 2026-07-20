<?php
/**
 * SECRETS — place this file OUTSIDE httpdocs.
 *
 * Correct location on your Plesk server:
 *   /var/www/vhosts/stevie-portal.co.za/dashboard-secrets.php
 *
 * (i.e. one level ABOVE httpdocs — the same folder that contains httpdocs).
 * Rename it to dashboard-secrets.php once it's there.
 *
 * Why outside httpdocs:
 *   - Git deploys only write inside httpdocs, so this file is never
 *     overwritten or deleted when you push.
 *   - It is not reachable over the web at all, even if PHP stops executing.
 *
 * Never commit this file to git.
 */

/* -- Login ------------------------------------------------------------------
 * Generate a hash by visiting setup.php in your browser, or on the CLI:
 *   php -r "echo password_hash('YourStrongPassword', PASSWORD_DEFAULT), PHP_EOL;"
 */
define('APP_USERNAME', 'admin');
define('APP_PASSWORD_HASH', 'PASTE_YOUR_BCRYPT_HASH_HERE');

/* -- App -------------------------------------------------------------------- */
define('APP_NAME', 'Personal Dashboard');
define('APP_TIMEZONE', 'Africa/Johannesburg');

// Set to true only while troubleshooting, then back to false.
define('APP_DEBUG', false);

/* -- Email alerts (cron.php) ------------------------------------------------ */
define('ALERT_EMAIL_TO', 'joshua.stevenson.100703@gmail.com');
define('ALERT_EMAIL_FROM', 'dashboard@stevie-portal.co.za');
define('ALERT_LEAD_DAYS', 7);

/* -- Storage ----------------------------------------------------------------
 * Leave these commented out to use the defaults:
 *   <domain>/dashboard-data/dashboard.sqlite
 *   <domain>/dashboard-data/uploads
 */
// define('DB_PATH', '/var/www/vhosts/stevie-portal.co.za/dashboard-data/dashboard.sqlite');
// define('UPLOAD_PATH', '/var/www/vhosts/stevie-portal.co.za/dashboard-data/uploads');
