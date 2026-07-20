# Personal Dashboard — Roadmap & Data Model

Target: turn the current 5-module dashboard into a full personal productivity suite
(tasks + notes + focus tracking + analytics), delivered as a **web app + PWA** running on
your Plesk host at `stevie-portal.co.za`, usable from Mac, Windows, and iPhone.

- **Stack:** PHP 8.4 + SQLite (existing), vanilla JS, no build step, no Composer.
- **Delivery:** installable PWA — Dock icon on macOS, home-screen app on iPhone.
- **Constraint set:** no SSH, no Composer, no persistent processes, shared Apache/Plesk.

---

## 1. Architecture decisions

| Decision | Choice | Why |
|---|---|---|
| Backend | PHP 8.4, procedural + small helpers | Already running, zero-config on Plesk, verified working |
| Database | SQLite (single file, `data/dashboard.sqlite`) | No DB server to configure; verified writable on your host |
| Search | SQLite **FTS5** virtual table | Built into SQLite; gives instant full-text note search |
| Frontend | Vanilla JS + CSS custom properties | No build step — critical with no SSH/npm on the host |
| Charts | Chart.js (CDN, with local fallback copy) | Analytics and streak visuals |
| Graph view | Force-directed canvas render (vis-style, self-written or D3 from CDN) | Note link graph |
| Editor | `textarea` + live markdown preview; rich mode via `contenteditable` | Avoids a heavy framework |
| Offline | Service worker + Cache API; IndexedDB queue for writes | PWA offline support |
| OCR | **tesseract.js in the browser** | Tesseract binary can't be installed without SSH |
| PDF export | Print-stylesheet → browser "Save as PDF" (phase 6 may vendor a PHP lib) | No Composer available |
| Auth | Existing single-user session + bcrypt | Already built and working |
| Schema changes | **Migration runner** (see §3) | You have live data — nothing may be destroyed |

### Why not native SwiftUI
Your UI list included *native SwiftUI, Dock/menu-bar integration, OS-wide Spotlight
launcher, floating assistant widget*. These require a Mac-only Swift codebase and would
abandon Windows/iPhone access. The PWA route recovers most of the *feel*:

| You asked for | What you get on the web track |
|---|---|
| Native SwiftUI interface | Installed PWA in its own window, native-ish styling |
| Dock / menu bar integration | Real Dock icon + own window when installed (no menu-bar applet) |
| Spotlight-style launcher | In-app command palette (`Cmd/Ctrl+K`) — app-scoped, not OS-wide |
| Floating assistant widget | Quick-capture overlay hotkey inside the app |
| Keyboard-first navigation | Full — implemented in Phase 1 |

If you later want the true native shell, Phase 1 exposes a clean JSON API so a SwiftUI
client can talk to the same database.

---

## 2. Phase plan

Each phase ships working, tested, deployable code before the next begins.

### Phase 1 — App shell & foundation
The frame everything else drops into.
- Sidebar navigation replacing the current top bar
- **Command palette** (`Cmd/Ctrl+K`): jump to any page, run any action, create anything
- **Keyboard-first navigation**: global shortcuts, focus rings, `?` shortcut cheatsheet
- **Theming**: dark/light/auto, accent color picker, 3–4 preset themes, custom theme editor
- **Layout presets** + resizable/reorderable dashboard widgets (persisted per user)
- **Custom shortcuts** — remap any command
- **PWA**: manifest, icons, service worker, installable on Mac + iPhone
- **Settings store** (`settings` table) + **migration runner**
- JSON API layer (`api.php`) for future native clients
- *Carries over the 5 existing modules unchanged into the new shell*

### Phase 2 — Tasks & scheduling
- Projects/boards; **kanban** with drag-and-drop columns
- Tasks, **subtasks** (unlimited nesting), **priorities** (P0–P3), **tags & categories**
- List view, board view, filters, saved views
- Due dates, start dates, **deadline tracking** with overdue rollup
- **Recurring tasks** (RRULE-lite: daily/weekly/monthly/custom interval)
- **Reminders**: email via existing cron + optional Web Push (VAPID)
- **ICS feed** — a secret-token URL your Apple/Google Calendar subscribes to
- Calendar view + local `events` table (**offline calendar**)

### Phase 3 — Productivity & tracking
- **Pomodoro timer** (configurable work/break, auto-cycle, audio cue)
- **Focus sessions** & **deep work tracking** — start/stop, link to a task, log interruptions
- **Habit tracker** with daily/weekly cadence + **streaks**
- **Goal tracking** — targets, progress entries, sub-goals
- **Daily planning dashboard** — today's intention, planned tasks, time blocks, evening review

