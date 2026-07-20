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

        3 => ['tasks_and_scheduling', "
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                color TEXT,
                view TEXT NOT NULL DEFAULT 'list',
                position INTEGER NOT NULL DEFAULT 0,
                archived INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS board_columns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                wip_limit INTEGER
            );

            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
                column_id INTEGER REFERENCES board_columns(id) ON DELETE SET NULL,
                parent_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                notes TEXT,
                priority INTEGER NOT NULL DEFAULT 2,
                status TEXT NOT NULL DEFAULT 'open',
                start_at TEXT,
                due_at TEXT,
                completed_at TEXT,
                estimate_min INTEGER,
                position INTEGER NOT NULL DEFAULT 0,
                recurrence TEXT,
                recurrence_parent_id INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);
            CREATE INDEX IF NOT EXISTS idx_tasks_due ON tasks(due_at);
            CREATE INDEX IF NOT EXISTS idx_tasks_parent ON tasks(parent_id);

            CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                color TEXT
            );

            CREATE TABLE IF NOT EXISTS taggables (
                tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                item_type TEXT NOT NULL,
                item_id INTEGER NOT NULL,
                PRIMARY KEY (tag_id, item_type, item_id)
            );

            CREATE TABLE IF NOT EXISTS reminders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_type TEXT NOT NULL DEFAULT 'task',
                item_id INTEGER NOT NULL,
                remind_at TEXT NOT NULL,
                method TEXT NOT NULL DEFAULT 'email',
                sent_at TEXT
            );

            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                notes TEXT,
                location TEXT,
                start_at TEXT NOT NULL,
                end_at TEXT,
                all_day INTEGER NOT NULL DEFAULT 0,
                recurrence TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_events_start ON events(start_at);

            CREATE TABLE IF NOT EXISTS calendar_feeds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE NOT NULL,
                scope TEXT NOT NULL DEFAULT 'all',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        "],

        4 => ['productivity_tracking', "
            CREATE TABLE IF NOT EXISTS focus_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
                kind TEXT NOT NULL DEFAULT 'pomodoro',
                label TEXT,
                started_at TEXT NOT NULL,
                ended_at TEXT,
                duration_sec INTEGER,
                interruptions INTEGER NOT NULL DEFAULT 0,
                notes TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_focus_started ON focus_sessions(started_at);

            CREATE TABLE IF NOT EXISTS habits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                color TEXT,
                cadence TEXT NOT NULL DEFAULT 'daily',
                target INTEGER NOT NULL DEFAULT 1,
                archived INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS habit_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                habit_id INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
                date TEXT NOT NULL,
                count INTEGER NOT NULL DEFAULT 1,
                note TEXT,
                UNIQUE (habit_id, date)
            );

            CREATE TABLE IF NOT EXISTS goals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER REFERENCES goals(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                description TEXT,
                target_value REAL,
                current_value REAL NOT NULL DEFAULT 0,
                unit TEXT,
                due_date TEXT,
                status TEXT NOT NULL DEFAULT 'active',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS goal_progress (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                goal_id INTEGER NOT NULL REFERENCES goals(id) ON DELETE CASCADE,
                date TEXT NOT NULL,
                value REAL NOT NULL,
                note TEXT
            );

            CREATE TABLE IF NOT EXISTS daily_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT UNIQUE NOT NULL,
                intention TEXT,
                plan TEXT,
                review TEXT,
                energy INTEGER,
                mood INTEGER
            );
        "],

        5 => ['notes_and_knowledge', "
            CREATE TABLE IF NOT EXISTS folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER REFERENCES folders(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folder_id INTEGER REFERENCES folders(id) ON DELETE SET NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL DEFAULT '',
                format TEXT NOT NULL DEFAULT 'md',
                pinned INTEGER NOT NULL DEFAULT 0,
                daily_date TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT,
                deleted_at TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_notes_folder ON notes(folder_id);
            CREATE INDEX IF NOT EXISTS idx_notes_daily ON notes(daily_date);

            CREATE TABLE IF NOT EXISTS note_links (
                source_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
                target_id INTEGER REFERENCES notes(id) ON DELETE CASCADE,
                target_title TEXT NOT NULL,
                PRIMARY KEY (source_id, target_title)
            );

            CREATE TABLE IF NOT EXISTS note_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                note_id INTEGER REFERENCES notes(id) ON DELETE CASCADE,
                filename TEXT NOT NULL,
                path TEXT NOT NULL,
                mime TEXT,
                size INTEGER,
                ocr_text TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                body TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT 'note'
            );

            CREATE TABLE IF NOT EXISTS smart_collections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                query TEXT NOT NULL,
                icon TEXT,
                position INTEGER NOT NULL DEFAULT 0
            );
        "],

        6 => ['backups_log', "
            CREATE TABLE IF NOT EXISTS backups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                size INTEGER,
                encrypted INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        "],

        7 => ['pages_and_blocks', "
            -- Everything-is-a-page. Pages nest infinitely.
            CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER REFERENCES pages(id) ON DELETE CASCADE,
                title TEXT NOT NULL DEFAULT 'Untitled',
                icon TEXT,                       -- emoji
                cover TEXT,                      -- gradient name or image path
                is_database INTEGER NOT NULL DEFAULT 0,
                db_view TEXT NOT NULL DEFAULT 'table',   -- table | board | gallery | list
                db_group_by TEXT,                -- property key used for board columns
                position INTEGER NOT NULL DEFAULT 0,
                favorite INTEGER NOT NULL DEFAULT 0,
                archived INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id);

            -- Ordered, typed, nestable content blocks.
            CREATE TABLE IF NOT EXISTS blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                parent_block_id INTEGER REFERENCES blocks(id) ON DELETE CASCADE,
                type TEXT NOT NULL DEFAULT 'paragraph',
                content TEXT NOT NULL DEFAULT '',
                props TEXT,                      -- JSON: {checked, collapsed, lang, color, ...}
                position INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_blocks_page ON blocks(page_id, position);

            -- Database column definitions (one row per property per database page).
            CREATE TABLE IF NOT EXISTS db_properties (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                database_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                key TEXT NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'text', -- text|number|select|multi|date|checkbox|url
                options TEXT,                      -- JSON array for select/multi
                position INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_dbprops ON db_properties(database_id, position);

            -- Property values for a row (a row IS a page whose parent is the database).
            CREATE TABLE IF NOT EXISTS page_values (
                page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                key TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (page_id, key)
            );
        "],

        8 => ['notion_advanced', function (PDO $pdo) {
            // Comments, anchored to a page and optionally to a single block.
            $pdo->exec("CREATE TABLE IF NOT EXISTS page_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                block_id INTEGER REFERENCES blocks(id) ON DELETE CASCADE,
                body TEXT NOT NULL,
                resolved INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comments_page ON page_comments(page_id)");

            // Multiple saved views per database, each with its own config.
            $pdo->exec("CREATE TABLE IF NOT EXISTS db_views (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                database_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                name TEXT NOT NULL DEFAULT 'Table',
                type TEXT NOT NULL DEFAULT 'table',
                group_by TEXT,
                sort_key TEXT,
                sort_dir TEXT NOT NULL DEFAULT 'asc',
                filter_key TEXT,
                filter_op TEXT NOT NULL DEFAULT 'contains',
                filter_value TEXT,
                hidden_props TEXT,
                position INTEGER NOT NULL DEFAULT 0
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_views_db ON db_views(database_id, position)");

            // Additive columns — ALTER TABLE ADD COLUMN fails if it already
            // exists, so each is guarded.
            $cols = [];
            foreach ($pdo->query("PRAGMA table_info(pages)") as $c) { $cols[] = $c['name']; }
            if (!in_array('is_template', $cols, true)) {
                $pdo->exec("ALTER TABLE pages ADD COLUMN is_template INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('locked', $cols, true)) {
                $pdo->exec("ALTER TABLE pages ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
            }

            $bcols = [];
            foreach ($pdo->query("PRAGMA table_info(blocks)") as $c) { $bcols[] = $c['name']; }
            if (!in_array('indent', $bcols, true)) {
                $pdo->exec("ALTER TABLE blocks ADD COLUMN indent INTEGER NOT NULL DEFAULT 0");
            }

            // Seed one default view for every existing database page so the
            // multi-view UI has something to show immediately.
            $dbs = $pdo->query("SELECT id, db_view, db_group_by FROM pages WHERE is_database = 1")->fetchAll();
            $ins = $pdo->prepare("INSERT INTO db_views (database_id, name, type, group_by, position)
                                  VALUES (?,?,?,?,0)");
            foreach ($dbs as $d) {
                $have = $pdo->prepare("SELECT COUNT(*) FROM db_views WHERE database_id = ?");
                $have->execute([$d['id']]);
                if ((int)$have->fetchColumn() === 0) {
                    $type = $d['db_view'] ?: 'table';
                    $ins->execute([$d['id'], ucfirst($type), $type, $d['db_group_by']]);
                }
            }
        }],

        9 => ['notion_provenance', function (PDO $pdo) {
            // Kept separate from migration 8 on purpose: version 8 had already
            // been applied on live installs, so columns added there would never
            // have run. New schema work always gets a new version number.
            $cols = [];
            foreach ($pdo->query("PRAGMA table_info(pages)") as $c) { $cols[] = $c['name']; }
            if (!in_array('notion_id', $cols, true)) {
                $pdo->exec("ALTER TABLE pages ADD COLUMN notion_id TEXT");
            }
            if (!in_array('notion_synced_at', $cols, true)) {
                $pdo->exec("ALTER TABLE pages ADD COLUMN notion_synced_at TEXT");
            }
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_notion ON pages(notion_id)");
        }],
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
