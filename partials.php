<?php
/**
 * App shell: sidebar navigation, theming, command palette, keyboard nav.
 * page_header($activeFile) / page_footer() keep their original signatures so
 * every existing module renders in the new shell without modification.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/pages.php';

/**
 * Recursive page tree for the sidebar.
 *
 * A database's child pages are its rows, so they're deliberately NOT expanded
 * here — rows belong inside the database view, not the navigation tree.
 */
function render_page_tree(array $tree, int $parent = 0, int $depth = 0, int $activeId = 0): void
{
    $kids = $tree[$parent] ?? [];
    if (!$kids || $depth > 6) {
        return;
    }
    foreach ($kids as $p) {
        $id = (int)$p['id'];
        $isDb = !empty($p['is_database']);
        $grandKids = (!$isDb && !empty($tree[$id])) ? $tree[$id] : [];
        $open = $id === $activeId;
        $icon = $p['icon'] ?: ($isDb ? '🗃' : '📄');

        echo "<div class='tree-node'>";
        echo "<div class='tree-row" . ($id === $activeId ? ' on' : '') . "'>";
        if ($grandKids) {
            echo "<button class='tree-caret" . ($open ? ' open' : '') . "' type='button' aria-label='Expand'>▸</button>";
        } else {
            echo "<span class='tree-caret' style='visibility:hidden'>▸</span>";
        }
        echo "<span class='tree-ico'>" . e($icon) . "</span>";
        echo "<a class='tree-link' href='page.php?id=$id'>" . e($p['title']) . "</a>";
        echo "</div>";

        if ($grandKids) {
            echo "<div class='tree-kids'" . ($open ? " style='display:block'" : '') . ">";
            render_page_tree($tree, $id, $depth + 1, $activeId);
            echo "</div>";
        }
        echo "</div>";
    }
}

/** Navigation model — grouped. Later phases append to these groups. */
function nav_model(): array
{
    return [
        'Overview' => [
            'index.php'     => ['Dashboard', '▦'],
            'inbox.php'     => ['Inbox', '⌁'],
        ],
        'Plan' => [
            'planner.php'   => ['Daily plan', '◉'],
            'tasks.php'     => ['Tasks', '☑'],
            'calendar.php'  => ['Calendar', '▤'],
        ],
        'Focus' => [
            'focus.php'     => ['Focus timer', '◐'],
            'habits.php'    => ['Habits', '▪'],
            'goals.php'     => ['Goals', '◎'],
        ],
        'Track' => [
            'subscriptions.php' => ['Subscriptions', '↻'],
            'dates.php'         => ['Dates', '◷'],
        ],
        'Knowledge' => [
            'page.php'      => ['Pages', '◫'],
            'notes.php'     => ['Notes', '✎'],
            'graph.php'     => ['Graph', '⁂'],
        ],
        'Reference' => [
            'bookmarks.php' => ['Bookmarks', '★'],
            'remote.php'    => ['Remote', '⌘'],
        ],
        'Review' => [
            'reports.php'   => ['Reports', '▧'],
            'review.php'    => ['Weekly review', '✓'],
        ],
        'System' => [
            'export.php'    => ['Export & backup', '⇩'],
            'settings.php'  => ['Settings', '⚙'],
        ],
    ];
}