### Phase 4 — Notes & knowledge
- **Markdown editor** with live preview + **split view**
- **Rich text editor** mode; **code blocks** with syntax highlighting
- **Embedded media** — image/file uploads into notes
- **Nested folders**, **tags**, **pinned notes**, **smart collections** (saved queries)
- **Daily notes / journal** auto-created per day
- **Templates / snippets** (integrates the existing snippet vault)
- **Wiki-style backlinks** via `[[Note Title]]` + **linked references** panel
- **Graph view** of note links
- **Reading mode** (distraction-free, typography-tuned)
- **Full-text search** across notes, tasks, and OCR'd attachments (FTS5)

### Phase 5 — Reporting & analytics
- **Productivity analytics**: tasks completed, focus hours, trends over time
- **Time reports**: by day/week/project/tag, exportable CSV
- **Habit streak** charts and consistency heatmap
- **Weekly review** workflow: auto-assembled summary + reflection prompts

### Phase 6 — Export, backup & OCR
- **Markdown export** (single note, folder, or whole vault as .zip)
- **PDF export** via print stylesheet
- **Version history** — note revisions with diff + restore
- **Encrypted backups** — AES-256 encrypted .zip of the DB + uploads, downloadable
- **OCR** for uploaded PDFs/images via tesseract.js, text indexed into FTS5
- Offline hardening: background sync queue, conflict handling

---

## 3. Migration strategy (important)

You already have live data in five tables. Every phase adds tables, so the app gets a
migration runner instead of raw `CREATE TABLE` calls:

```
migrations (
  version     INTEGER PRIMARY KEY,
  name        TEXT NOT NULL,
  applied_at  TEXT NOT NULL DEFAULT (datetime('now'))
)
```

Rules:
1. Migrations are numbered, forward-only, and run automatically on page load.
2. Only additive changes (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ADD COLUMN`).
3. Existing tables (`inbox`, `subscriptions`, `important_dates`, `bookmarks`, `snippets`,
   `devices`) are never dropped or rewritten.
4. Before each phase deploys, the app writes a timestamped copy of the SQLite file to
   `data/backups/` automatically.

---

## 4. Data model

Existing tables stay as-is. New tables by phase:

### Phase 1 — system

```sql
settings (
  key         TEXT PRIMARY KEY,      -- 'theme', 'accent', 'layout', 'shortcuts'
  value       TEXT NOT NULL,         -- JSON or scalar
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

migrations ( version INTEGER PRIMARY KEY, name TEXT, applied_at TEXT );
```

### Phase 2 — tasks & scheduling

```sql
projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  color TEXT,
  view TEXT NOT NULL DEFAULT 'list',      -- list | board | calendar
  position INTEGER NOT NULL DEFAULT 0,
  archived INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

board_columns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  wip_limit INTEGER
);

tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
  column_id  INTEGER REFERENCES board_columns(id) ON DELETE SET NULL,
  parent_id  INTEGER REFERENCES tasks(id) ON DELETE CASCADE,   -- subtasks
  title TEXT NOT NULL,
  notes TEXT,
  priority INTEGER NOT NULL DEFAULT 2,     -- 0=P0 urgent … 3=P3 low
  status TEXT NOT NULL DEFAULT 'open',     -- open | doing | done | cancelled
  start_at TEXT, due_at TEXT, completed_at TEXT,
  estimate_min INTEGER,
  position INTEGER NOT NULL DEFAULT 0,
  recurrence TEXT,                         -- e.g. 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO'
  recurrence_parent_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT
);

tags ( id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, color TEXT );

taggables (                               -- polymorphic: tasks, notes, events
  tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
  item_type TEXT NOT NULL,                -- 'task' | 'note' | 'event'
  item_id INTEGER NOT NULL,
  PRIMARY KEY (tag_id, item_type, item_id)
);

reminders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_type TEXT NOT NULL DEFAULT 'task',
  item_id INTEGER NOT NULL,
  remind_at TEXT NOT NULL,
  method TEXT NOT NULL DEFAULT 'email',   -- email | push
  sent_at TEXT
);

events (                                   -- offline calendar
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL, notes TEXT, location TEXT,
  start_at TEXT NOT NULL, end_at TEXT,
  all_day INTEGER NOT NULL DEFAULT 0,
  recurrence TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

calendar_feeds (                           -- secret-token ICS subscription URLs
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT UNIQUE NOT NULL,
  scope TEXT NOT NULL DEFAULT 'all',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
```

### Phase 3 — productivity

```sql
focus_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
  kind TEXT NOT NULL DEFAULT 'pomodoro',   -- pomodoro | deep | break
  started_at TEXT NOT NULL, ended_at TEXT,
  duration_sec INTEGER,
  interruptions INTEGER NOT NULL DEFAULT 0,
  notes TEXT
);

habits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, color TEXT,
  cadence TEXT NOT NULL DEFAULT 'daily',   -- daily | weekly | interval
  target INTEGER NOT NULL DEFAULT 1,
  archived INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

habit_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  habit_id INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
  date TEXT NOT NULL,                      -- YYYY-MM-DD
  count INTEGER NOT NULL DEFAULT 1,
  note TEXT,
  UNIQUE (habit_id, date)
);

goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  parent_id INTEGER REFERENCES goals(id) ON DELETE CASCADE,
  title TEXT NOT NULL, description TEXT,
  target_value REAL, current_value REAL NOT NULL DEFAULT 0, unit TEXT,
  due_date TEXT,
  status TEXT NOT NULL DEFAULT 'active',   -- active | done | paused | dropped
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

goal_progress (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  goal_id INTEGER NOT NULL REFERENCES goals(id) ON DELETE CASCADE,
  date TEXT NOT NULL, value REAL NOT NULL, note TEXT
);

daily_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT UNIQUE NOT NULL,
  intention TEXT, plan TEXT, review TEXT,
  energy INTEGER, mood INTEGER
);
```

### Phase 4 — notes & knowledge

```sql
folders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  parent_id INTEGER REFERENCES folders(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0
);

notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  folder_id INTEGER REFERENCES folders(id) ON DELETE SET NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  format TEXT NOT NULL DEFAULT 'md',       -- md | rich
  pinned INTEGER NOT NULL DEFAULT 0,
  daily_date TEXT,                          -- set for journal/daily notes
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT,
  deleted_at TEXT                           -- soft delete / trash
);

note_links (                                -- backlinks + graph edges
  source_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
  target_id INTEGER REFERENCES notes(id) ON DELETE CASCADE,
  target_title TEXT,                        -- unresolved [[links]] kept as text
  PRIMARY KEY (source_id, target_title)
);

note_revisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
  body TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  note_id INTEGER REFERENCES notes(id) ON DELETE CASCADE,
  filename TEXT NOT NULL, path TEXT NOT NULL,
  mime TEXT, size INTEGER,
  ocr_text TEXT,                            -- filled by tesseract.js (Phase 6)
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, body TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'note'         -- note | task | daily
);

smart_collections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  query TEXT NOT NULL,                      -- JSON filter definition
  icon TEXT, position INTEGER NOT NULL DEFAULT 0
);

-- Full-text search (FTS5), kept in sync by triggers
CREATE VIRTUAL TABLE notes_fts USING fts5(
  title, body, content='notes', content_rowid='id'
);
```

### Phase 6 — backup

```sql
backups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT NOT NULL, size INTEGER,
  encrypted INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
```

---

## 5. Target file structure

```
httpdocs/
├── index.php              Dashboard (widget grid, layout presets)
├── api.php                JSON API (future native clients)
├── tasks.php              List + board views
├── calendar.php           Calendar + events
├── ics.php                ICS feed endpoint (token auth)
├── focus.php              Pomodoro / deep work
├── habits.php             Habits + streaks
├── goals.php              Goals
├── planner.php            Daily planning
├── notes.php              Note list + editor (split view)
├── graph.php              Note graph view
├── search.php             Global FTS search
├── reports.php            Analytics + time reports
├── review.php             Weekly review
├── export.php             Markdown / PDF / backup
├── settings.php           Theme, accent, shortcuts, layout
├── inbox.php  subscriptions.php  dates.php  bookmarks.php  remote.php   (existing)
├── cron.php               Reminders, recurring tasks, auto-backup
├── lib/                   db, auth, migrations, markdown, ics, recurrence helpers
├── assets/                css (themes), js (palette, kanban, editor, charts), icons
├── uploads/               note media (web-blocked, served via a PHP handler)
├── data/                  SQLite DB + auto backups (web-blocked)
├── manifest.webmanifest   PWA manifest
└── sw.js                  Service worker (offline)
```

---

## 6. Known constraints & how each is handled

| Constraint | Impact | Handling |
|---|---|---|
| No SSH / Composer | Can't install PHP libs or binaries | Zero-dependency PHP; vendor JS from CDN with local fallback |
| No Tesseract binary | Server-side OCR impossible | tesseract.js runs in the browser, result posted back and indexed |
| No persistent processes | No WebSockets / background workers | Cron for scheduled work; polling where live updates are needed |
| Shared Apache | Upload size + execution limits | Chunked uploads; keep backups streamed, not buffered |
| Single SQLite writer | Concurrent writes can lock | WAL mode + short transactions (fine for single-user) |
| iOS PWA limits | No true background push on iPhone unless installed | Email reminders as the reliable channel; Web Push as a bonus |
| Your data is live | Schema changes are risky | Forward-only migration runner + automatic pre-phase backups |

---

## 7. Suggested build order

1. **Phase 1 — shell** (sidebar, palette, themes, PWA, migrations, API)
2. **Phase 2 — tasks & scheduling** (largest single chunk of the request)
3. **Phase 3 — productivity & tracking**
4. **Phase 4 — notes & knowledge**
5. **Phase 5 — reporting**
6. **Phase 6 — export, backup, OCR, offline hardening**

Each phase ends with: lint on PHP 8.4, a scripted smoke test of every new route, a
migration dry-run against a copy of your live database, and a deploy checklist.
