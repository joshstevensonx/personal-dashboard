<?php
/**
 * Notion-style pages and blocks.
 *
 * Model:
 *   - A page can contain blocks and other pages (infinite nesting).
 *   - A page flagged is_database has child pages as its "rows", plus
 *     db_properties (columns) and page_values (cell values).
 */

/** Block types available in the slash menu, in order. */
function block_types(): array
{
    return [
        'paragraph' => ['label' => 'Text',            'icon' => '¶',  'hint' => 'Just start writing with plain text.'],
        'heading1'  => ['label' => 'Heading 1',       'icon' => 'H₁', 'hint' => 'Big section heading.'],
        'heading2'  => ['label' => 'Heading 2',       'icon' => 'H₂', 'hint' => 'Medium section heading.'],
        'heading3'  => ['label' => 'Heading 3',       'icon' => 'H₃', 'hint' => 'Small section heading.'],
        'bulleted'  => ['label' => 'Bulleted list',   'icon' => '•',  'hint' => 'Create a simple bulleted list.'],
        'numbered'  => ['label' => 'Numbered list',   'icon' => '1.', 'hint' => 'Create a list with numbering.'],
        'todo'      => ['label' => 'To-do list',      'icon' => '☑',  'hint' => 'Track tasks with a checkbox.'],
        'toggle'    => ['label' => 'Toggle list',     'icon' => '▸',  'hint' => 'Hide content inside a toggle.'],
        'quote'     => ['label' => 'Quote',           'icon' => '❝',  'hint' => 'Capture a quote.'],
        'callout'   => ['label' => 'Callout',         'icon' => '💡', 'hint' => 'Make writing stand out.'],
        'code'      => ['label' => 'Code',            'icon' => '{}', 'hint' => 'Capture a code snippet.'],
        'divider'   => ['label' => 'Divider',         'icon' => '—',  'hint' => 'Visually divide blocks.'],
        'image'     => ['label' => 'Image',           'icon' => '🖼', 'hint' => 'Embed an image by URL.'],
        'bookmark'  => ['label' => 'Web bookmark',    'icon' => '🔖', 'hint' => 'Save a link as a visual card.'],
        'embed'     => ['label' => 'Embed',           'icon' => '⧉',  'hint' => 'Embed a video or iframe by URL.'],
        'toc'       => ['label' => 'Table of contents','icon' => '≡', 'hint' => 'Auto-generated from headings.'],
        'columns'   => ['label' => 'Two columns',     'icon' => '▥',  'hint' => 'Side-by-side text, split with |.'],
        'equation'  => ['label' => 'Equation',        'icon' => '∑',  'hint' => 'Display a formula.'],
        'breadcrumb'=> ['label' => 'Breadcrumb',      'icon' => '›',  'hint' => 'Show this page\'s location.'],
    ];
}

/** Which embed providers get a proper iframe (everything else becomes a link). */
function embed_src(string $url): ?string
{
    $u = trim($url);
    if ($u === '' || preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $u)) return null;
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{6,})~i', $u, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(\d+)~i', $u, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    if (preg_match('~^https?://~i', $u)) return $u;
    return null;
}

/** Cover gradients — no image hosting needed. */
function cover_presets(): array
{
    return [
        'none'    => ['label' => 'None',    'css' => ''],
        'sand'    => ['label' => 'Sand',    'css' => 'linear-gradient(120deg,#f6d5a7,#e8b287)'],
        'sky'     => ['label' => 'Sky',     'css' => 'linear-gradient(120deg,#a8d8ff,#6ea8fe)'],
        'mint'    => ['label' => 'Mint',    'css' => 'linear-gradient(120deg,#a8e6cf,#54d19a)'],
        'blush'   => ['label' => 'Blush',   'css' => 'linear-gradient(120deg,#ffd3e0,#f2726f)'],
        'lavender'=> ['label' => 'Lavender','css' => 'linear-gradient(120deg,#d8c9ff,#b98cf5)'],
        'slate'   => ['label' => 'Slate',   'css' => 'linear-gradient(120deg,#c3cbd8,#7d8898)'],
        'dusk'    => ['label' => 'Dusk',    'css' => 'linear-gradient(120deg,#5b6b9a,#2b3350)'],
    ];
}

function cover_css(?string $name): string
{
    $p = cover_presets();
    return $p[$name ?? 'none']['css'] ?? '';
}

/* ----------------------------------------------------------------- pages --- */

