<?php
/**
 * Official Notion API client (api.notion.com/v1).
 *
 * Auth is an internal integration secret stored in settings under
 * 'notion_token'. The integration only sees pages that have been explicitly
 * connected to it in Notion — that's Notion's model, not a limitation here.
 *
 * Scope: pull pages/databases into local pages, and push local edits back.
 */

const NOTION_API = 'https://api.notion.com/v1/';
const NOTION_VERSION = '2022-06-28';

function notion_token(): string
{
    return trim((string)setting('notion_token', ''));
}

function notion_api_request(string $method, string $path, ?array $body = null): array
{
    $token = notion_token();
    if ($token === '') {
        throw new RuntimeException('No integration token saved yet.');
    }

    $ch = curl_init(NOTION_API . ltrim($path, '/'));
    $headers = [
        'Authorization: Bearer ' . $token,
        'Notion-Version: ' . NOTION_VERSION,
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Connection failed: ' . $err);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Unexpected response (HTTP $code).");
    }
    if ($code >= 400) {
        $msg = $data['message'] ?? 'HTTP ' . $code;
        if ($code === 401) { $msg = 'Token rejected. Check the integration secret.'; }
        if ($code === 404) { $msg .= ' — is the page shared with your integration?'; }
        throw new RuntimeException($msg);
    }
    return $data;
}

function notion_api_get(string $path): array
{
    return notion_api_request('GET', $path);
}

function notion_api_post(string $path, array $body): array
{
    return notion_api_request('POST', $path, $body);
}

/* ------------------------------------------------------------- reading --- */

/** Flatten Notion's rich_text array to plain text. */
function notion_rich_text(array $rt): string
{
    $out = '';
    foreach ($rt as $piece) {
        $out .= $piece['plain_text'] ?? ($piece['text']['content'] ?? '');
    }
    return $out;
}

/** Page title from a page object (the title property varies by parent type). */
function notion_page_title(array $page): string
{
    foreach ($page['properties'] ?? [] as $prop) {
        if (($prop['type'] ?? '') === 'title') {
            $t = notion_rich_text($prop['title'] ?? []);
            if ($t !== '') return $t;
        }
    }
    return 'Untitled';
}

/** Map a Notion block object to one of our block types. */
function notion_block_to_local(array $b): ?array
{
    $type = $b['type'] ?? '';
    $data = $b[$type] ?? [];
    $text = isset($data['rich_text']) ? notion_rich_text($data['rich_text']) : '';

    $map = [
        'paragraph'         => 'paragraph',
        'heading_1'         => 'heading1',
        'heading_2'         => 'heading2',
        'heading_3'         => 'heading3',
        'bulleted_list_item'=> 'bulleted',
        'numbered_list_item'=> 'numbered',
        'to_do'             => 'todo',
        'toggle'            => 'toggle',
        'quote'             => 'quote',
        'callout'           => 'callout',
        'code'              => 'code',
        'divider'           => 'divider',
        'table_of_contents' => 'toc',
        'equation'          => 'equation',
        'breadcrumb'        => 'breadcrumb',
    ];

    if ($type === 'image') {
        $url = $data['file']['url'] ?? $data['external']['url'] ?? '';
        return $url ? ['type' => 'image', 'content' => $url, 'props' => []] : null;
    }
    if ($type === 'bookmark' || $type === 'embed' || $type === 'video') {
        $url = $data['url'] ?? '';
        return $url ? ['type' => $type === 'bookmark' ? 'bookmark' : 'embed',
                       'content' => $url, 'props' => []] : null;
    }
    if (!isset($map[$type])) { return null; }

    $props = ['notion_id' => $b['id'] ?? ''];
    if ($type === 'to_do')   { $props['checked'] = !empty($data['checked']); }
    if ($type === 'callout') { $props['emoji'] = $data['icon']['emoji'] ?? '💡'; }
    if ($type === 'code')    { $props['lang'] = $data['language'] ?? ''; }
    if ($type === 'equation'){ $text = $data['expression'] ?? $text; }

    return ['type' => $map[$type], 'content' => $text, 'props' => $props];
}

