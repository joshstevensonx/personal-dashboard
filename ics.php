<?php
/**
 * Public ICS feed endpoint — authenticated by a secret token, NOT by session,
 * so calendar apps can poll it. Subscribe to:
 *   https://yourdomain/ics.php?token=YOUR_TOKEN
 *
 * The token is shown on calendar.php. Treat the URL as a secret: anyone with it
 * can read your event and task titles. Regenerate it there if it leaks.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ics.php';

$pdo = db();
$token = $_GET['token'] ?? '';

$st = $pdo->prepare("SELECT * FROM calendar_feeds WHERE token = ?");
$st->execute([$token]);
$feed = $st->fetch();

if (!$feed || $token === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Invalid or missing calendar token.\n");
}

// Look 1 year back and 2 years forward — keeps the feed small but complete.
$events = $pdo->query(
    "SELECT * FROM events
     WHERE date(start_at) BETWEEN date('now','-1 year') AND date('now','+2 year')
     ORDER BY start_at"
)->fetchAll();

$tasks = $pdo->query(
    "SELECT * FROM tasks
     WHERE due_at IS NOT NULL
       AND date(due_at) BETWEEN date('now','-1 year') AND date('now','+2 year')
     ORDER BY due_at"
)->fetchAll();

$body = build_ics($events, $tasks, APP_NAME);

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="dashboard.ics"');
header('Cache-Control: max-age=900, public');
echo $body;
