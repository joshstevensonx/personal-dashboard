<?php
/**
 * Forward-only migration runner.
 *
 * Rules (see ROADMAP.md §3):
 *  - Migrations are numbered and run in order, once each.
 *  - Additive only: CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD COLUMN.
 *  - Existing tables are never dropped or rewritten.
 *  - A timestamped copy of the database is written to data/backups/ before any
 *    migration runs for the first time in a given version jump.
 */

/** Every migration: version => [name, SQL or callable]. Never renumber these. */
function migration_list(): array
{
    return [
        1 => ['baseline_existing_tables', "
            CREATE TABLE IF NOT EXISTS inbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT 'note',
                done INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL, amount REAL NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT 'USD',
                cycle TEXT NOT NULL DEFAULT 'monthly',
                next_renewal TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS important_dates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL, date TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT 'general',
                recurring INTEGER NOT NULL DEFAULT 0,
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS bookmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL, url TEXT NOT NULL, tags TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS snippets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL, body TEXT NOT NULL, tags TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                platform TEXT NOT NULL DEFAULT 'mac',
                method TEXT NOT NULL DEFAULT 'rustdesk',
                address TEXT, share_note TEXT, notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        "],

        2 => ['settings_store', "
            CREATE TABLE IF NOT EXISTS settings (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        "],
    ];
}

function current_schema_version(PDO $pdo): int
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        version INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $v = $pdo->query("SELECT COALESCE(MAX(version), 0) FROM migrations")->fetchColumn();
    return (int)$v;
}

/** Copy the SQLite file to data/backups/ before applying migrations. */
function backup_database(string $reason = 'migration'): ?string
{
    if (!is_file(DB_PATH)) {
        return null;
    }
    $dir = dirname(DB_PATH) . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    $dest = sprintf('%s/%s-%s.sqlite', $dir, date('Ymd-His'), preg_replace('/\W+/', '', $reason));
    return @copy(DB_PATH, $dest) ? $dest : null;
}

/** Apply any migrations newer than the recorded version. */
function run_migrations(PDO $pdo): void
{
    $have = current_schema_version($pdo);
    $all = migration_list();
    $pending = array_filter($all, fn($k) => $k > $have, ARRAY_FILTER_USE_KEY);
    if (!$pending) {
        return;
    }

    // Back up whenever the database already contains user tables — this covers a
    // pre-migration database (version 0 but full of live data), which is exactly
    // the riskiest case. A brand-new empty database has nothing worth copying.
    $existingTables = (int)$pdo->query(
        "SELECT COUNT(*) FROM sqlite_master
         WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'migrations'"
    )->fetchColumn();
    if ($existingTables > 0) {
        backup_database('premigrate');
    }

    ksort($pending);
    $stmt = $pdo->prepare("INSERT INTO migrations (version, name) VALUES (?, ?)");
    foreach ($pending as $version => [$name, $sql]) {
        if (is_callable($sql)) {
            $sql($pdo);
        } else {
            $pdo->exec($sql);
        }
        $stmt->execute([$version, $name]);
    }
}