/** Fetch every block of a page, following pagination. */
function notion_fetch_blocks(string $pageId, int $depth = 0): array
{
    if ($depth > 3) return [];
    $out = [];
    $cursor = null;
    do {
        $path = 'blocks/' . $pageId . '/children?page_size=100'
              . ($cursor ? '&start_cursor=' . urlencode($cursor) : '');
        $res = notion_api_get($path);
        foreach ($res['results'] ?? [] as $b) {
            $local = notion_block_to_local($b);
            if ($local) { $out[] = $local; }
            // Children of toggles/columns are flattened one level.
            if (!empty($b['has_children']) && $depth < 3) {
                foreach (notion_fetch_blocks($b['id'], $depth + 1) as $child) {
                    $out[] = $child;
                }
            }
        }
        $cursor = $res['next_cursor'] ?? null;
    } while (!empty($res['has_more']) && $cursor);
    return $out;
}

/** Write a fetched page into local storage (update if already imported). */
function notion_store_page(array $page, ?int $parentId): int
{
    $pdo = db();
    $nid = str_replace('-', '', (string)($page['id'] ?? ''));
    $title = notion_page_title($page);
    $icon = $page['icon']['emoji'] ?? null;
    $cover = $page['cover']['external']['url'] ?? $page['cover']['file']['url'] ?? null;

    $f = $pdo->prepare("SELECT id FROM pages WHERE notion_id = ?");
    $f->execute([$nid]);
    $id = $f->fetchColumn();

    if ($id) {
        $pdo->prepare("UPDATE pages SET title=?, icon=COALESCE(?,icon), updated_at=datetime('now'),
                       notion_synced_at=datetime('now') WHERE id=?")
            ->execute([$title, $icon, $id]);
        $pdo->prepare("DELETE FROM blocks WHERE page_id=?")->execute([$id]);
    } else {
        $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM pages WHERE "
            . ($parentId ? "parent_id = " . (int)$parentId : "parent_id IS NULL"))->fetchColumn();
        $pdo->prepare("INSERT INTO pages (parent_id, title, icon, position, notion_id,
                       updated_at, notion_synced_at)
                       VALUES (?,?,?,?,?,datetime('now'),datetime('now'))")
            ->execute([$parentId, $title, $icon, $pos, $nid]);
        $id = (int)$pdo->lastInsertId();
    }

    $blocks = notion_fetch_blocks((string)$page['id']);
    $ins = $pdo->prepare("INSERT INTO blocks (page_id, type, content, props, position, updated_at)
                          VALUES (?,?,?,?,?,datetime('now'))");
    foreach ($blocks as $i => $b) {
        $ins->execute([$id, $b['type'], $b['content'], json_encode($b['props']), $i]);
    }
    if (!$blocks) {
        $ins->execute([$id, 'paragraph', '', null, 0]);
    }
    return (int)$id;
}

/** Pull every page the integration can see. */
function notion_api_pull(?int $parentId = null): array
{
    @set_time_limit(0);
    $stats = ['pages' => 0, 'blocks' => 0, 'errors' => []];
    $cursor = null;

    do {
        $body = ['page_size' => 50, 'filter' => ['property' => 'object', 'value' => 'page']];
        if ($cursor) { $body['start_cursor'] = $cursor; }
        $res = notion_api_post('search', $body);

        foreach ($res['results'] ?? [] as $page) {
            try {
                notion_store_page($page, $parentId);
                $stats['pages']++;
            } catch (Throwable $e) {
                $stats['errors'][] = substr($e->getMessage(), 0, 120);
            }
        }
        $cursor = $res['next_cursor'] ?? null;
    } while (!empty($res['has_more']) && $cursor);

    if ($stats['pages'] === 0 && !$stats['errors']) {
        $stats['errors'][] = 'The integration can see no pages. In Notion, open a page → '
                           . '⋯ → Connections → add your integration.';
    }
    return $stats;
}

/* ------------------------------------------------------------- writing --- */

/** Convert one local block back into a Notion block object. */
function local_block_to_notion(array $b): ?array
{
    $rt = [['type' => 'text', 'text' => ['content' => mb_substr((string)$b['content'], 0, 2000)]]];
    $props = json_decode((string)($b['props'] ?? ''), true) ?: [];

    switch ($b['type']) {
        case 'heading1': return ['object'=>'block','type'=>'heading_1','heading_1'=>['rich_text'=>$rt]];
        case 'heading2': return ['object'=>'block','type'=>'heading_2','heading_2'=>['rich_text'=>$rt]];
        case 'heading3': return ['object'=>'block','type'=>'heading_3','heading_3'=>['rich_text'=>$rt]];
        case 'bulleted': return ['object'=>'block','type'=>'bulleted_list_item','bulleted_list_item'=>['rich_text'=>$rt]];
        case 'numbered': return ['object'=>'block','type'=>'numbered_list_item','numbered_list_item'=>['rich_text'=>$rt]];
        case 'todo':     return ['object'=>'block','type'=>'to_do','to_do'=>['rich_text'=>$rt,'checked'=>!empty($props['checked'])]];
        case 'toggle':   return ['object'=>'block','type'=>'toggle','toggle'=>['rich_text'=>$rt]];
        case 'quote':    return ['object'=>'block','type'=>'quote','quote'=>['rich_text'=>$rt]];
        case 'callout':  return ['object'=>'block','type'=>'callout','callout'=>['rich_text'=>$rt,
                                  'icon'=>['emoji'=>$props['emoji'] ?? '💡']]];
        case 'code':     return ['object'=>'block','type'=>'code','code'=>['rich_text'=>$rt,
                                  'language'=>$props['lang'] ?? 'plain text']];
        case 'divider':  return ['object'=>'block','type'=>'divider','divider'=>new stdClass()];
        case 'paragraph':return ['object'=>'block','type'=>'paragraph','paragraph'=>['rich_text'=>$rt]];
    }
    return null;   // notion_html and app-specific blocks aren't pushed
}

/**
 * Push a local page's blocks back to its Notion counterpart.
 * Notion's API can't replace a page's children wholesale, so existing blocks
 * are deleted (archived) first, then the current set is appended.
 */
function notion_api_push(int $pageId): array
{
    $pdo = db();
    $p = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
    $p->execute([$pageId]);
    $page = $p->fetch();
    if (!$page) { throw new RuntimeException('No such page.'); }
    if (empty($page['notion_id'])) {
        throw new RuntimeException('This page did not come from Notion, so there is nothing to push to.');
    }
    $nid = notion_uuid((string)$page['notion_id']);

    // Archive existing children.
    $existing = notion_api_get('blocks/' . $nid . '/children?page_size=100');
    foreach ($existing['results'] ?? [] as $b) {
        try { notion_api_request('DELETE', 'blocks/' . $b['id']); } catch (Throwable $e) { /* keep going */ }
    }

    $bs = $pdo->prepare("SELECT * FROM blocks WHERE page_id = ? ORDER BY position, id");
    $bs->execute([$pageId]);
    $children = [];
    foreach ($bs->fetchAll() as $b) {
        $nb = local_block_to_notion($b);
        if ($nb) { $children[] = $nb; }
    }

    $sent = 0;
    // The API accepts 100 children per call.
    foreach (array_chunk($children, 100) as $chunk) {
        notion_api_request('PATCH', 'blocks/' . $nid . '/children', ['children' => $chunk]);
        $sent += count($chunk);
    }

    $pdo->prepare("UPDATE pages SET notion_synced_at = datetime('now') WHERE id = ?")->execute([$pageId]);
    return ['pushed' => $sent];
}
