<?php
/**
 * cron.php — daily alert emailer (run from the command line / Plesk scheduler).
 *
 * Emails you a digest of subscription renewals and important dates falling
 * within ALERT_LEAD_DAYS. Does nothing if there's nothing to report.
 *
 * Plesk → Tools & Settings → Scheduled Tasks (or the domain's Scheduled Tasks):
 *   Run a PHP script:  cron.php   daily, e.g. 08:00
 * Or a shell command:  php /var/www/vhosts/<domain>/httpdocs/cron.php
 *
 * Add --dry-run to print the digest instead of emailing it.
 */
require_once __DIR__ . '/lib.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$pdo = db();
$lead = ALERT_LEAD_DAYS;

$lines = [];

// Subscriptions renewing soon (active only).
$subs = $pdo->query("SELECT * FROM subscriptions WHERE active = 1")->fetchAll();
$subHits = [];
foreach ($subs as $s) {
    $d = days_until($s['next_renewal']);
    if ($d >= 0 && $d <= $lead) {
        $subHits[] = sprintf('  • %s — %s %s renews %s (%s)',
            $s['name'], $s['currency'], number_format((float)$s['amount'], 2),
            $s['next_renewal'], countdown_label($d));
    }
}
if ($subHits) {
    $lines[] = "SUBSCRIPTIONS RENEWING (next $lead days):";
    $lines = array_merge($lines, $subHits);
    $lines[] = '';
}

// Important dates soon (respecting yearly recurrence).
$dates = $pdo->query("SELECT * FROM important_dates")->fetchAll();
$dateHits = [];
foreach ($dates as $row) {
    $eff = next_occurrence($row['date'], (bool)$row['recurring']);
    $d = days_until($eff);
    if ($d >= 0 && $d <= $lead) {
        $dateHits[] = sprintf('  • %s — %s (%s) [%s]',
            $row['title'], $eff, countdown_label($d), $row['category']);
    }
}
if ($dateHits) {
    $lines[] = "IMPORTANT DATES (next $lead days):";
    $lines = array_merge($lines, $dateHits);
    $lines[] = '';
}

if (!$lines) {
    fwrite(STDOUT, "Nothing due in the next $lead days. No email sent.\n");
    exit(0);
}

$body = APP_NAME . " — upcoming reminders\n"
      . str_repeat('=', 40) . "\n\n"
      . implode("\n", $lines)
      . "\n— sent by your dashboard on " . date('Y-m-d H:i') . "\n";

$subject = APP_NAME . ': ' . (count($subHits)) . ' renewal(s), ' . (count($dateHits)) . ' date(s) coming up';

if ($dry) {
    fwrite(STDOUT, "Subject: $subject\n\n$body\n");
    exit(0);
}

$headers = 'From: ' . ALERT_EMAIL_FROM . "\r\n"
         . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

if (mail(ALERT_EMAIL_TO, $subject, $body, $headers)) {
    fwrite(STDOUT, "Alert email sent to " . ALERT_EMAIL_TO . ".\n");
    exit(0);
}
fwrite(STDERR, "mail() failed. Check the server's mail configuration in Plesk.\n");
exit(1);
