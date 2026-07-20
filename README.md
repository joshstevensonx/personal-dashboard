# Personal Utility Dashboard

A single, private web app that bundles four everyday tools behind one login:

- **Quick-capture inbox** — dump notes, to-dos, and links fast; sort later.
- **Subscriptions & renewals** — track recurring charges, see monthly/yearly totals, get flagged before each renewal.
- **Important dates & countdowns** — birthdays, expiries, deadlines, with yearly-recurring support.
- **Bookmarks & snippet vault** — searchable links and reusable copy-paste text.
- **Remote access hub** — register your Mac / Windows / iPhone and launch remote-control
  sessions from one place: builds `vn c://`, `rustdesk://`, and `jump://` launch links,
  opens Chrome Remote Desktop URLs, and generates a downloadable `.rdp` file for Windows
  (works on Mac + Windows). Store a "share note" to hand access to someone else. Includes
  a per-platform setup guide.

Plus a **daily email digest** (via cron) for anything renewing or due soon. 

**Phase 1 app shell** (see ROADMAP.md for the full plan):
- **Sidebar navigation** with grouped sections
- **Command palette** — `⌘K` / `Ctrl+K`, fuzzy search over every page and action
- **Keyboard-first navigation** — `g`+key to jump anywhere, `?` for the cheatsheet, `⇧D` theme toggle
- **Theming** — auto/dark/light, 4 presets (Midnight, Slate, Nord, Paper), accent colour picker, density
- **Customisable shortcuts** and start page
- **Installable PWA** — Dock icon on macOS, home-screen app on iPhone, offline shell
- **Migration runner** — forward-only schema changes with an automatic pre-change DB backup
- **JSON API** (`api.php`) for a future native client

**Phase 2 — tasks & scheduling:**
- **Tasks** with subtasks (unlimited nesting), P0–P3 priorities, statuses, notes, estimates
- **Kanban board** per project with drag-and-drop (auto-saves)
- **Tags** across tasks, with tag filtering and a tag cloud
- **Projects** with auto-created board columns
- **Filters** — open, today, next 7 days, overdue, done, all
- **Recurring tasks** — completing one spawns the next occurrence, tags carried over
- **Deadline tracking** — overdue/today/soon states everywhere
- **Calendar** — month grid showing events *and* task due dates
- **ICS subscription feed** (`ics.php`) — token-authenticated URL for Apple/Google Calendar
- **Reminders** + a much richer daily cron briefing (overdue, due soon, events, renewals, dates)
- **Auto-maintenance** — weekly DB snapshot, 30-day pruning, stale recurring tasks rolled forward

**Phase 3 — productivity & tracking:**
- **Pomodoro / deep-work timer** — wall-clock accurate (survives tab throttling), presets,
  interruption counter, auto-logs the session, audio cue
- **Habit tracker** with current/longest streaks and a 90-day heatmap
- **Goals** with targets, progress log, sub-goals and % completion
- **Daily planner** — intention, time blocks, evening review, energy/mood, habit ticks,
  and pulling overdue or unscheduled tasks into the day

**Phase 4 — notes & knowledge:**
- **Markdown editor** with live preview, split / edit-only / reading modes
- **Wiki links** `[[Note Title]]` with automatic **backlinks** and linked-references panel
- **Note graph** — force-directed canvas view, click to open, orphan detection
- **Full-text search** (SQLite FTS5 with highlighted snippets; LIKE fallback)
- Nested **folders**, tags, pinned notes, **daily notes**, templates, trash + restore
- **Version history** — every edit snapshotted, restore any revision
- **Attachments** with markdown insertion

**Phase 5 — reporting:**
- Productivity analytics: completions/day, focus/day, by project, by priority, by weekday
- Habit streak grids, goal progress, **CSV export** for focus/tasks/habits
- **Weekly review** — auto-assembled facts + reflection, saved into Notes with history

**Phase 6 — export, backup & OCR:**
- **Markdown export** — all notes as `.md` with YAML front-matter, zipped (works with or
  without the `zip` extension — includes a built-in ZIP writer)
- **Full JSON export** of every table
- **Encrypted backups** — AES-256-CBC, PBKDF2 (200k iterations), HMAC-SHA256
  encrypt-then-MAC, plus an in-app **verify** tool
- **PDF export** via print stylesheet (Reading mode → Print → Save as PDF)
- **OCR** for image attachments via tesseract.js, running entirely in your browser

> **How the remote hub works:** it's a launcher and directory, not a screen-streaming
> server. The actual connection runs through the remote-access app you choose (RustDesk,
> Chrome Remote Desktop, Microsoft Remote Desktop, VNC/Screen Sharing). A PHP host can't
> capture or relay a screen itself — the hub stores each device's connection details and
> opens the right app for you. Note iOS limits third-party remote *control* of an iPhone;
> the iPhone works great as a controller, and can screen-*share* view-only via FaceTime/Zoom.

