<?php
/**
 * iCalendar (RFC 5545) feed generation.
 * Produces a VCALENDAR containing events and tasks that have due dates, so a
 * calendar app (Apple Calendar, Google Calendar, Outlook) can subscribe to it.
 */

function ics_escape(string $s): string
{
    // Order matters: backslash first.
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace(["\r\n", "\n", "\r"], '\\n', $s);
    $s = str_replace([',', ';'], ['\\,', '\\;'], $s);
    return $s;
}

/** Fold lines at 75 octets per RFC 5545. */
function ics_fold(string $line): string
{
    if (strlen($line) <= 75) {
        return $line . "\r\n";
    }
    $out = '';
    $first = true;
    while (strlen($line) > 0) {
        $take = $first ? 75 : 74;
        $chunk = substr($line, 0, $take);
        $line = substr($line, $take);
        $out .= ($first ? '' : ' ') . $chunk . "\r\n";
        $first = false;
    }
    return $out;
}

function ics_utc(string $datetime): string
{
    $ts = strtotime($datetime);
    return gmdate('Ymd\THis\Z', $ts ?: time());
}

function ics_date(string $datetime): string
{
    $ts = strtotime($datetime);
    return date('Ymd', $ts ?: time());
}

/**
 * Build the full .ics document.
 * @param array $events rows from `events`
 * @param array $tasks  rows from `tasks` (only ones with due_at)
 */
function build_ics(array $events, array $tasks, string $calName): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $out  = "BEGIN:VCALENDAR\r\n";
    $out .= "VERSION:2.0\r\n";
    $out .= "PRODID:-//Personal Dashboard//EN\r\n";
    $out .= "CALSCALE:GREGORIAN\r\n";
    $out .= "METHOD:PUBLISH\r\n";
    $out .= ics_fold('X-WR-CALNAME:' . ics_escape($calName));
    $out .= "X-PUBLISHED-TTL:PT1H\r\n";

    foreach ($events as $ev) {
        $out .= "BEGIN:VEVENT\r\n";
        $out .= ics_fold('UID:event-' . (int)$ev['id'] . '@' . $host);
        $out .= ics_fold('DTSTAMP:' . ics_utc($ev['created_at'] ?? 'now'));
        if (!empty($ev['all_day'])) {
            $out .= ics_fold('DTSTART;VALUE=DATE:' . ics_date($ev['start_at']));
            $end = $ev['end_at'] ?: $ev['start_at'];
            $out .= ics_fold('DTEND;VALUE=DATE:' . date('Ymd', strtotime($end . ' +1 day')));
        } else {
            $out .= ics_fold('DTSTART:' . ics_utc($ev['start_at']));
            $out .= ics_fold('DTEND:' . ics_utc($ev['end_at'] ?: $ev['start_at'] . ' +1 hour'));
        }
        $out .= ics_fold('SUMMARY:' . ics_escape($ev['title']));
        if (!empty($ev['notes']))    $out .= ics_fold('DESCRIPTION:' . ics_escape($ev['notes']));
        if (!empty($ev['location'])) $out .= ics_fold('LOCATION:' . ics_escape($ev['location']));
        $out .= "END:VEVENT\r\n";
    }

    foreach ($tasks as $t) {
        if (empty($t['due_at'])) continue;
        $out .= "BEGIN:VEVENT\r\n";
        $out .= ics_fold('UID:task-' . (int)$t['id'] . '@' . $host);
        $out .= ics_fold('DTSTAMP:' . ics_utc($t['created_at'] ?? 'now'));
        // Tasks show as all-day markers on their due date.
        $out .= ics_fold('DTSTART;VALUE=DATE:' . ics_date($t['due_at']));
        $out .= ics_fold('DTEND;VALUE=DATE:' . date('Ymd', strtotime($t['due_at'] . ' +1 day')));
        $prefix = ($t['status'] === 'done') ? '✓ ' : '';
        $out .= ics_fold('SUMMARY:' . ics_escape($prefix . $t['title']));
        if (!empty($t['notes'])) $out .= ics_fold('DESCRIPTION:' . ics_escape($t['notes']));
        $out .= ics_fold('STATUS:' . ($t['status'] === 'done' ? 'CONFIRMED' : 'TENTATIVE'));
        $out .= "END:VEVENT\r\n";
    }

    $out .= "END:VCALENDAR\r\n";
    return $out;
}

/** Get (or lazily create) the subscription token. */
function calendar_token(): string
{
    $pdo = db();
    $tok = $pdo->query("SELECT token FROM calendar_feeds ORDER BY id LIMIT 1")->fetchColumn();
    if ($tok) return (string)$tok;
    $tok = bin2hex(random_bytes(20));
    $pdo->prepare("INSERT INTO calendar_feeds (token, scope) VALUES (?, 'all')")->execute([$tok]);
    return $tok;
}
