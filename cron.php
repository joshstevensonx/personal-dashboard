<?php
/**
 * cron.php — daily digest + scheduled maintenance.
 * Run from Plesk → Scheduled Tasks (Run a PHP script), daily.
 *
 *   php cron.php            send the digest and run maintenance
 *   php cron.php --dry-run  print what would be sent, change nothing
 *
 * Covers: subscription renewals, important dates, task deadlines, due reminders,
 * and rolling recurring tasks forward.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/tasks.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$pdo = db();
$lead = ALERT_LEAD_DAYS;
$sections = [];

/* ---------------------------------------------------------- subscriptions -- */
$subHits = [];
foreach ($pdo->query("SELECT * FROM subscriptions WHERE active = 1") as $s) {
    $d = days_until($s['next_renewal']);
    if ($d >= 0 && $d <= $lead) {
        $subHits[] = sprintf('  • %s — %s %s renews %s (%s)',
            $s['name'], $s['currency'], number_format((float)$s['amount'], 2),
            $s['next_renewal'], countdown_label($d));
    }
}
if ($subHits) { $sections[] = "SUBSCRIPTIONS RENEWING (next $lead days):\n" . implode("\n", $subHits); }

/* ------------------------------------------------------------ key dates ---- */
$dateHits = [];
foreach ($pdo->query("SELECT * FROM important_dates") as $row) {
    $eff = next_occurrence($row['date'], (bool)$row['recurring']);
    $d = days_until($eff);
    if ($d >= 0 && $d <= $lead) {
        $dateHits[] = sprintf('  • %s — %s (%s) [%s]', $row['title'], $eff, countdown_label($d), $row['category']);
    }
}
if ($dateHits) { $sections[] = "IMPORTANT DATES (next $lead days):\n" . implode("\n", $dateHits); }

/* ------------------------------------------------------------- overdue ----- */
$overdue = $pdo->query(
    "SELECT title, due_at, priority FROM tasks
     WHERE status IN ('open','doing') AND due_at IS NOT NULL AND date(due_at) < date('now')
     ORDER BY due_at LIMIT 25"
)->fetchAll();
if ($overdue) {
    $lines = array_map(function ($t) {
        $p = PRIORITIES[$t['priority']]['short'] ?? 'P2';
        return sprintf('  • [%s] %s — due %s (%s)', $p, $t['title'],
            substr($t['due_at'], 0, 10), countdown_label(days_until(substr($t['due_at'], 0, 10))));
    }, $overdue);
    $sections[] = "OVERDUE TASKS:\n" . implode("\n", $lines);
}

/* ------------------------------------------------------------ due soon ----- */
$soon = $pdo->prepare(
    "SELECT title, due_at, priority FROM tasks
     WHERE status IN ('open','doing') AND due_at IS NOT NULL
       AND date(due_at) BETWEEN date('now') AND date('now', ?)
     ORDER BY due_at LIMIT 25"
);
$soon->execute(["+$lead day"]);
$soonRows = $soon->fetchAll();
if ($soonRows) {
    $lines = array_map(function ($t) {
        $p = PRIORITIES[$t['priority']]['short'] ?? 'P2';
        return sprintf('  • [%s] %s — due %s (%s)', $p, $t['title'],
            substr($t['due_at'], 0, 10), countdown_label(days_until(substr($t['due_at'], 0, 10))));
    }, $soonRows);
    $sections[] = "TASKS DUE SOON (next $lead days):\n" . implode("\n", $lines);
}

/* ------------------------------------------------------------- events ------ */
$evs = $pdo->prepare(
    "SELECT title, start_at, all_day FROM events
     WHERE date(start_at) BETWEEN date('now') AND date('now', ?) ORDER BY start_at LIMIT 25"
);
$evs->execute(["+$lead day"]);
$evRows = $evs->fetchAll();
if ($evRows) {
    $lines = array_map(fn($ev) => sprintf('  • %s — %s', $ev['title'],
        $ev['all_day'] ? date('D j M', strtotime($ev['start_at'])) . ' (all day)'
                       : date('D j M H:i', strtotime($ev['start_at']))), $evRows);
    $sections[] = "UPCOMING EVENTS (next $lead days):\n" . implode("\n", $lines);
}