function get_page(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM pages WHERE id = ? AND archived = 0");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function create_page(?int $parentId, string $title = 'Untitled', bool $isDatabase = false): int
{
    $pdo = db();
    $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM pages WHERE "
        . ($parentId ? "parent_id = " . (int)$parentId : "parent_id IS NULL"))->fetchColumn();
    $st = $pdo->prepare("INSERT INTO pages (parent_id, title, position, is_database, updated_at)
                         VALUES (?,?,?,?,datetime('now'))");
    $st->execute([$parentId, $title, $pos, $isDatabase ? 1 : 0]);
    $id = (int)$pdo->lastInsertId();

    if ($isDatabase) {
        // Sensible starting columns.
        $ins = $pdo->prepare("INSERT INTO db_properties (database_id, key, name, type, options, position) VALUES (?,?,?,?,?,?)");
        $ins->execute([$id, 'status', 'Status', 'select', json_encode(['Not started', 'In progress', 'Done']), 0]);
        $ins->execute([$id, 'date', 'Date', 'date', null, 1]);
        $pdo->prepare("UPDATE pages SET db_group_by = 'status' WHERE id = ?")->execute([$id]);
    } else {
        // Start every page with one empty paragraph so there's a cursor target.
        add_block($id, 'paragraph', '');
    }
    return $id;
}

/** Full ancestor chain, root first, for breadcrumbs. */
function page_trail(int $id): array
{
    $trail = [];
    $st = db()->prepare("SELECT id, parent_id, title, icon FROM pages WHERE id = ?");
    $guard = 0;
    while ($id && $guard++ < 30) {
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) break;
        array_unshift($trail, $p);
        $id = (int)($p['parent_id'] ?? 0);
    }
    return $trail;
}

/** All pages grouped by parent, for the sidebar tree. */
function page_tree(): array
{
    $rows = db()->query("SELECT id, parent_id, title, icon, is_database, favorite
                         FROM pages WHERE archived = 0 ORDER BY position, id")->fetchAll();
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
    }
    return $byParent;
}

function delete_page(int $id): void
{
    // Soft-archive the page and everything beneath it.
    $ids = [$id];
    $queue = [$id];
    $st = db()->prepare("SELECT id FROM pages WHERE parent_id = ?");
    $guard = 0;
    while ($queue && $guard++ < 500) {
        $cur = array_shift($queue);
        $st->execute([$cur]);
        foreach ($st->fetchAll() as $c) {
            $ids[] = (int)$c['id'];
            $queue[] = (int)$c['id'];
        }
    }
    $in = implode(',', array_map('intval', $ids));
    db()->exec("UPDATE pages SET archived = 1 WHERE id IN ($in)");
}

/* ---------------------------------------------------------------- blocks --- */

function page_blocks(int $pageId): array
{
    $st = db()->prepare("SELECT * FROM blocks WHERE page_id = ? ORDER BY position, id");
    $st->execute([$pageId]);
    return $st->fetchAll();
}

