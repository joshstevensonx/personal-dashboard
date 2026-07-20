<?php
/**
 * Notion HTML-export importer.
 *
 * Notion's "Export → HTML" produces one .html per page, sibling folders for
 * sub-pages, .csv files for every database, and the original asset files.
 * This walks that tree and reproduces it as native pages, blocks and databases.
 *
 * Design notes
 *  - Every Notion block carries its UUID in the element `id`. We keep it in
 *    blocks.props.notion_id so a re-import updates rather than duplicates.
 *  - The page's own HTML is also stored verbatim (blocks.type = 'notion_html')
 *    so it can be rendered with Notion's exact stylesheet — "looks identical"
 *    without having to model every one of Notion's block variants.
 *  - Inter-page links are rewritten to page.php?id=… after all pages exist.
 */

/** Result accumulator so the UI can report what happened. */
function import_stats_new(): array
{
    return ['pages' => 0, 'blocks' => 0, 'databases' => 0, 'rows' => 0,
            'assets' => 0, 'links' => 0, 'skipped' => 0, 'errors' => []];
}

/**
 * Notion filenames look like "Task Tracker 39ad80dbf06c8062aecbf2b41ec85a3d".
 * Split the human title from the 32-char hex id.
 */
function notion_split_name(string $name): array
{
    $base = preg_replace('/\.(html|csv)$/i', '', $name);
    if (preg_match('/^(.*?)[ _]([0-9a-f]{32})$/i', $base, $m)) {
        return [trim($m[1]), strtolower($m[2])];
    }
    return [trim($base), ''];
}