/* ------------------------------------------------- explicit task reminders -- */
$due = $pdo->query(
    "SELECT r.id, r.item_id, t.title, r.remind_at
     FROM reminders r JOIN tasks t ON t.id = r.item_id
     WHERE r.item_type = 'task' AND r.sent_at IS NULL
       AND datetime(r.remind_at) <= datetime('now')
       AND t.status IN ('open','doing')"
)->fetchAll();
if ($due) {
    $lines = array_map(fn($r) => sprintf('  • %s (reminder set for %s)', $r['title'], $r['remind_at']), $due);
    $sections[] = "REMINDERS:\n" . implode("\n", $lines);
}

/* --------------------------------------- maintenance: recurring rollover ---- */
// Any recurring task whose due date has passed while still open gets rolled to
// its next occurrence, so repeating chores never sit permanently overdue.
$rolled = [];
$stale = $pdo->query(
    "SELECT id, title, due_at, recurrence FROM tasks
     WHERE status IN ('open','doing') AND recurrence IS NOT NULL AND recurrence <> ''
       AND due_at IS NOT NULL AND date(due_at) < date('now','-1 day')"
)->fetchAll();
foreach ($stale as $t) {
    $next = next_recurrence($t['recurrence'], $t['due_at']);
    if (!$next) continue;
    // Advance until it lands in the future.
    $guard = 0;
    while (strtotime($next) < strtotime('today') && $guard++ < 500) {
        $next = next_recurrence($t['recurrence'], $next) ?: $next;
        if ($guard > 490) break;
    }
    if (!$dry) {
        $pdo->prepare("UPDATE tasks SET due_at = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$next, $t['id']]);
    }
    $rolled[] = sprintf('  • %s → %s', $t['title'], substr($next, 0, 10));
}
if ($rolled) { $sections[] = "RECURRING TASKS ROLLED FORWARD:\n" . implode("\n", $rolled); }

/* ------------------------------------------------------ maintenance: backup - */
// Keep a rolling weekly snapshot; prune copies older than 30 days.
if (!$dry) {
    $bdir = dirname(DB_PATH) . '/backups';
    $latest = is_dir($bdir) ? glob($bdir . '/*auto.sqlite') : [];
    $needBackup = true;
    foreach ($latest as $f) {
        if (filemtime($f) > strtotime('-7 days')) { $needBackup = false; break; }
    }
    if ($needBackup) { backup_database('auto'); }
    foreach ((is_dir($bdir) ? glob($bdir . '/*.sqlite') : []) as $f) {
        if (filemtime($f) < strtotime('-30 days')) { @unlink($f); }
    }
}

/* --------------------------------------------------------------- output ----- */
if (!$sections) {
    fwrite(STDOUT, "Nothing due in the next $lead days. No email sent.\n");
    exit(0);
}

$body = APP_NAME . " — daily briefing\n" . str_repeat('=', 44) . "\n\n"
      . implode("\n\n", $sections)
      . "\n\n— sent by your dashboard on " . date('Y-m-d H:i') . "\n";

$subject = sprintf('%s: %d overdue, %d due soon, %d event(s)',
    APP_NAME, count($overdue), count($soonRows), count($evRows));

if ($dry) {
    fwrite(STDOUT, "Subject: $subject\n\n$body\n");
    exit(0);
}

$headers = 'From: ' . ALERT_EMAIL_FROM . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
if (mail(ALERT_EMAIL_TO, $subject, $body, $headers)) {
    if ($due) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $due));
        $pdo->exec("UPDATE reminders SET sent_at = datetime('now') WHERE id IN ($ids)");
    }
    fwrite(STDOUT, "Briefing sent to " . ALERT_EMAIL_TO . ".\n");
    exit(0);
}
fwrite(STDERR, "mail() failed. Check the server's mail configuration in Plesk.\n");
exit(1);