function add_block(int $pageId, string $type = 'paragraph', string $content = '', ?int $afterId = null): int
{
    $pdo = db();
    if ($afterId) {
        $p = $pdo->prepare("SELECT position FROM blocks WHERE id = ?");
        $p->execute([$afterId]);
        $pos = (int)$p->fetchColumn();
        $pdo->prepare("UPDATE blocks SET position = position + 1 WHERE page_id = ? AND position > ?")
            ->execute([$pageId, $pos]);
        $pos = $pos + 1;
    } else {
        $p = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM blocks WHERE page_id = ?");
        $p->execute([$pageId]);
        $pos = (int)$p->fetchColumn();
    }
    $st = $pdo->prepare("INSERT INTO blocks (page_id, type, content, position, updated_at)
                         VALUES (?,?,?,?,datetime('now'))");
    $st->execute([$pageId, valid_block_type($type), $content, $pos]);
    touch_page($pageId);
    return (int)$pdo->lastInsertId();
}

function valid_block_type(string $t): string
{
    return array_key_exists($t, block_types()) ? $t : 'paragraph';
}

function update_block(int $id, ?string $content = null, ?string $type = null, ?array $props = null): void
{
    $sets = [];
    $args = [];
    if ($content !== null) { $sets[] = 'content = ?'; $args[] = $content; }
    if ($type !== null)    { $sets[] = 'type = ?';    $args[] = valid_block_type($type); }
    if ($props !== null)   { $sets[] = 'props = ?';   $args[] = json_encode($props); }
    if (!$sets) return;
    $sets[] = "updated_at = datetime('now')";
    $args[] = $id;
    db()->prepare("UPDATE blocks SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
}

function delete_block(int $id): void
{
    db()->prepare("DELETE FROM blocks WHERE id = ?")->execute([$id]);
}

/** Reorder: $order is an array of block ids in their new sequence. */
function reorder_blocks(int $pageId, array $order): void
{
    $pdo = db();
    $st = $pdo->prepare("UPDATE blocks SET position = ? WHERE id = ? AND page_id = ?");
    foreach (array_values($order) as $i => $bid) {
        $st->execute([$i, (int)$bid, $pageId]);
    }
    touch_page($pageId);
}

function touch_page(int $pageId): void
{
    db()->prepare("UPDATE pages SET updated_at = datetime('now') WHERE id = ?")->execute([$pageId]);
}

/* ------------------------------------------------------------- database --- */

/** Property types offered when adding a column. */
function property_types(): array
{
    return [
        'text'     => 'Text',
        'number'   => 'Number',
        'select'   => 'Select',
        'multi'    => 'Multi-select',
        'date'     => 'Date',
        'checkbox' => 'Checkbox',
        'url'      => 'URL',
    ];
}

/** Colour palette cycled through for select-option tags. */
function tag_colors(): array
{
    return ['#e3e2e0', '#ffe2dd', '#fdecc8', '#dbeddb', '#d3e5ef', '#e8deee', '#f5e0e9', '#eee0da'];
}

function tag_color(string $value): string
{
    $c = tag_colors();
    return $c[abs(crc32($value)) % count($c)];
}

function db_properties(int $databaseId): array
{
    $st = db()->prepare("SELECT * FROM db_properties WHERE database_id = ? ORDER BY position, id");
    $st->execute([$databaseId]);
    return $st->fetchAll();
}

function add_property(int $databaseId, string $name, string $type = 'text', ?array $options = null): int
{
    $pdo = db();
    $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($name))) ?: 'field';
    // Ensure the key is unique within this database.
    $exists = $pdo->prepare("SELECT COUNT(*) FROM db_properties WHERE database_id = ? AND key = ?");
    $base = $key; $n = 2;
    $exists->execute([$databaseId, $key]);
    while ((int)$exists->fetchColumn() > 0) {
        $key = $base . '_' . $n++;
        $exists->execute([$databaseId, $key]);
    }
    $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM db_properties WHERE database_id = " . (int)$databaseId)->fetchColumn();
    $type = array_key_exists($type, property_types()) ? $type : 'text';
    $st = $pdo->prepare("INSERT INTO db_properties (database_id, key, name, type, options, position) VALUES (?,?,?,?,?,?)");
    $st->execute([$databaseId, $key, $name ?: 'Field', $type, $options ? json_encode(array_values($options)) : null, $pos]);
    return (int)$pdo->lastInsertId();
}

function delete_property(int $databaseId, int $propId): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT key FROM db_properties WHERE id = ? AND database_id = ?");
    $st->execute([$propId, $databaseId]);
    if ($key = $st->fetchColumn()) {
        // Remove the stored values for every row in this database.
        $pdo->prepare("DELETE FROM page_values WHERE key = ? AND page_id IN
                       (SELECT id FROM pages WHERE parent_id = ?)")->execute([$key, $databaseId]);
    }
    $pdo->prepare("DELETE FROM db_properties WHERE id = ? AND database_id = ?")->execute([$propId, $databaseId]);
}

