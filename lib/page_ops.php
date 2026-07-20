<?php
/**
 * Page operations: duplicate, move, trash/restore, templates, comments,
 * and cross-page search.
 */

/* ------------------------------------------------------------- duplicate -- */

/** Deep-copy a page (blocks, property values, and optionally children). */
function duplicate_page(int $id, ?int $newParent = null, bool $deep = true, int $depth = 0): ?int
{
    if ($depth > 8) return null;
    $pdo = db();
    $src = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
    $src->execute([$id]);
    $p = $src->fetch();
    if (!$p) return null;

    $parent = $newParent !== null ? $newParent : $p['parent_id'];
    $title = $depth === 0 ? $p['title'] . ' (copy)' : $p['title'];

    $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM pages WHERE "
        . ($parent ? "parent_id = " . (int)$parent : "parent_id IS NULL"))->fetchColumn();

    $ins = $pdo->prepare("INSERT INTO pages (parent_id, title, icon, cover, is_database, db_view,
                          db_group_by, position, favorite, is_template, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,0,?,datetime('now'))");
    $ins->execute([$parent, $title, $p['icon'], $p['cover'], $p['is_database'],
                   $p['db_view'], $p['db_group_by'], $pos, $p['is_template'] ?? 0]);
    $new = (int)$pdo->lastInsertId();

    // blocks
    $b = $pdo->prepare("SELECT type, content, props, position, indent FROM blocks WHERE page_id = ? ORDER BY position");
    $b->execute([$id]);
    $bi = $pdo->prepare("INSERT INTO blocks (page_id, type, content, props, position, indent, updated_at)
                         VALUES (?,?,?,?,?,?,datetime('now'))");
    foreach ($b->fetchAll() as $row) {
        $bi->execute([$new, $row['type'], $row['content'], $row['props'], $row['position'], $row['indent'] ?? 0]);
    }

    // property values
    $v = $pdo->prepare("SELECT key, value FROM page_values WHERE page_id = ?");
    $v->execute([$id]);
    $vi = $pdo->prepare("INSERT OR REPLACE INTO page_values (page_id, key, value) VALUES (?,?,?)");
    foreach ($v->fetchAll() as $row) { $vi->execute([$new, $row['key'], $row['value']]); }

    // database column definitions
    if (!empty($p['is_database'])) {
        $dp = $pdo->prepare("SELECT key, name, type, options, position FROM db_properties WHERE database_id = ?");
        $dp->execute([$id]);
        $di = $pdo->prepare("INSERT INTO db_properties (database_id, key, name, type, options, position) VALUES (?,?,?,?,?,?)");
        foreach ($dp->fetchAll() as $row) {
            $di->execute([$new, $row['key'], $row['name'], $row['type'], $row['options'], $row['position']]);
        }
        $vw = $pdo->prepare("SELECT name, type, group_by, sort_key, sort_dir, filter_key, filter_op, filter_value, hidden_props, position
                             FROM db_views WHERE database_id = ?");
        $vw->execute([$id]);
        $vwi = $pdo->prepare("INSERT INTO db_views (database_id, name, type, group_by, sort_key, sort_dir, filter_key, filter_op, filter_value, hidden_props, position)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($vw->fetchAll() as $row) {
            $vwi->execute([$new, $row['name'], $row['type'], $row['group_by'], $row['sort_key'], $row['sort_dir'],
                           $row['filter_key'], $row['filter_op'], $row['filter_value'], $row['hidden_props'], $row['position']]);
        }
    }

    if ($deep) {
        $kids = $pdo->prepare("SELECT id FROM pages WHERE parent_id = ? AND archived = 0");
        $kids->execute([$id]);
        foreach ($kids->fetchAll() as $k) {
            duplicate_page((int)$k['id'], $new, true, $depth + 1);
        }
    }
    return $new;
}

/* ------------------------------------------------------------------ move -- */

/** Reparent a page, refusing moves that would create a cycle. */
function move_page(int $id, ?int $newParent): bool
{
    if ($id === $newParent) return false;
    if ($newParent !== null) {
        // Walk up from the target; if we meet $id, this move would loop.
        $cur = $newParent;
        $st = db()->prepare("SELECT parent_id FROM pages WHERE id = ?");
        $guard = 0;
        while ($cur && $guard++ < 50) {
            if ((int)$cur === $id) return false;
            $st->execute([$cur]);
            $cur = $st->fetchColumn();
        }
    }
    db()->prepare("UPDATE pages SET parent_id = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$newParent, $id]);
    return true;
}

/* ----------------------------------------------------------------- trash -- */

function trashed_pages(): array
{
    return db()->query("SELECT id, title, icon, is_database, updated_at
                        FROM pages WHERE archived = 1 ORDER BY COALESCE(updated_at, created_at) DESC")->fetchAll();
}

function restore_page(int $id): void
{
    db()->prepare("UPDATE pages SET archived = 0 WHERE id = ?")->execute([$id]);
}

/** Permanently delete a page and everything under it. */
function purge_page(int $id): void
{
    $pdo = db();
    $ids = [$id];
    $queue = [$id];
    $st = $pdo->prepare("SELECT id FROM pages WHERE parent_id = ?");
    $guard = 0;
    while ($queue && $guard++ < 500) {
        $cur = array_shift($queue);
        $st->execute([$cur]);
        foreach ($st->fetchAll() as $c) { $ids[] = (int)$c['id']; $queue[] = (int)$c['id']; }
    }
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE FROM pages WHERE id IN ($in)");
}

function empty_trash(): int
{
    $pdo = db();
    $n = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE archived = 1")->fetchColumn();
    $pdo->exec("DELETE FROM pages WHERE archived = 1");
    return $n;
}

/* ------------------------------------------------------------- templates -- */

function templates_list(): array
{
    return db()->query("SELECT id, title, icon, is_database FROM pages
                        WHERE is_template = 1 AND archived = 0 ORDER BY title")->fetchAll();
}

function create_from_template(int $templateId, ?int $parent): ?int
{
    $new = duplicate_page($templateId, $parent, true);
    if ($new) {
        db()->prepare("UPDATE pages SET is_template = 0, title = REPLACE(title, ' (copy)', '') WHERE id = ?")
            ->execute([$new]);
    }
    return $new;
}

/* -------------------------------------------------------------- comments -- */

function page_comments(int $pageId, bool $includeResolved = false): array
{
    $sql = "SELECT * FROM page_comments WHERE page_id = ?"
         . ($includeResolved ? '' : ' AND resolved = 0')
         . " ORDER BY created_at";
    $st = db()->prepare($sql);
    $st->execute([$pageId]);
    return $st->fetchAll();
}

function add_comment(int $pageId, string $body, ?int $blockId = null): int
{
    db()->prepare("INSERT INTO page_comments (page_id, block_id, body) VALUES (?,?,?)")
        ->execute([$pageId, $blockId, $body]);
    return (int)db()->lastInsertId();
}

function resolve_comment(int $id): void
{
    db()->prepare("UPDATE page_comments SET resolved = 1 - resolved WHERE id = ?")->execute([$id]);
}

function delete_comment(int $id): void
{
    db()->prepare("DELETE FROM page_comments WHERE id = ?")->execute([$id]);
}

function comment_counts(): array
{
    $out = [];
    foreach (db()->query("SELECT page_id, COUNT(*) c FROM page_comments WHERE resolved = 0 GROUP BY page_id") as $r) {
        $out[(int)$r['page_id']] = (int)$r['c'];
    }
    return $out;
}

/* ---------------------------------------------------------------- search -- */

/** Search page titles and block content. */
function search_pages(string $q, int $limit = 40): array
{
    $q = trim($q);
    if ($q === '') return [];
    $like = '%' . $q . '%';
    $st = db()->prepare(
        "SELECT p.id, p.title, p.icon, p.is_database,
                (SELECT b.content FROM blocks b
                  WHERE b.page_id = p.id AND b.content LIKE ? LIMIT 1) AS snippet
         FROM pages p
         WHERE p.archived = 0
           AND (p.title LIKE ? OR EXISTS (
                SELECT 1 FROM blocks b2 WHERE b2.page_id = p.id AND b2.content LIKE ?))
         ORDER BY (p.title LIKE ?) DESC, COALESCE(p.updated_at, p.created_at) DESC
         LIMIT ?"
    );
    $st->execute([$like, $like, $like, $like, $limit]);
    return $st->fetchAll();
}
