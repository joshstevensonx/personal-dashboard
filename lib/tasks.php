<?php
/**
 * Task engine: priorities, tags, subtask trees, recurrence.
 */

const PRIORITIES = [
    0 => ['label' => 'P0 · Urgent', 'short' => 'P0', 'class' => 'danger'],
    1 => ['label' => 'P1 · High',   'short' => 'P1', 'class' => 'warn'],
    2 => ['label' => 'P2 · Normal', 'short' => 'P2', 'class' => ''],
    3 => ['label' => 'P3 · Low',    'short' => 'P3', 'class' => 'muted-pill'],
];

const STATUSES = ['open' => 'Open', 'doing' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];

/* ------------------------------------------------------------------ tags --- */

/** Turn "work, urgent" into tag ids, creating tags as needed. */
function sync_tags(string $itemType, int $itemId, string $csv): void
{
    $pdo = db();
    $names = array_filter(array_map('trim', explode(',', $csv)), fn($n) => $n !== '');
    $names = array_slice(array_unique($names), 0, 20);

    $pdo->prepare("DELETE FROM taggables WHERE item_type = ? AND item_id = ?")
        ->execute([$itemType, $itemId]);

    $find = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
    $make = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
    $link = $pdo->prepare("INSERT OR IGNORE INTO taggables (tag_id, item_type, item_id) VALUES (?,?,?)");

    foreach ($names as $n) {
        $find->execute([$n]);
        $id = $find->fetchColumn();
        if (!$id) {
            $make->execute([$n]);
            $id = (int)$pdo->lastInsertId();
        }
        $link->execute([(int)$id, $itemType, $itemId]);
    }
}

/** tags for a set of items: returns [itemId => "a, b"] */
function tags_for(string $itemType, array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT tg.item_id, t.name FROM taggables tg
            JOIN tags t ON t.id = tg.tag_id
            WHERE tg.item_type = ? AND tg.item_id IN ($in)
            ORDER BY t.name";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$itemType], $ids));
    $out = [];
    foreach ($st as $r) {
        $out[$r['item_id']][] = $r['name'];
    }
    return array_map(fn($a) => implode(', ', $a), $out);
}

function all_tags(): array
{
    return db()->query("SELECT t.name, COUNT(tg.item_id) c FROM tags t
                        LEFT JOIN taggables tg ON tg.tag_id = t.id
                        GROUP BY t.id ORDER BY c DESC, t.name")->fetchAll();
}

/* ------------------------------------------------------------ recurrence --- */

/**
 * Minimal RRULE subset: FREQ=DAILY|WEEKLY|MONTHLY|YEARLY;INTERVAL=n
 * Returns the next date after $from, or null if the rule is empty/invalid.
 */
function next_recurrence(string $rule, string $from): ?string
{
    if (trim($rule) === '') return null;
    $parts = [];
    foreach (explode(';', strtoupper($rule)) as $bit) {
        if (strpos($bit, '=') !== false) {
            [$k, $v] = explode('=', $bit, 2);
            $parts[trim($k)] = trim($v);
        }
    }
    $freq = $parts['FREQ'] ?? '';
    $interval = max(1, (int)($parts['INTERVAL'] ?? 1));
    $map = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'];
    if (!isset($map[$freq])) return null;

    $d = date_create($from) ?: date_create('today');
    $d->modify("+{$interval} {$map[$freq]}");
    return $d->format('Y-m-d H:i:s');
}

function recurrence_label(?string $rule): string
{
    if (!$rule) return '';
    $up = strtoupper($rule);
    $n = 1;
    if (preg_match('/INTERVAL=(\d+)/', $up, $m)) $n = (int)$m[1];
    foreach (['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'] as $f => $w) {
        if (strpos($up, 'FREQ=' . $f) !== false) {
            return $n === 1 ? "every $w" : "every $n {$w}s";
        }
    }
    return 'repeats';
}

/** Preset recurrence options for the UI. */
function recurrence_options(): array
{
    return [
        ''                          => 'Does not repeat',
        'FREQ=DAILY;INTERVAL=1'     => 'Every day',
        'FREQ=WEEKLY;INTERVAL=1'    => 'Every week',
        'FREQ=WEEKLY;INTERVAL=2'    => 'Every 2 weeks',
        'FREQ=MONTHLY;INTERVAL=1'   => 'Every month',
        'FREQ=MONTHLY;INTERVAL=3'   => 'Every 3 months',
        'FREQ=YEARLY;INTERVAL=1'    => 'Every year',
    ];
}

/**
 * Complete a task. If it recurs, spawn the next instance and return its id.
 */
function complete_task(int $id): ?int
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) return null;

    $pdo->prepare("UPDATE tasks SET status='done', completed_at=datetime('now'), updated_at=datetime('now') WHERE id = ?")
        ->execute([$id]);

    if (empty($t['recurrence'])) return null;

    $base = $t['due_at'] ?: date('Y-m-d H:i:s');
    $nextDue = next_recurrence($t['recurrence'], $base);
    if (!$nextDue) return null;

    $nextStart = null;
    if (!empty($t['start_at']) && !empty($t['due_at'])) {
        $delta = strtotime($t['due_at']) - strtotime($t['start_at']);
        $nextStart = date('Y-m-d H:i:s', strtotime($nextDue) - $delta);
    }

    $ins = $pdo->prepare(
        "INSERT INTO tasks (project_id, column_id, parent_id, title, notes, priority, status,
                            start_at, due_at, estimate_min, position, recurrence, recurrence_parent_id)
         VALUES (?,?,?,?,?,?, 'open', ?,?,?,?,?,?)"
    );
    $ins->execute([
        $t['project_id'], $t['column_id'], $t['parent_id'], $t['title'], $t['notes'],
        $t['priority'], $nextStart, $nextDue, $t['estimate_min'], $t['position'],
        $t['recurrence'], $t['recurrence_parent_id'] ?: $t['id'],
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Carry the tags across to the new instance.
    $pdo->prepare("INSERT OR IGNORE INTO taggables (tag_id, item_type, item_id)
                   SELECT tag_id, 'task', ? FROM taggables WHERE item_type='task' AND item_id = ?")
        ->execute([$newId, $id]);

    return $newId;
}

/* ----------------------------------------------------------------- views --- */

function default_columns(): array
{
    return ['To do', 'In progress', 'Blocked', 'Done'];
}

function ensure_project_columns(int $projectId): void
{
    $pdo = db();
    $c = $pdo->prepare("SELECT COUNT(*) FROM board_columns WHERE project_id = ?");
    $c->execute([$projectId]);
    if ((int)$c->fetchColumn() > 0) return;

    $ins = $pdo->prepare("INSERT INTO board_columns (project_id, name, position) VALUES (?,?,?)");
    foreach (default_columns() as $i => $name) {
        $ins->execute([$projectId, $name, $i]);
    }
}

/** Human due-date state: [label, cssClass] */
function due_state(?string $dueAt): array
{
    if (!$dueAt) return ['', ''];
    $due = strtotime($dueAt);
    $today = strtotime('today');
    $days = (int)floor(($due - $today) / 86400);
    if ($days < 0)  return [abs($days) . 'd overdue', 'danger'];
    if ($days === 0) return ['Today', 'warn'];
    if ($days === 1) return ['Tomorrow', 'warn'];
    if ($days <= 7)  return ["in $days days", ''];
    return [date('j M', $due), ''];
}

/** Build a nested tree from a flat task list. */
function task_tree(array $rows): array
{
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[$r['parent_id'] ?? 0][] = $r;
    }
    return $byParent;
}