/** Rows of a database = its child pages, with their values merged in. */
function db_rows(int $databaseId, array $opts = []): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM pages WHERE parent_id = ? AND archived = 0 ORDER BY position, id");
    $st->execute([$databaseId]);
    $rows = $st->fetchAll();
    if (!$rows) return [];

    $ids = array_column($rows, 'id');
    $in = implode(',', array_map('intval', $ids));
    $vals = [];
    foreach ($pdo->query("SELECT page_id, key, value FROM page_values WHERE page_id IN ($in)") as $v) {
        $vals[(int)$v['page_id']][$v['key']] = $v['value'];
    }
    foreach ($rows as &$r) {
        $r['values'] = $vals[(int)$r['id']] ?? [];
    }
    unset($r);

    // Optional filter: ['key'=>..., 'op'=>'is|contains|not_empty', 'value'=>...]
    if (!empty($opts['filter']['key'])) {
        $f = $opts['filter'];
        $rows = array_values(array_filter($rows, function ($r) use ($f) {
            $v = (string)($r['values'][$f['key']] ?? '');
            $needle = (string)($f['value'] ?? '');
            return match ($f['op'] ?? 'is') {
                'contains'  => $needle === '' || stripos($v, $needle) !== false,
                'not_empty' => trim($v) !== '',
                'empty'     => trim($v) === '',
                default     => $needle === '' || strcasecmp($v, $needle) === 0,
            };
        }));
    }

    // Optional sort: ['key'=>..., 'dir'=>'asc|desc']
    if (!empty($opts['sort']['key'])) {
        $k = $opts['sort']['key'];
        $dir = ($opts['sort']['dir'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($rows, function ($a, $b) use ($k, $dir) {
            $x = $k === '__title' ? $a['title'] : (string)($a['values'][$k] ?? '');
            $y = $k === '__title' ? $b['title'] : (string)($b['values'][$k] ?? '');
            if (is_numeric($x) && is_numeric($y)) {
                return ((float)$x <=> (float)$y) * $dir;
            }
            return strcasecmp($x, $y) * $dir;
        });
    }
    return $rows;
}

function set_value(int $pageId, string $key, ?string $value): void
{
    if ($value === null || $value === '') {
        db()->prepare("DELETE FROM page_values WHERE page_id = ? AND key = ?")->execute([$pageId, $key]);
        return;
    }
    db()->prepare("INSERT INTO page_values (page_id, key, value) VALUES (?,?,?)
                   ON CONFLICT(page_id, key) DO UPDATE SET value = excluded.value")
        ->execute([$pageId, $key, $value]);
}

/** Distinct values for a property — used to build board columns. */
function group_values(int $databaseId, string $key, array $rows): array
{
    $props = db_properties($databaseId);
    foreach ($props as $p) {
        if ($p['key'] === $key) {
            $opts = json_decode((string)$p['options'], true);
            if (is_array($opts) && $opts) return $opts;
        }
    }
    $seen = [];
    foreach ($rows as $r) {
        $v = trim((string)($r['values'][$key] ?? ''));
        if ($v !== '' && !in_array($v, $seen, true)) { $seen[] = $v; }
    }
    return $seen;
}

/** Render one property value as display HTML. */
function render_value(array $prop, ?string $value): string
{
    $v = (string)$value;
    switch ($prop['type']) {
        case 'checkbox':
            return "<input type='checkbox' class='bk-check' disabled" . ($v === '1' ? ' checked' : '') . ">";
        case 'select':
            if ($v === '') return '';
            return "<span class='tag' style='background:" . tag_color($v) . "'>" . htmlspecialchars($v, ENT_QUOTES) . "</span>";
        case 'multi':
            if ($v === '') return '';
            $out = '';
            foreach (array_filter(array_map('trim', explode(',', $v))) as $t) {
                $out .= "<span class='tag' style='background:" . tag_color($t) . "'>" . htmlspecialchars($t, ENT_QUOTES) . "</span> ";
            }
            return $out;
        case 'url':
            if ($v === '') return '';
            $safe = preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $v) ? '#' : $v;
            if (!preg_match('~^https?://~i', $safe) && $safe !== '#') { $safe = 'https://' . $safe; }
            return "<a href='" . htmlspecialchars($safe, ENT_QUOTES) . "' target='_blank' rel='noopener'>"
                 . htmlspecialchars($v, ENT_QUOTES) . "</a>";
        case 'date':
            return $v === '' ? '' : "<span class='muted'>" . htmlspecialchars($v, ENT_QUOTES) . "</span>";
        default:
            return htmlspecialchars($v, ENT_QUOTES);
    }
}

/* --------------------------------------------------------------- render --- */

/**
 * Inline formatting for block content. Input is escaped first, so output is safe.
 * Supports **bold**, *italic*, `code`, ~~strike~~, [text](url) and [[Page]].
 */
function block_inline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $u = $m[2];
        if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', html_entity_decode($u))) { $u = '#'; }
        return '<a href="' . $u . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);
    return $s;
}

function block_props(array $b): array
{
    $p = json_decode((string)($b['props'] ?? ''), true);
    return is_array($p) ? $p : [];
}