Built in plain PHP + SQLite — no build step, no external database, no dependencies. Designed to drop straight onto a Plesk Linux host.

---

## What's in the box

```
personal-dashboard/
├── index.php          Dashboard home (summary grid)
├── login.php          Sign-in
├── logout.php
├── inbox.php          Quick-capture inbox
├── subscriptions.php  Subscription & renewal tracker
├── dates.php          Important dates + countdowns
├── bookmarks.php      Bookmarks + snippet vault
├── remote.php         Remote access hub (Mac / Windows / iPhone launchers + .rdp)
├── setup.php          One-time browser password-hash generator (delete after use)
├── cron.php           Daily alert emailer (run from Plesk Scheduled Tasks)
├── config.php         ← edit this (login, email, timezone)
├── db.php             SQLite connection + auto-creates tables
├── lib.php            Auth, CSRF, helpers
├── partials.php       Header/nav/footer
├── assets/style.css
├── data/              SQLite DB is created here (web access blocked)
└── .htaccess          Hardening + protects the database
```

The database and all tables are created **automatically** the first time you open the app — nothing to import.

---

## Deploy on Plesk (about 5 minutes)

1. **Upload the files.** In Plesk → *Files* (or via SFTP), copy the contents of this
   `personal-dashboard/` folder into your domain's document root
   (usually `httpdocs/`). You can also drop it in a subfolder like `httpdocs/dashboard/`
   if you'd rather reach it at `yourdomain.com/dashboard/`.

2. **Set your password — no SSH needed.** Visit `https://yourdomain.com/setup.php`
   in your browser, type the password you want, and it prints the exact
   `APP_PASSWORD_HASH` line. Open `config.php` in Plesk's **File Manager**, paste that
   line in, set `APP_USERNAME`, and save. Then **delete `setup.php`**.
   *(Default login is `admin` / `changeme` — change it before going live.)*

   *Have SSH instead? You can also run:*
   `php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"`

3. **Edit `config.php`.** Set `APP_TIMEZONE`, `ALERT_EMAIL_TO`, and `ALERT_EMAIL_FROM`
   (use an address on your own domain for best deliverability).

4. **Enable SSL.** In Plesk → *SSL/TLS Certificates* → install the free **Let's Encrypt**
   certificate for the domain, and turn on "Redirect from HTTP to HTTPS." This app stores
   personal data, so always run it over HTTPS.

5. **Check permissions.** The web user must be able to write to the `data/` folder
   (Plesk usually handles this). If you see a database error, set `data/` to writable
   (750) in the Plesk file manager.

6. **Open it.** Visit `https://yourdomain.com/` (or `/dashboard/`) and sign in.

### PHP version
Any PHP 7.4+ works; 8.x recommended. In Plesk → *PHP Settings*, make sure the
**pdo_sqlite** extension is enabled (it is by default on virtually all Plesk installs).

---

## Set up the daily reminder email

Plesk → **Websites & Domains → your domain → Scheduled Tasks → Add Task**:

- **Task type:** *Run a PHP script*
- **Script path:** `cron.php` (or the full path, e.g.
  `/var/www/vhosts/yourdomain.com/httpdocs/cron.php`)
- **Run:** Daily, at a time you'll see it — e.g. 08:00.

Prefer a shell command? Use:
```bash
php /var/www/vhosts/yourdomain.com/httpdocs/cron.php
```

Test it first without sending mail:
```bash
php cron.php --dry-run
```
It prints the digest it *would* send. Change the lead time (default 7 days) with
`ALERT_LEAD_DAYS` in `config.php`.

---

## Troubleshooting a 500 error

The root page redirects to login *before* touching the database, so if you get a **500
only after signing in**, it's the database step. Upload **`health.php`**, open
`https://yourdomain.com/health.php`, and it will tell you exactly what's wrong. The two
usual causes:

1. **`data/` folder not writable.** In Plesk File Manager, set the `data/` folder's
   permissions so the site's system user can write (755 or 775). This is where the SQLite
   file is created.
2. **`pdo_sqlite` extension off.** Plesk → *PHP Settings* → make sure `pdo_sqlite` is
   enabled, or switch the domain to a PHP 8.x handler (which has it on by default).

For a full error message, set `APP_DEBUG` to `true` in `config.php` temporarily. Turn it
back to `false` — and delete `health.php` and `setup.php` — once the app works.

## Security notes

- Single-user login with a bcrypt-hashed password; sessions regenerate on login.
- All forms are CSRF-protected; all output is HTML-escaped.
- The SQLite database lives in `data/`, which is blocked from web access by `.htaccess`.
  On Nginx-only Plesk setups, add an equivalent `location ~ /data/ { deny all; }` rule,
  or move `DB_PATH` (in `config.php`) to a folder outside the document root.
- Always serve over HTTPS.

## Extending it
Each module is a self-contained `*.php` file following the same pattern
(POST handler at top, list below). To add a fifth tool, copy `inbox.php`, add a table
in `db.php`, and add a nav entry in `partials.php`.
```
```
