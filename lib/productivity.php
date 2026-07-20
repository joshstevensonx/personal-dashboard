<?php
/**
 * Productivity helpers: focus sessions, habit streaks, goal progress.
 */

const FOCUS_KINDS = ['pomodoro' => 'Pomodoro', 'deep' => 'Deep work', 'break' => 'Break'];
const CADENCES = ['daily' => 'Every day', 'weekly' => 'Weekly target', 'interval' => 'Every N days'];

/* --------------------------------------------------------------- streaks --- */

/**
 * Current and longest streak for a daily habit.
 * A streak counts consecutive days ending today or yesterday (so a day isn't
 * "broken" until it's actually missed).
 */
function habit_streaks(int $habitId): array
{
    $rows = db()->prepare("SELECT date FROM habit_entries WHERE habit_id = ? AND count > 0 ORDER BY date DESC");
    $rows->execute([$habitId]);
    $dates = array_column($rows->fetchAll(), 'date');
    if (!$dates) return ['current' => 0, 'longest' => 0, 'total' => 0];

    $set = array_flip($dates);
    // Current streak: walk back from today (allow yesterday as the anchor).
    $current = 0;
    $cursor = new DateTime('today');
    if (!isset($set[$cursor->format('Y-m-d')])) {
        $cursor->modify('-1 day');
        if (!isset($set[$cursor->format('Y-m-d')])) {
            $current = 0;
            $cursor = null;
        }
    }
    if ($cursor) {
        while (isset($set[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor->modify('-1 day');
        }
    }

    // Longest streak across all history.
    $sorted = $dates;
    sort($sorted);
    $longest = $run = 0;
    $prev = null;
    foreach ($sorted as $d) {
        if ($prev !== null && (strtotime($d) - strtotime($prev)) === 86400) {
            $run++;
        } else {
            $run = 1;
        }
        $longest = max($longest, $run);
        $prev = $d;
    }

    return ['current' => $current, 'longest' => $longest, 'total' => count($dates)];
}

/** Completion map for the last N days: ['Y-m-d' => count] */
function habit_history(int $habitId, int $days = 84): array
{
    $st = db()->prepare(
        "SELECT date, count FROM habit_entries
         WHERE habit_id = ? AND date >= date('now', ?) ORDER BY date"
    );
    $st->execute([$habitId, "-$days day"]);
    $out = [];
    foreach ($st as $r) { $out[$r['date']] = (int)$r['count']; }
    return $out;
}

/* ---------------------------------------------------------------- focus ---- */

function focus_totals(string $since = '-7 day'): array
{
    $st = db()->prepare(
        "SELECT kind, COUNT(*) sessions, COALESCE(SUM(duration_sec),0) secs
         FROM focus_sessions
         WHERE ended_at IS NOT NULL AND date(started_at) >= date('now', ?)
         GROUP BY kind"
    );
    $st->execute([$since]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['kind']] = ['sessions' => (int)$r['sessions'], 'secs' => (int)$r['secs']];
    }
    return $out;
}

/** Focus seconds per day for the last N days: ['Y-m-d' => secs] */
function focus_by_day(int $days = 14): array
{
    $st = db()->prepare(
        "SELECT date(started_at) d, COALESCE(SUM(duration_sec),0) secs
         FROM focus_sessions
         WHERE ended_at IS NOT NULL AND kind <> 'break' AND date(started_at) >= date('now', ?)
         GROUP BY d ORDER BY d"
    );
    $st->execute(["-$days day"]);
    $out = [];
    foreach ($st as $r) { $out[$r['d']] = (int)$r['secs']; }
    return $out;
}

function hhmm(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm';
    return $seconds . 's';
}

/* ---------------------------------------------------------------- goals ---- */

function goal_percent(array $g): int
{
    $target = (float)($g['target_value'] ?? 0);
    if ($target <= 0) return 0;
    $pct = ((float)$g['current_value'] / $target) * 100;
    return (int)max(0, min(100, round($pct)));
}

function recalc_goal(int $goalId): void
{
    $pdo = db();
    $sum = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM goal_progress WHERE goal_id = ?");
    $sum->execute([$goalId]);
    $pdo->prepare("UPDATE goals SET current_value = ? WHERE id = ?")
        ->execute([(float)$sum->fetchColumn(), $goalId]);
}

/* ----------------------------------------------------------- daily plan ---- */

function daily_plan(string $date): array
{
    $st = db()->prepare("SELECT * FROM daily_plans WHERE date = ?");
    $st->execute([$date]);
    return $st->fetch() ?: ['date' => $date, 'intention' => '', 'plan' => '', 'review' => '', 'energy' => null, 'mood' => null];
}

function save_daily_plan(string $date, array $f): void
{
    db()->prepare(
        "INSERT INTO daily_plans (date, intention, plan, review, energy, mood) VALUES (?,?,?,?,?,?)
         ON CONFLICT(date) DO UPDATE SET intention=excluded.intention, plan=excluded.plan,
             review=excluded.review, energy=excluded.energy, mood=excluded.mood"
    )->execute([
        $date, $f['intention'] ?? '', $f['plan'] ?? '', $f['review'] ?? '',
        ($f['energy'] ?? '') !== '' ? (int)$f['energy'] : null,
        ($f['mood'] ?? '') !== '' ? (int)$f['mood'] : null,
    ]);
}