/** Server-side render of a single block (the editor re-uses these classes). */
function render_block(array $b): string
{
    $props = block_props($b);
    $id = (int)$b['id'];
    $type = $b['type'];
    $html = block_inline((string)$b['content']);
    $placeholder = $type === 'paragraph' ? "Type '/' for commands" : '';

    $editable = function (string $extra = '') use ($id, $html, $placeholder) {
        return "<div class='bk-text' contenteditable='true' data-ph=\"" . htmlspecialchars($placeholder, ENT_QUOTES)
             . "\" $extra>$html</div>";
    };

    $inner = '';
    switch ($type) {
        case 'heading1': $inner = "<h1 class='bk-h1'>" . $editable() . "</h1>"; break;
        case 'heading2': $inner = "<h2 class='bk-h2'>" . $editable() . "</h2>"; break;
        case 'heading3': $inner = "<h3 class='bk-h3'>" . $editable() . "</h3>"; break;
        case 'bulleted': $inner = "<div class='bk-li'><span class='bk-bullet'>•</span>" . $editable() . "</div>"; break;
        case 'numbered': $inner = "<div class='bk-li'><span class='bk-num'></span>" . $editable() . "</div>"; break;
        case 'todo':
            $checked = !empty($props['checked']);
            $inner = "<div class='bk-li'><input type='checkbox' class='bk-check'" . ($checked ? ' checked' : '') . ">"
                   . $editable($checked ? "style='opacity:.45;text-decoration:line-through'" : '') . "</div>";
            break;
        case 'toggle':
            $open = !empty($props['open']);
            $inner = "<div class='bk-toggle" . ($open ? ' open' : '') . "'>"
                   . "<button class='bk-arrow' type='button'>▸</button>" . $editable() . "</div>";
            break;
        case 'quote':   $inner = "<blockquote class='bk-quote'>" . $editable() . "</blockquote>"; break;
        case 'callout':
            $emoji = $props['emoji'] ?? '💡';
            $inner = "<div class='bk-callout'><span class='bk-emoji'>" . htmlspecialchars($emoji, ENT_QUOTES) . "</span>"
                   . $editable() . "</div>";
            break;
        case 'code':
            $inner = "<pre class='bk-code'><code class='bk-text' contenteditable='true'>"
                   . htmlspecialchars((string)$b['content'], ENT_QUOTES, 'UTF-8') . "</code></pre>";
            break;
        case 'divider': $inner = "<hr class='bk-divider'>"; break;

        case 'bookmark': {
            $url = trim((string)$b['content']);
            if ($url !== '' && !preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $url)) {
                $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
                $host = parse_url($href, PHP_URL_HOST) ?: $href;
                $inner = "<a class='bk-bookmark' href='" . htmlspecialchars($href, ENT_QUOTES) . "' target='_blank' rel='noopener'>"
                       . "<span class='bk-bm-title'>" . htmlspecialchars($host, ENT_QUOTES) . "</span>"
                       . "<span class='bk-bm-url'>" . htmlspecialchars($href, ENT_QUOTES) . "</span></a>";
            } else {
                $inner = "<div class='bk-image-empty'>" . $editable() . "</div>";
            }
            break;
        }

        case 'embed': {
            $src = embed_src((string)$b['content']);
            if ($src) {
                $inner = "<div class='bk-embed'><iframe src='" . htmlspecialchars($src, ENT_QUOTES) . "' "
                       . "loading='lazy' allowfullscreen referrerpolicy='no-referrer' "
                       . "sandbox='allow-scripts allow-same-origin allow-presentation'></iframe></div>";
            } else {
                $inner = "<div class='bk-image-empty'>" . $editable() . "</div>";
            }
            break;
        }

        case 'toc':
            // Filled in by page.php, which knows the whole block list.
            $inner = "<div class='bk-toc' data-toc='1'><span class='muted'>Table of contents</span></div>";
            break;

        case 'columns': {
            // Content is split on "|" into two columns.
            $parts = explode('|', (string)$b['content'], 2);
            $l = block_inline(trim($parts[0] ?? ''));
            $r = block_inline(trim($parts[1] ?? ''));
            $inner = "<div class='bk-columns'><div class='bk-col'>$l</div><div class='bk-col'>$r</div>"
                   . "<div class='bk-col-edit'>" . $editable() . "</div></div>";
            break;
        }

        case 'equation':
            $inner = "<div class='bk-equation'>" . $editable() . "</div>";
            break;

        case 'breadcrumb':
            $inner = "<div class='bk-crumb muted'>" . ($GLOBALS['__crumbHtml'] ?? 'Page location') . "</div>";
            break;
        case 'image':
            $src = trim((string)$b['content']);
            if ($src !== '' && !preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $src)) {
                $inner = "<img class='bk-image' src='" . htmlspecialchars($src, ENT_QUOTES) . "' alt='' loading='lazy'>";
            } else {
                $inner = "<div class='bk-image-empty'>" . $editable() . "</div>";
            }
            break;
        default:
            $inner = "<div class='bk-p'>" . $editable() . "</div>";
    }

    return "<div class='bk' data-id='$id' data-type='" . htmlspecialchars($type, ENT_QUOTES) . "'>"
         . "<div class='bk-handles'>"
         . "<button class='bk-add' type='button' title='Add block below'>+</button>"
         . "<button class='bk-drag' type='button' draggable='true' title='Drag to move'>⠿</button>"
         . "</div><div class='bk-body'>$inner</div></div>";
}
