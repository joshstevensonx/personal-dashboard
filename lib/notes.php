<?php
/**
 * Notes engine: markdown rendering, [[wiki links]], backlinks, search.
 * Zero dependencies — no Composer available on the host.
 */

/* ------------------------------------------------------------- markdown --- */

/**
 * Compact Markdown → HTML renderer.
 * Supports: headings, bold, italic, strikethrough, inline code, fenced code
 * blocks, links, images, blockquotes, hr, ordered/unordered lists, task lists,
 * tables (simple), and [[wiki links]].
 * All input is escaped first, so the output is safe to print.
 */
function render_markdown(string $md, array $noteTitles = []): string
{
    // Extract fenced code blocks first so their contents are never processed.
    $blocks = [];
    // NOTE: the placeholder must not contain NUL — PHP's trim() strips "\0",
    // which would silently destroy the marker before it can be restored.
    $md = preg_replace_callback('/```([a-zA-Z0-9_+-]*)\n(.*?)```/s', function ($m) use (&$blocks) {
        $key = '@@CODEBLOCK' . count($blocks) . '@@';
        $lang = $m[1] !== '' ? ' data-lang="' . htmlspecialchars($m[1], ENT_QUOTES) . '"' : '';
        $blocks[$key] = '<pre class="code"' . $lang . '><code>'
                      . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return $key;
    }, $md);

    $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

    // Inline code (after escaping, before other inline rules).
    $md = preg_replace_callback('/`([^`\n]+)`/', fn($m) => '<code>' . $m[1] . '</code>', $md);

    $lines = preg_split('/\r\n|\r|\n/', $md);
    $out = [];
    $listStack = [];   // 'ul' | 'ol'
    $inQuote = false;

    $closeLists = function () use (&$listStack, &$out) {
        while ($listStack) { $out[] = '</' . array_pop($listStack) . '>'; }
    };
    $closeQuote = function () use (&$inQuote, &$out) {
        if ($inQuote) { $out[] = '</blockquote>'; $inQuote = false; }
    };

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '') { $closeLists(); $closeQuote(); continue; }

        // Preserved code block placeholder
        if (preg_match('/^@@CODEBLOCK\d+@@$/', $trim)) {
            $closeLists(); $closeQuote();
            $out[] = $trim;
            continue;
        }

        // Horizontal rule
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim)) {
            $closeLists(); $closeQuote();
            $out[] = '<hr>';
            continue;
        }

        // Heading
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $closeLists(); $closeQuote();
            $lvl = strlen($m[1]);
            $out[] = "<h$lvl>" . md_inline($m[2], $noteTitles) . "</h$lvl>";
            continue;
        }

        // Blockquote
        if (preg_match('/^&gt;\s?(.*)$/', $trim, $m)) {
            $closeLists();
            if (!$inQuote) { $out[] = '<blockquote>'; $inQuote = true; }
            $out[] = '<p>' . md_inline($m[1], $noteTitles) . '</p>';
            continue;
        }
        $closeQuote();

        // Task list item
        if (preg_match('/^[-*+]\s+\[([ xX])\]\s+(.*)$/', $trim, $m)) {
            if (!$listStack || end($listStack) !== 'ul') { $closeLists(); $out[] = '<ul class="tasklist">'; $listStack[] = 'ul'; }
            $checked = strtolower($m[1]) === 'x' ? ' checked' : '';
            $out[] = '<li><input type="checkbox" disabled' . $checked . '> ' . md_inline($m[2], $noteTitles) . '</li>';
            continue;
        }
        // Unordered list
        if (preg_match('/^[-*+]\s+(.*)$/', $trim, $m)) {
            if (!$listStack || end($listStack) !== 'ul') { $closeLists(); $out[] = '<ul>'; $listStack[] = 'ul'; }
            $out[] = '<li>' . md_inline($m[1], $noteTitles) . '</li>';
            continue;
        }
        // Ordered list
        if (preg_match('/^\d+[.)]\s+(.*)$/', $trim, $m)) {
            if (!$listStack || end($listStack) !== 'ol') { $closeLists(); $out[] = '<ol>'; $listStack[] = 'ol'; }
            $out[] = '<li>' . md_inline($m[1], $noteTitles) . '</li>';
            continue;
        }

        $closeLists();
        $out[] = '<p>' . md_inline($trim, $noteTitles) . '</p>';
    }
    $closeLists();
    $closeQuote();

    $html = implode("\n", $out);
    // Restore code blocks.
    if ($blocks) {
        $html = strtr($html, $blocks);
    }
    return $html;
}