/** Commands available in the palette (pages + actions). */
function command_model(): array
{
    $cmds = [];
    foreach (nav_model() as $group => $items) {
        foreach ($items as $file => [$label, $icon]) {
            $cmds[] = ['id' => 'go:' . $file, 'label' => 'Go to ' . $label,
                       'group' => $group, 'icon' => $icon, 'href' => $file];
        }
    }
    // Quick actions
    $cmds[] = ['id' => 'new:task',     'label' => 'Add a task',             'group' => 'Create', 'icon' => '+', 'href' => 'tasks.php#new'];
    $cmds[] = ['id' => 'new:event',    'label' => 'Add a calendar event',   'group' => 'Create', 'icon' => '+', 'href' => 'calendar.php#new'];
    $cmds[] = ['id' => 'view:today',   'label' => 'Tasks due today',        'group' => 'Tasks',  'icon' => '☑', 'href' => 'tasks.php?filter=today'];
    $cmds[] = ['id' => 'view:overdue', 'label' => 'Overdue tasks',          'group' => 'Tasks',  'icon' => '!', 'href' => 'tasks.php?filter=overdue'];
    $cmds[] = ['id' => 'view:board',   'label' => 'Kanban board',           'group' => 'Tasks',  'icon' => '▥', 'href' => 'tasks.php?view=board'];
    $cmds[] = ['id' => 'new:habit',    'label' => 'Add a habit',            'group' => 'Create', 'icon' => '+', 'href' => 'habits.php#new'];
    $cmds[] = ['id' => 'new:goal',     'label' => 'Add a goal',             'group' => 'Create', 'icon' => '+', 'href' => 'goals.php#new'];
    $cmds[] = ['id' => 'go:pomodoro',  'label' => 'Start a focus session',  'group' => 'Focus',  'icon' => '◐', 'href' => 'focus.php'];
    $cmds[] = ['id' => 'go:pages',     'label' => 'All pages',              'group' => 'Pages',  'icon' => '◫', 'href' => 'page.php'];
    // Recent pages become palette entries, so ⌘K jumps straight to any page.
    try {
        foreach (db()->query("SELECT id, title, icon, is_database FROM pages
                              WHERE archived = 0 ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 25") as $p) {
            $cmds[] = ['id' => 'page:' . $p['id'], 'label' => $p['title'],
                       'group' => $p['is_database'] ? 'Database' : 'Page',
                       'icon' => $p['icon'] ?: ($p['is_database'] ? '🗃' : '📄'),
                       'href' => 'page.php?id=' . (int)$p['id']];
        }
    } catch (Throwable $e) { /* pages table not migrated yet */ }
    $cmds[] = ['id' => 'new:note',     'label' => 'New note',               'group' => 'Create', 'icon' => '✎', 'href' => 'notes.php?new='];
    $cmds[] = ['id' => 'go:daily',     "label" => "Today's daily note",     'group' => 'Notes',  'icon' => '☀', 'href' => 'notes.php?daily=1'];
    $cmds[] = ['id' => 'go:search',    'label' => 'Search notes',           'group' => 'Notes',  'icon' => '⌕', 'href' => 'notes.php'];
    $cmds[] = ['id' => 'new:capture',  'label' => 'Quick capture a note',   'group' => 'Create', 'icon' => '+', 'href' => 'inbox.php#new'];
    $cmds[] = ['id' => 'new:bookmark', 'label' => 'Save a bookmark',        'group' => 'Create', 'icon' => '+', 'href' => 'bookmarks.php#new'];
    $cmds[] = ['id' => 'new:sub',      'label' => 'Add a subscription',     'group' => 'Create', 'icon' => '+', 'href' => 'subscriptions.php#new'];
    $cmds[] = ['id' => 'new:date',     'label' => 'Add an important date',  'group' => 'Create', 'icon' => '+', 'href' => 'dates.php#new'];
    $cmds[] = ['id' => 'new:device',   'label' => 'Add a device',           'group' => 'Create', 'icon' => '+', 'href' => 'remote.php#new'];
    // Theme actions
    $cmds[] = ['id' => 'theme:toggle', 'label' => 'Toggle dark / light',    'group' => 'Theme',  'icon' => '◐', 'action' => 'toggleTheme'];
    $cmds[] = ['id' => 'help:keys',    'label' => 'Show keyboard shortcuts','group' => 'Help',   'icon' => '?', 'action' => 'showKeys'];
    $cmds[] = ['id' => 'auth:out',     'label' => 'Sign out',               'group' => 'System', 'icon' => '⏻', 'href' => 'logout.php'];
    return $cmds;
}

/** Build the inline CSS variables for the active theme preset + accent. */
function theme_style_attr(): string
{
    $presets = theme_presets();
    $key = (string)setting('preset');
    $p = $presets[$key] ?? $presets['midnight'];
    $accent = (string)setting('accent');
    if (!preg_match('/^#[0-9a-f]{6}$/i', $accent)) {
        $accent = '#6ea8fe';
    }
    // Choose readable ink for buttons based on accent luminance.
    $r = hexdec(substr($accent, 1, 2)); $g = hexdec(substr($accent, 3, 2)); $b = hexdec(substr($accent, 5, 2));
    $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    $ink = $lum > 0.6 ? '#101418' : '#06101f';

    $css = '';
    foreach ($p['vars'] as $k => $v) {
        $css .= "--$k:$v;";
    }
    $css .= "--accent:$accent;--accent-ink:$ink;";
    return $css;
}

function page_header(string $active = ''): void
{
    $theme = (string)setting('theme');       // auto | dark | light
    $density = (string)setting('density');
    $style = theme_style_attr();
    $name = e(APP_NAME);
    $cmds = json_for_html(command_model());
    $shortcuts = json_for_html(setting_json('shortcuts', []));

    echo "<!doctype html><html lang='en' data-theme='" . e($theme) . "' data-density='" . e($density) . "' style='" . e($style) . "'><head>";
    echo "<meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1, viewport-fit=cover'>";
    echo "<title>$name</title>";
    echo "<link rel='stylesheet' href='assets/style.css'>";
    echo "<link rel='stylesheet' href='assets/notion.css'>";
    echo "<link rel='manifest' href='manifest.webmanifest'>";
    echo "<meta name='theme-color' content='" . e((string)setting('accent')) . "'>";
    echo "<meta name='apple-mobile-web-app-capable' content='yes'>";
    echo "<meta name='apple-mobile-web-app-title' content='$name'>";
    echo "<link rel='apple-touch-icon' href='assets/icons/icon-180.png'>";
    echo "<link rel='icon' href='assets/icons/icon-192.png'>";
    // The Notion layer is opt-out: assets/notion.css is scoped to body.notion,
    // so switching this setting to "classic" restores the original shell.
    $bodyClass = setting('ui_style') === 'classic' ? '' : ' class="notion"';
    echo "</head><body$bodyClass>";

    echo "<div class='app'>";

    /* -- sidebar -- */
    echo "<aside class='sidebar' id='sidebar'>";
    echo "<div class='brand'><span class='dot'></span> $name</div><nav>";
    foreach (nav_model() as $group => $items) {
        echo "<div class='sec'>" . e($group) . "</div>";
        foreach ($items as $file => [$label, $icon]) {
            $on = ($file === $active) ? "class='on'" : '';
            echo "<a $on href='" . e($file) . "'><span class='ic'>" . $icon . "</span>" . e($label) . "</a>";
        }
    }

    /* -- Notion-style page tree -- */
    $tree = [];
    try { $tree = page_tree(); } catch (Throwable $e) { $tree = []; }
    $activePage = (isset($_GET['id']) && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'page.php')
        ? (int)$_GET['id'] : 0;

    $favs = array_filter($tree[0] ?? [], fn($p) => !empty($p['favorite']));
    if ($favs) {
        echo "<div class='sec'>Favourites</div>";
        foreach ($favs as $f) {
            echo "<a class='tree-fav" . ((int)$f['id'] === $activePage ? ' on' : '') . "' href='page.php?id="
               . (int)$f['id'] . "'><span class='ic'>" . e($f['icon'] ?: '★') . "</span>" . e($f['title']) . "</a>";
        }
    }

    echo "<div class='sec sec-row'><span>Pages</span>"
       . "<a class='sec-add' href='page.php' title='All pages'>＋</a></div>";
    echo "<div class='tree'>";
    render_page_tree($tree, 0, 0, $activePage);
    if (empty($tree[0])) {
        echo "<a class='tree-empty' href='page.php'>Create your first page</a>";
    }
    echo "</div>";

    echo "</nav><div class='foot'><span class='kbd-hint'>Press <kbd>?</kbd> for keys</span>"
       . "<a class='pill' href='logout.php'>Sign out</a></div>";
    echo "</aside>";

    /* -- content -- */
    echo "<div class='content'>";
    echo "<div class='topbar'>";
    echo "<button class='menubtn' id='menubtn' aria-label='Toggle navigation'>☰</button>";
    echo "<button class='searchbtn' id='palettebtn'>Search or jump to… <span class='k'>⌘K</span></button>";
    echo "<div class='spacer'></div>";
    echo "<button class='ghost mini' id='themebtn' title='Toggle dark / light'>◐</button>";
    echo "</div>";
    echo "<main>";

    if ($msg = flash()) {
        echo "<div class='flash'>" . e($msg) . "</div>";
    }

    // Data the shell's JS needs.
    echo "<script id='app-data' type='application/json'>"
       . json_for_html(['commands' => command_model(), 'shortcuts' => setting_json('shortcuts', [])])
       . "</script>";
}

function page_footer(): void
{
    echo "</main><footer>" . e(APP_NAME) . " &middot; " . date('l, j M Y') . "</footer>";
    echo "</div></div>"; // .content .app

    /* command palette */
    echo "<div class='palette-wrap' id='palette-wrap' role='dialog' aria-modal='true' aria-label='Command palette'>
            <div class='palette'>
              <input id='palette-input' placeholder='Search commands and pages…' autocomplete='off' spellcheck='false'>
              <ul id='palette-list'></ul>
            </div>
          </div>";

    /* shortcuts cheatsheet */
    echo "<div class='sheet-wrap' id='sheet-wrap' role='dialog' aria-modal='true' aria-label='Keyboard shortcuts'>
            <div class='sheet'>
              <h2>Keyboard shortcuts</h2>
              <table>
                <tr><td>Command palette</td><td><kbd>⌘K</kbd> / <kbd>Ctrl K</kbd></td></tr>
                <tr><td>Search (same)</td><td><kbd>/</kbd></td></tr>
                <tr><td>This cheatsheet</td><td><kbd>?</kbd></td></tr>
                <tr><td>Go to Dashboard</td><td><kbd>g</kbd> <kbd>d</kbd></td></tr>
                <tr><td>Go to Tasks</td><td><kbd>g</kbd> <kbd>k</kbd></td></tr>
                <tr><td>Go to Calendar</td><td><kbd>g</kbd> <kbd>c</kbd></td></tr>
                <tr><td>Go to Inbox</td><td><kbd>g</kbd> <kbd>i</kbd></td></tr>
                <tr><td>Go to Subscriptions</td><td><kbd>g</kbd> <kbd>s</kbd></td></tr>
                <tr><td>Go to Dates</td><td><kbd>g</kbd> <kbd>t</kbd></td></tr>
                <tr><td>Go to Bookmarks</td><td><kbd>g</kbd> <kbd>b</kbd></td></tr>
                <tr><td>Go to Remote</td><td><kbd>g</kbd> <kbd>r</kbd></td></tr>
                <tr><td>Settings</td><td><kbd>g</kbd> <kbd>,</kbd></td></tr>
                <tr><td>Toggle dark / light</td><td><kbd>⇧</kbd> <kbd>D</kbd></td></tr>
                <tr><td>Close overlay</td><td><kbd>Esc</kbd></td></tr>
              </table>
            </div>
          </div>";

    echo "<script src='assets/app.js'></script>";
    echo "</body></html>";
}