/** Turn a 32-char hex into canonical UUID form. */
function notion_uuid(string $hex): string
{
    if (strlen($hex) !== 32) return $hex;
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
         . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

/** Load an export HTML file into a DOMDocument. */
function notion_dom(string $file): ?DOMDocument
{
    $html = @file_get_contents($file);
    if ($html === false || trim($html) === '') return null;
    $doc = new DOMDocument();
    // Notion exports are UTF-8; the hint stops DOM mangling accented text.
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

function notion_first(DOMXPath $xp, string $query, ?DOMNode $ctx = null): ?DOMElement
{
    $n = $ctx ? $xp->query($query, $ctx) : $xp->query($query);
    return ($n && $n->length) ? $n->item(0) : null;
}

/** Inner HTML of a node. */
function notion_inner_html(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $c) {
        $html .= $node->ownerDocument->saveHTML($c);
    }
    return $html;
}

/* ==========================================================================
   Asset handling
   ========================================================================== */

/**
 * Copy a referenced asset into the uploads folder and return its public URL.
 * Notion references assets relative to the HTML file.
 */
function notion_copy_asset(string $src, string $baseDir, array &$stats, array &$assetMap): ?string
{
    $src = urldecode($src);
    if ($src === '' || preg_match('~^(https?:|data:|#)~i', $src)) {
        return null;   // remote or inline — leave as-is
    }
    $full = realpath($baseDir . '/' . $src);
    if ($full === false || !is_file($full)) return null;
    if (isset($assetMap[$full])) return $assetMap[$full];

    $dir = defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','gif','webp','svg','pdf','txt','md','csv','heic','docx','mp4','webm'];
    if (!in_array($ext, $allowed, true)) { return null; }

    $name = 'n' . substr(sha1($full), 0, 16) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!is_file($dest) && !@copy($full, $dest)) { return null; }

    $url = function_exists('attachment_url') ? attachment_url($name) : 'uploads/' . $name;
    $assetMap[$full] = $url;
    $stats['assets']++;

    try {
        db()->prepare("INSERT INTO attachments (note_id, filename, path, mime, size)
                       VALUES (NULL, ?, ?, ?, ?)")
            ->execute([basename($full), $name, mime_content_type($full) ?: '', filesize($full)]);
    } catch (Throwable $e) { /* attachment log is best-effort */ }

    return $url;
}

/* ==========================================================================
   Page import
   ========================================================================== */

/**
 * Import one exported HTML file (and, recursively, its sibling folder of
 * sub-pages). Returns the new page id.
 */
function import_notion_page(string $file, ?int $parentId, array &$stats,
                            array &$idMap, array &$assetMap, int $depth = 0): ?int
{
    // Notion nests deeply; the guard only exists to stop symlink loops.
    if ($depth > 30) { $stats['skipped']++; return null; }

    $doc = notion_dom($file);
    if (!$doc) { $stats['errors'][] = 'Unreadable: ' . basename($file); return null; }
    $xp = new DOMXPath($doc);
    $baseDir = dirname($file);

    [$fileTitle, $hex] = notion_split_name(basename($file));

    $article = notion_first($xp, '//article');
    $titleEl = notion_first($xp, '//h1[contains(@class,"page-title")]');
    $title = $titleEl ? trim($titleEl->textContent) : $fileTitle;
    if ($title === '') { $title = 'Untitled'; }

    // Page icon: Notion uses either an emoji span or an <img> URL.
    $icon = null;
    $iconEl = notion_first($xp, '//div[contains(@class,"page-header-icon")]');
    if ($iconEl) {
        $txt = trim($iconEl->textContent);
        if ($txt !== '' && mb_strlen($txt) <= 4) { $icon = $txt; }
    }
    if ($icon === null && $article) {
        $attr = $article->getAttribute('data-notion-page-icon');
        if ($attr !== '' && !str_starts_with($attr, '/icons/')) { $icon = null; }
    }

    // Cover
    $cover = null;
    $coverEl = notion_first($xp, '//img[contains(@class,"page-cover-image")]');
    if ($coverEl) {
        $u = notion_copy_asset($coverEl->getAttribute('src'), $baseDir, $stats, $assetMap);
        if ($u) { $cover = $u; }
    }

    $body = notion_first($xp, '//div[contains(@class,"page-body")]');

    /* -- create or update the page ---------------------------------------- */
    $pdo = db();
    $pageId = null;
    if ($hex !== '') {
        $find = $pdo->prepare("SELECT id FROM pages WHERE notion_id = ?");
        $find->execute([$hex]);
        $pageId = $find->fetchColumn() ?: null;
    }

    if ($pageId) {
        $pdo->prepare("UPDATE pages SET title=?, icon=COALESCE(?,icon), cover=COALESCE(?,cover),
                       updated_at=datetime('now') WHERE id=?")
            ->execute([$title, $icon, $cover, $pageId]);
        $pdo->prepare("DELETE FROM blocks WHERE page_id=?")->execute([$pageId]);
    } else {
        $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM pages WHERE "
            . ($parentId ? "parent_id = " . (int)$parentId : "parent_id IS NULL"))->fetchColumn();
        $pdo->prepare("INSERT INTO pages (parent_id, title, icon, cover, position, notion_id, updated_at)
                       VALUES (?,?,?,?,?,?,datetime('now'))")
            ->execute([$parentId, $title, $icon, $cover, $pos, $hex ?: null]);
        $pageId = (int)$pdo->lastInsertId();
    }
    $stats['pages']++;
    if ($hex !== '') { $idMap[$hex] = (int)$pageId; }
    // Map by filename too — Notion links point at the file, not the id.
    $idMap['file:' . basename($file)] = (int)$pageId;

    /* -- store the body ---------------------------------------------------- */
    if ($body) {
        // Rewrite asset URLs inside the stored HTML.
        foreach ($xp->query('.//img | .//a | .//source', $body) as $el) {
            $attr = $el->nodeName === 'a' ? 'href' : 'src';
            $val = $el->getAttribute($attr);
            if ($val === '') continue;
            $new = notion_copy_asset($val, $baseDir, $stats, $assetMap);
            if ($new) { $el->setAttribute($attr, $new); }
        }

        // Sanitise before storing — imported markup is untrusted input.
        $html = sanitize_notion_html(notion_inner_html($body));
        // One verbatim block renders with Notion's own stylesheet.
        $pdo->prepare("INSERT INTO blocks (page_id, type, content, props, position, updated_at)
                       VALUES (?, 'notion_html', ?, ?, 0, datetime('now'))")
            ->execute([$pageId, $html, json_encode(['notion_id' => $hex, 'source' => basename($file)])]);
        $stats['blocks']++;

        // Also extract editable native blocks so the content isn't a black box.
        $n = extract_native_blocks($xp, $body, (int)$pageId, $baseDir, $stats, $assetMap);
        $stats['blocks'] += $n;
    }

    /* -- locate this page's companion folder -------------------------------
     * Notion names it either "Title <hex>" or, when unambiguous, just "Title".
     * Sub-pages, database CSVs and assets all live inside it.
     */
    $folder = notion_page_folder($file, $baseDir);

    /* -- inline databases: CSVs sit in the companion folder ---------------- */
    $csvDirs = array_values(array_filter([$folder, $baseDir]));
    import_notion_collections($xp, $csvDirs, (int)$pageId, $stats, $idMap, $assetMap, $depth);

    /* -- recurse into sub-pages -------------------------------------------- */
    if ($folder !== null) {
        foreach (glob($folder . '/*.html') ?: [] as $child) {
            import_notion_page($child, (int)$pageId, $stats, $idMap, $assetMap, $depth + 1);
        }
    }

    return (int)$pageId;
}

/**
 * Resolve the folder that belongs to an exported page.
 * Notion is inconsistent: sometimes "Title <hex>/", sometimes just "Title/".
 */
function notion_page_folder(string $file, string $baseDir): ?string
{
    $stem = preg_replace('/\.html$/i', '', basename($file));
    [$title] = notion_split_name(basename($file));

    foreach ([$stem, $title] as $cand) {
        $p = $baseDir . '/' . $cand;
        if (is_dir($p)) return $p;
    }
    return null;
}

/**
 * Map the common Notion block elements onto native editable blocks.
 * The verbatim HTML block above guarantees fidelity; these make it editable.
 */
function extract_native_blocks(DOMXPath $xp, DOMNode $body, int $pageId, string $baseDir,
                               array &$stats, array &$assetMap): int
{
    $pdo = db();
    $ins = $pdo->prepare("INSERT INTO blocks (page_id, type, content, props, position, updated_at)
                          VALUES (?,?,?,?,?,datetime('now'))");
    $pos = 1;
    $made = 0;

    foreach ($body->childNodes as $node) {
        if (!($node instanceof DOMElement)) continue;
        $cls = $node->getAttribute('class');
        $tag = strtolower($node->nodeName);
        $nid = $node->getAttribute('id');
        $text = trim($node->textContent);

        $type = null; $content = $text; $props = ['notion_id' => $nid];

        if ($tag === 'h1') { $type = 'heading1'; }
        elseif ($tag === 'h2') { $type = 'heading2'; }
        elseif ($tag === 'h3') { $type = 'heading3'; }
        elseif ($tag === 'hr') { $type = 'divider'; $content = ''; }
        elseif ($tag === 'blockquote') { $type = 'quote'; }
        elseif ($tag === 'pre') { $type = 'code'; }
        elseif ($tag === 'figure' && str_contains($cls, 'image')) {
            $img = notion_first($xp, './/img', $node);
            if ($img) { $type = 'image'; $content = $img->getAttribute('src'); }
        }
        elseif ($tag === 'aside' || str_contains($cls, 'callout')) { $type = 'callout'; }
        elseif ($tag === 'ul' && str_contains($cls, 'to-do-list')) {
            foreach ($xp->query('./li', $node) as $li) {
                $checked = $xp->query('.//div[contains(@class,"checkbox-on")]', $li)->length > 0;
                $ins->execute([$pageId, 'todo', trim($li->textContent),
                               json_encode(['checked' => $checked]), $pos++]);
                $made++;
            }
            continue;
        }
        elseif ($tag === 'ul') {
            foreach ($xp->query('./li', $node) as $li) {
                $ins->execute([$pageId, 'bulleted', trim($li->textContent), null, $pos++]);
                $made++;
            }
            continue;
        }
        elseif ($tag === 'ol') {
            foreach ($xp->query('./li', $node) as $li) {
                $ins->execute([$pageId, 'numbered', trim($li->textContent), null, $pos++]);
                $made++;
            }
            continue;
        }
        elseif ($tag === 'p') { $type = 'paragraph'; }

        if ($type !== null && ($content !== '' || $type === 'divider')) {
            $ins->execute([$pageId, $type, $content, json_encode($props), $pos++]);
            $made++;
        }
    }
    return $made;
}

/**
 * Import every collection (database) referenced on a page.
 * The CSV holds the data; the HTML table tells us the column order.
 */
function import_notion_collections(DOMXPath $xp, array $csvDirs, int $pageId,
                                   array &$stats, array &$idMap, array &$assetMap,
                                   int $depth = 0): void
{
    foreach ($xp->query('//div[contains(@class,"collection-content")]') as $coll) {
        if (!($coll instanceof DOMElement)) continue;
        $collId = $coll->getAttribute('id');
        $titleEl = notion_first($xp, './/*[contains(@class,"collection-title")]', $coll);
        $name = $titleEl ? trim($titleEl->textContent) : 'Database';

        // The CSV may sit in the page's own folder or beside the HTML.
        $hex = str_replace('-', '', $collId);
        $csv = null;
        $byName = null;
        foreach ($csvDirs as $dir) {
            foreach (glob($dir . '/*.csv') ?: [] as $cand) {
                [$cn, $ch] = notion_split_name(basename($cand));
                if ($ch !== '' && $ch === $hex) { $csv = $cand; break 2; }
                if ($byName === null && $cn === $name) { $byName = $cand; }
            }
        }
        $csv = $csv ?? $byName;
        if ($csv === null) { continue; }

        // Skip a CSV already imported under this id.
        [, $csvHex] = notion_split_name(basename($csv));
        if ($csvHex !== '' && isset($idMap[$csvHex])) { continue; }

        import_notion_csv($csv, $name, $pageId, $stats, $idMap, $assetMap, $depth + 1);
    }
}

/**
 * Turn one exported CSV into a database page with properties and rows.
 *
 * Notion stores a database as: "<Name> <hex>.csv" for the data, plus a sibling
 * "<Name>/" folder holding one .html per row (with the row's page content and,
 * recursively, its own sub-pages). We import the row pages for their content
 * and then attach the CSV values to them, matching on title.
 */
function import_notion_csv(string $csvFile, string $name, ?int $parentId,
                           array &$stats, array &$idMap,
                           ?array &$assetMap = null, int $depth = 0): ?int
{
    if ($assetMap === null) { $assetMap = []; }
    $fh = @fopen($csvFile, 'r');
    if (!$fh) return null;

    $header = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$header) { fclose($fh); return null; }
    // Strip the UTF-8 BOM Notion writes.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    [$csvName, $hex] = notion_split_name(basename($csvFile));
    if ($name === '' || $name === 'Database') { $name = $csvName; }

    $pdo = db();
    $dbId = null;
    if ($hex !== '') {
        $f = $pdo->prepare("SELECT id FROM pages WHERE notion_id = ?");
        $f->execute([$hex]);
        $dbId = $f->fetchColumn() ?: null;
    }

    if ($dbId) {
        // Re-import: clear rows, keep the schema.
        $pdo->prepare("DELETE FROM pages WHERE parent_id = ?")->execute([$dbId]);
    } else {
        $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM pages WHERE "
            . ($parentId ? "parent_id = " . (int)$parentId : "parent_id IS NULL"))->fetchColumn();
        $pdo->prepare("INSERT INTO pages (parent_id, title, is_database, position, notion_id, updated_at)
                       VALUES (?,?,1,?,?,datetime('now'))")
            ->execute([$parentId, $name, $pos, $hex ?: null]);
        $dbId = (int)$pdo->lastInsertId();
        $stats['databases']++;
        if ($hex !== '') { $idMap[$hex] = (int)$dbId; }
    }

    /* -- properties: first column is the title, the rest become fields ----- */
    $existing = [];
    foreach (db_properties((int)$dbId) as $p) { $existing[$p['name']] = $p; }

    $keys = [];
    foreach ($header as $i => $col) {
        $col = trim((string)$col);
        if ($col === '') { $keys[$i] = null; continue; }
        if ($i === 0) { $keys[$i] = '__title'; continue; }
        if (isset($existing[$col])) { $keys[$i] = $existing[$col]['key']; continue; }
        $pid = add_property((int)$dbId, $col, notion_guess_type($col));
        $k = $pdo->prepare("SELECT key FROM db_properties WHERE id = ?");
        $k->execute([$pid]);
        $keys[$i] = (string)$k->fetchColumn();
    }

    /* -- read the CSV into memory, keyed by title --------------------------- */
    $csvRows = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $title = trim((string)($row[0] ?? '')) ?: 'Untitled';
        $csvRows[] = ['title' => $title, 'cells' => $row];
    }
    fclose($fh);

    /* -- import the row PAGES (content) from the companion folder ----------- */
    // "<dir>/<Name>/" holds one .html per row; each may have children of its own.
    $rowPagesByTitle = [];
    $folder = null;
    foreach ([dirname($csvFile) . '/' . $csvName, dirname($csvFile) . '/' . $name] as $cand) {
        if (is_dir($cand)) { $folder = $cand; break; }
    }
    if ($folder !== null && $depth < 30) {
        foreach (glob($folder . '/*.html') ?: [] as $rowFile) {
            $rid = import_notion_page($rowFile, (int)$dbId, $stats, $idMap, $assetMap, $depth + 1);
            if ($rid) {
                [$rt] = notion_split_name(basename($rowFile));
                $rowPagesByTitle[mb_strtolower(trim($rt))] = $rid;
                // Also key on the rendered title, which can differ from the filename.
                $t = $pdo->prepare("SELECT title FROM pages WHERE id = ?");
                $t->execute([$rid]);
                $rowPagesByTitle[mb_strtolower(trim((string)$t->fetchColumn()))] = $rid;
            }
        }
    }

    /* -- attach CSV values to those pages (or create bare rows) ------------- */
    $rowPos = 0;
    $insPage = $pdo->prepare("INSERT INTO pages (parent_id, title, position, updated_at)
                              VALUES (?,?,?,datetime('now'))");
    foreach ($csvRows as $r) {
        $key = mb_strtolower(trim($r['title']));
        $rid = $rowPagesByTitle[$key] ?? null;
        if ($rid === null) {
            $insPage->execute([$dbId, $r['title'], $rowPos]);
            $rid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE pages SET position = ? WHERE id = ?")->execute([$rowPos, $rid]);
        }
        $rowPos++;
        foreach ($r['cells'] as $i => $val) {
            $k = $keys[$i] ?? null;
            if ($k === null || $k === '__title') continue;
            $val = trim((string)$val);
            if ($val !== '') { set_value((int)$rid, $k, $val); }
        }
        $stats['rows']++;
    }

    // A database with a single select-ish column groups nicely on a board.
    $props = db_properties((int)$dbId);
    foreach ($props as $p) {
        if ($p['type'] === 'select') {
            $pdo->prepare("UPDATE pages SET db_group_by = ? WHERE id = ?")->execute([$p['key'], $dbId]);
            break;
        }
    }
    return (int)$dbId;
}

/** Guess a property type from the column name. */
function notion_guess_type(string $col): string
{
    $c = strtolower($col);
    if (preg_match('/\b(date|due|created|updated|deadline|start|end)\b/', $c)) return 'date';
    if (preg_match('/\b(status|type|category|stage|priority|state)\b/', $c))   return 'select';
    if (preg_match('/\b(tags|labels)\b/', $c))                                 return 'multi';
    if (preg_match('/\b(url|link|website)\b/', $c))                            return 'url';
    if (preg_match('/\b(count|number|amount|total|qty|price|score)\b/', $c))    return 'number';
    if (preg_match('/\b(done|complete|checked)\b/', $c))                       return 'checkbox';
    return 'text';
}

/* ==========================================================================
   Link rewriting — runs after every page exists
   ========================================================================== */

/**
 * Notion links point at sibling .html files. Once every page is imported we
 * can turn those into internal links.
 */
function rewrite_notion_links(array $idMap, array &$stats): void
{
    $pdo = db();
    $rows = $pdo->query("SELECT id, page_id, content FROM blocks WHERE type = 'notion_html'")->fetchAll();
    $upd = $pdo->prepare("UPDATE blocks SET content = ? WHERE id = ?");

    foreach ($rows as $b) {
        $html = (string)$b['content'];
        $orig = $html;
        $html = preg_replace_callback('~href="([^"]+\.html)"~i', function ($m) use ($idMap, &$stats) {
            $target = urldecode($m[1]);
            $base = basename($target);
            if (isset($idMap['file:' . $base])) {
                $stats['links']++;
                return 'href="page.php?id=' . (int)$idMap['file:' . $base] . '"';
            }
            [, $hex] = notion_split_name($base);
            if ($hex !== '' && isset($idMap[$hex])) {
                $stats['links']++;
                return 'href="page.php?id=' . (int)$idMap[$hex] . '"';
            }
            return $m[0];
        }, $html);

        if ($html !== $orig) { $upd->execute([$html, $b['id']]); }
    }
}

/* ==========================================================================
   Entry point
   ========================================================================== */

/** Scan a folder and report what would be imported, without writing anything. */
function notion_scan(string $dir): array
{
    $dir = rtrim($dir, '/');
    $out = ['root_pages' => [], 'html' => 0, 'csv' => 0, 'assets' => 0, 'exists' => is_dir($dir)];
    if (!$out['exists']) return $out;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $ext = strtolower($f->getExtension());
        if ($ext === 'html') { $out['html']++; }
        elseif ($ext === 'csv') { $out['csv']++; }
        elseif (in_array($ext, ['png','jpg','jpeg','gif','webp','svg','pdf','heic','docx'], true)) { $out['assets']++; }
    }
    foreach (glob($dir . '/*.html') ?: [] as $f) {
        [$t] = notion_split_name(basename($f));
        $out['root_pages'][] = $t;
    }
    return $out;
}

/**
 * Import an entire export folder.
 * @param string   $dir      folder containing the top-level .html files
 * @param int|null $parentId where to attach the imported tree (null = top level)
 */
function import_notion_export(string $dir, ?int $parentId = null): array
{
    $dir = rtrim($dir, '/');
    $stats = import_stats_new();
    $idMap = [];
    $assetMap = [];

    $roots = glob($dir . '/*.html') ?: [];
    if (!$roots) {
        $stats['errors'][] = 'No .html files found at the top level of that folder.';
        return $stats;
    }

    // Long imports need headroom; these are advisory and safe to ignore.
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    foreach ($roots as $file) {
        import_notion_page($file, $parentId, $stats, $idMap, $assetMap);
    }

    // Any CSV at the top level that no page referenced becomes a standalone database.
    foreach (glob($dir . '/*.csv') ?: [] as $csv) {
        [$n, $hex] = notion_split_name(basename($csv));
        if ($hex !== '' && isset($idMap[$hex])) continue;
        import_notion_csv($csv, $n, $parentId, $stats, $idMap, $assetMap, 0);
    }

    // Safety net: Notion's folder layout has enough quirks (databases whose
    // rows live in a sibling folder, collections referenced but not inlined)
    // that a pure tree-walk can miss files. Sweep the whole export and import
    // anything still unaccounted for, attaching it to its nearest imported
    // ancestor folder.
    notion_sweep_orphans($dir, $stats, $idMap, $assetMap);

    rewrite_notion_links($idMap, $stats);
    return $stats;
}

/**
 * Import any .html in the export that no earlier pass claimed.
 * Parent is resolved by walking up the directory tree to the first folder
 * whose own page (or database) we already imported.
 */
function notion_sweep_orphans(string $dir, array &$stats, array &$idMap,
                              array &$assetMap, int $rounds = 0): void
{
    if ($rounds > 3) return;

    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) !== 'html') continue;
        [, $hex] = notion_split_name($f->getFilename());
        if ($hex !== '' && isset($idMap[$hex])) continue;
        if (isset($idMap['file:' . $f->getFilename()])) continue;
        $found[] = $f->getPathname();
    }
    if (!$found) return;

    foreach ($found as $file) {
        // Walk up: the containing folder usually corresponds to a page or a
        // database we imported, either by its hex or by its plain name.
        $parentId = null;
        $cur = dirname($file);
        $guard = 0;
        while ($guard++ < 20 && strlen($cur) >= strlen($dir)) {
            $base = basename($cur);
            [, $fhex] = notion_split_name($base);
            if ($fhex !== '' && isset($idMap[$fhex])) { $parentId = $idMap[$fhex]; break; }

            // Match a sibling "<name> <hex>.csv|html" that we did import.
            foreach ((glob(dirname($cur) . '/' . $base . ' *.csv') ?: [])
                   + (glob(dirname($cur) . '/' . $base . ' *.html') ?: []) as $sib) {
                [, $sh] = notion_split_name(basename($sib));
                if ($sh !== '' && isset($idMap[$sh])) { $parentId = $idMap[$sh]; break 2; }
            }
            if (isset($idMap['file:' . $base . '.html'])) {
                $parentId = $idMap['file:' . $base . '.html']; break;
            }
            $cur = dirname($cur);
        }

        import_notion_page($file, $parentId, $stats, $idMap, $assetMap, 0);
    }

    // Newly imported pages may reveal further orphans one level down.
    notion_sweep_orphans($dir, $stats, $idMap, $assetMap, $rounds + 1);
}