/** Inline-level markdown. Input is already HTML-escaped. */
function md_inline(string $s, array $noteTitles = []): string
{
    // Images: ![alt](src)
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        return '<img src="' . md_safe_url($m[2]) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $s);

    // Links: [text](url) — allows balanced parens inside the URL.
    $s = preg_replace_callback('/\[([^\]]+)\]\(((?:[^()\s]|\([^()]*\))+)\)/', function ($m) {
        return '<a href="' . md_safe_url($m[2]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);

    // Wiki links: [[Note Title]]
    $s = preg_replace_callback('/\[\[([^\]]+)\]\]/', function ($m) use ($noteTitles) {
        $title = trim($m[1]);
        $key = mb_strtolower($title);
        if (isset($noteTitles[$key])) {
            return '<a class="wikilink" href="notes.php?id=' . (int)$noteTitles[$key] . '">' . $title . '</a>';
        }
        return '<a class="wikilink missing" href="notes.php?new=' . rawurlencode($title) . '" title="Create this note">'
             . $title . '</a>';
    }, $s);

    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s);
    $s = preg_replace('/(?<!_)__([^_]+)__(?!_)/', '<strong>$1</strong>', $s);

    // Bare URLs
    $s = preg_replace_callback('/(?<!["\'>=])\b(https?:\/\/[^\s<]+)/', function ($m) {
        return '<a href="' . md_safe_url($m[1]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);

    return $s;
}

/** Only allow safe URL schemes — blocks javascript: and data: payloads. */
function md_safe_url(string $url): string
{
    $u = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $u)) {
        return '#';
    }
    return htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------- wiki linking --- */

/** Map of lowercase title => id, for resolving [[links]]. */
function note_title_map(): array
{
    $out = [];
    foreach (db()->query("SELECT id, title FROM notes WHERE deleted_at IS NULL") as $r) {
        $out[mb_strtolower($r['title'])] = (int)$r['id'];
    }
    return $out;
}

/** Re-scan a note's body and rewrite its outgoing links. */
function sync_note_links(int $noteId, string $body): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM note_links WHERE source_id = ?")->execute([$noteId]);
    if (!preg_match_all('/\[\[([^\]]+)\]\]/', $body, $m)) return;

    $map = note_title_map();
    $ins = $pdo->prepare("INSERT OR IGNORE INTO note_links (source_id, target_id, target_title) VALUES (?,?,?)");
    foreach (array_unique($m[1]) as $title) {
        $title = trim($title);
        if ($title === '') continue;
        $tid = $map[mb_strtolower($title)] ?? null;
        $ins->execute([$noteId, $tid, $title]);
    }
}

/** Notes that link TO this note (resolved by id, or by title for new notes). */
function backlinks(int $noteId, string $title): array
{
    $st = db()->prepare(
        "SELECT DISTINCT n.id, n.title FROM note_links l
         JOIN notes n ON n.id = l.source_id
         WHERE (l.target_id = ? OR lower(l.target_title) = lower(?))
           AND n.deleted_at IS NULL AND n.id <> ?
         ORDER BY n.title"
    );
    $st->execute([$noteId, $title, $noteId]);
    return $st->fetchAll();
}

/** After creating a note, connect any dangling links that named it. */
function resolve_dangling_links(int $noteId, string $title): void
{
    db()->prepare("UPDATE note_links SET target_id = ? WHERE target_id IS NULL AND lower(target_title) = lower(?)")
        ->execute([$noteId, $title]);
}

/* --------------------------------------------------------------- search --- */

/** Is FTS5 compiled into this SQLite build? */
function has_fts5(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->exec("CREATE VIRTUAL TABLE IF NOT EXISTS _fts_probe USING fts5(x)");
        db()->exec("DROP TABLE IF EXISTS _fts_probe");
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * The index is a REGULAR fts5 table (it keeps its own copy of the text).
 * A contentless table (content='') cannot be DELETEd from and cannot return
 * text from snippet(), both of which this app needs.
 */
function fts_populate(PDO $pdo): void
{
    $ins = $pdo->prepare("INSERT INTO notes_fts (rowid, title, body) VALUES (?,?,?)");
    foreach ($pdo->query("SELECT id, title, body FROM notes WHERE deleted_at IS NULL") as $n) {
        $ins->execute([$n['id'], $n['title'], $n['body']]);
    }
}

/** Create the index if missing, and fill it if it's empty. */
function ensure_fts(): void
{
    if (!has_fts5()) return;
    $pdo = db();
    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS notes_fts USING fts5(title, body)");
    $count = (int)$pdo->query("SELECT COUNT(*) FROM notes_fts")->fetchColumn();
    $notes = (int)$pdo->query("SELECT COUNT(*) FROM notes WHERE deleted_at IS NULL")->fetchColumn();
    if ($count !== $notes) { reindex_fts(); }
}

/** Rebuild the index from scratch. Safe before the table exists. */
function reindex_fts(): void
{
    if (!has_fts5()) return;
    $pdo = db();
    // DROP+CREATE also migrates any older contentless table to the new shape.
    $pdo->exec("DROP TABLE IF EXISTS notes_fts");
    $pdo->exec("CREATE VIRTUAL TABLE notes_fts USING fts5(title, body)");
    fts_populate($pdo);
}

/** Search notes. Uses FTS5 when available, LIKE otherwise. */
function search_notes(string $q, int $limit = 50): array
{
    $q = trim($q);
    if ($q === '') return [];
    $pdo = db();

    if (has_fts5()) {
        ensure_fts();
        try {
            // Quote each term so punctuation can't break FTS syntax.
            $terms = preg_split('/\s+/', $q);
            $safe = implode(' ', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"*', $terms));
            $st = $pdo->prepare(
                "SELECT n.id, n.title, n.updated_at, n.folder_id,
                        snippet(notes_fts, 1, '<mark>', '</mark>', '…', 18) AS snip
                 FROM notes_fts f JOIN notes n ON n.id = f.rowid
                 WHERE notes_fts MATCH ? AND n.deleted_at IS NULL
                 ORDER BY rank LIMIT ?"
            );
            $st->execute([$safe, $limit]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            // fall through to LIKE
        }
    }

    $like = '%' . $q . '%';
    $st = $pdo->prepare(
        "SELECT id, title, updated_at, folder_id, substr(body, 1, 160) AS snip
         FROM notes WHERE deleted_at IS NULL AND (title LIKE ? OR body LIKE ?)
         ORDER BY updated_at DESC LIMIT ?"
    );
    $st->execute([$like, $like, $limit]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['snip'] = htmlspecialchars($r['snip'], ENT_QUOTES, 'UTF-8'); }
    return $rows;
}

/* -------------------------------------------------------------- folders --- */

function folder_tree(): array
{
    $rows = db()->query("SELECT * FROM folders ORDER BY position, name")->fetchAll();
    $byParent = [];
    foreach ($rows as $r) { $byParent[$r['parent_id'] ?? 0][] = $r; }
    return $byParent;
}

function folder_path(?int $id): string
{
    if (!$id) return '';
    $names = [];
    $guard = 0;
    $st = db()->prepare("SELECT id, parent_id, name FROM folders WHERE id = ?");
    while ($id && $guard++ < 20) {
        $st->execute([$id]);
        $f = $st->fetch();
        if (!$f) break;
        array_unshift($names, $f['name']);
        $id = $f['parent_id'];
    }
    return implode(' / ', $names);
}

function reading_time(string $body): int
{
    $words = str_word_count(strip_tags($body));
    return max(1, (int)round($words / 220));
}
