<?php
/**
 * Database views for a page flagged is_database.
 * Included by page.php — expects: $pageId, $page, $props, $rows, $view, $groupBy,
 * $sortKey, $sortDir, $fKey, $fVal.
 */
$qs = function (array $over = []) use ($pageId, $sortKey, $sortDir, $fKey, $fVal) {
    $p = array_filter([
        'id'   => $pageId,
        'sort' => $over['sort'] ?? $sortKey,
        'dir'  => $over['dir']  ?? $sortDir,
        'fkey' => $over['fkey'] ?? $fKey,
        'fval' => $over['fval'] ?? $fVal,
    ], fn($v) => $v !== '' && $v !== null);
    return 'page.php?' . http_build_query($p);
};
?>

<!-- view tabs -->
<div class="db-tabs">
    <?php foreach (['table' => 'Table', 'board' => 'Board', 'gallery' => 'Gallery', 'list' => 'List'] as $v => $label): ?>
        <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_view">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <input type="hidden" name="view" value="<?= $v ?>">
            <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
            <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
            <button class="db-tab <?= $view === $v ? 'on' : '' ?>" type="submit"><?= $label ?></button>
        </form>
    <?php endforeach; ?>

    <span style="flex:1"></span>

    <!-- filter -->
    <form method="get" class="db-toolform">
        <input type="hidden" name="id" value="<?= (int)$pageId ?>">
        <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
        <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
        <select name="fkey" class="db-mini">
            <option value="">Filter…</option>
            <?php foreach ($props as $p): ?>
                <option value="<?= e($p['key']) ?>" <?= $fKey === $p['key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="db-mini" name="fval" value="<?= e($fVal) ?>" placeholder="contains…" style="width:110px">
        <button class="db-tab" type="submit">Apply</button>
        <?php if ($fKey !== ''): ?><a class="db-tab" href="<?= e($qs(['fkey' => '', 'fval' => ''])) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if ($view === 'board'): ?>
        <form method="post" class="db-toolform">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_group">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <select name="group_by" class="db-mini" onchange="this.form.submit()">
                <option value="">Group by…</option>
                <?php foreach ($props as $p): if (!in_array($p['type'], ['select', 'multi', 'text'], true)) continue; ?>
                    <option value="<?= e($p['key']) ?>" <?= $groupBy === $p['key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if ($view === 'table'): ?>
    <div style="overflow-x:auto">
    <table class="db-table">
        <thead>
            <tr>
                <th style="min-width:220px">
                    <a href="<?= e($qs(['sort' => '__title', 'dir' => $sortKey === '__title' && $sortDir === 'asc' ? 'desc' : 'asc'])) ?>">
                        Name <?= $sortKey === '__title' ? ($sortDir === 'asc' ? '↑' : '↓') : '' ?></a>
                </th>
                <?php foreach ($props as $p): ?>
                    <th>
                        <a href="<?= e($qs(['sort' => $p['key'], 'dir' => $sortKey === $p['key'] && $sortDir === 'asc' ? 'desc' : 'asc'])) ?>">
                            <?= e($p['name']) ?> <?= $sortKey === $p['key'] ? ($sortDir === 'asc' ? '↑' : '↓') : '' ?></a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this property and its values?')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="del_prop">
                            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
                            <input type="hidden" name="prop_id" value="<?= (int)$p['id'] ?>">
                            <button class="db-x" type="submit" title="Delete property">×</button></form>
                    </th>
                <?php endforeach; ?>
                <th style="width:150px"><button class="db-tab" type="button" id="addprop">+ Property</button></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="page.php?id=<?= (int)$r['id'] ?>" class="db-rowlink">
                        <?= e($r['icon'] ?: '📄') ?> <?= e($r['title']) ?></a></td>
                    <?php foreach ($props as $p): $val = $r['values'][$p['key']] ?? ''; ?>
                        <td>
                            <?php if ($p['type'] === 'checkbox'): ?>
                                <input type="checkbox" class="db-editcheck" data-row="<?= (int)$r['id'] ?>"
                                       data-key="<?= e($p['key']) ?>" <?= $val === '1' ? 'checked' : '' ?>>
                            <?php elseif (in_array($p['type'], ['select', 'multi'], true)): ?>
                                <?php $opts = json_decode((string)$p['options'], true) ?: []; ?>
                                <select class="db-editselect db-mini" data-row="<?= (int)$r['id'] ?>" data-key="<?= e($p['key']) ?>">
                                    <option value="">—</option>
                                    <?php foreach ($opts as $o): ?>
                                        <option value="<?= e($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($p['type'] === 'date'): ?>
                                <input type="date" class="db-editinput db-mini" data-row="<?= (int)$r['id'] ?>"
                                       data-key="<?= e($p['key']) ?>" value="<?= e($val) ?>">
                            <?php else: ?>
                                <div class="db-cell" contenteditable="true" data-row="<?= (int)$r['id'] ?>"
                                     data-key="<?= e($p['key']) ?>"><?= e($val) ?></div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="<?= count($props) + 2 ?>">
                    <form method="post" class="db-newrow">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_row">
                        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
                        <input name="title" placeholder="+ New row" class="db-newinput">
                    </form>
                </td>
            </tr>
        </tbody>
    </table>
    </div>

<?php elseif ($view === 'board'): ?>
    <?php
    $gk = $groupBy ?: ($props[0]['key'] ?? '');
    $cols = $gk ? group_values($pageId, $gk, $rows) : [];
    $ungrouped = array_values(array_filter($rows, fn($r) => trim((string)($r['values'][$gk] ?? '')) === ''));
    ?>
    <?php if (!$gk): ?>
        <div class="empty">Add a select property, then choose "Group by" to build board columns.</div>
    <?php else: ?>
        <div class="db-board">
            <?php foreach ($cols as $c):
                $inCol = array_values(array_filter($rows, fn($r) => (string)($r['values'][$gk] ?? '') === $c)); ?>
                <div class="db-col">
                    <div class="db-colhead">
                        <span class="tag" style="background:<?= tag_color($c) ?>"><?= e($c) ?></span>
                        <span class="muted"><?= count($inCol) ?></span>
                    </div>
                    <?php foreach ($inCol as $r): ?>
                        <a class="db-card" href="page.php?id=<?= (int)$r['id'] ?>">
                            <div><?= e($r['icon'] ? $r['icon'] . ' ' : '') ?><?= e($r['title']) ?></div>
                            <?php foreach ($props as $p): if ($p['key'] === $gk) continue;
                                $v = $r['values'][$p['key']] ?? ''; if ($v === '') continue; ?>
                                <div class="db-cardmeta"><?= render_value($p, $v) ?></div>
                            <?php endforeach; ?>
                        </a>
                    <?php endforeach; ?>
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_row">
                        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
                        <input type="hidden" name="group_key" value="<?= e($gk) ?>">
                        <input type="hidden" name="group_value" value="<?= e($c) ?>">
                        <input name="title" placeholder="+ New" class="db-newinput">
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if ($ungrouped): ?>
                <div class="db-col">
                    <div class="db-colhead"><span class="muted">No value</span><span class="muted"><?= count($ungrouped) ?></span></div>
                    <?php foreach ($ungrouped as $r): ?>
                        <a class="db-card" href="page.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php elseif ($view === 'gallery'): ?>
    <div class="db-gallery">
        <?php foreach ($rows as $r): $cc = cover_css($r['cover']); ?>
            <a class="db-gcard" href="page.php?id=<?= (int)$r['id'] ?>">
                <div class="db-gcover" style="background:<?= e($cc ?: 'var(--n-hover)') ?>">
                    <?php if (!$cc): ?><span style="font-size:30px"><?= e($r['icon'] ?: '📄') ?></span><?php endif; ?>
                </div>
                <div class="db-gbody">
                    <div class="db-gtitle"><?= e($r['title']) ?></div>
                    <?php foreach ($props as $p): $v = $r['values'][$p['key']] ?? ''; if ($v === '') continue; ?>
                        <div class="db-cardmeta"><?= render_value($p, $v) ?></div>
                    <?php endforeach; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="post" class="row" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_row">
        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
        <input name="title" placeholder="+ New item" class="db-newinput" style="max-width:240px">
    </form>

<?php else: /* list */ ?>
    <div class="list" style="margin-top:12px">
        <?php foreach ($rows as $r): ?>
            <a class="item" style="text-decoration:none;color:inherit;padding:9px 12px" href="page.php?id=<?= (int)$r['id'] ?>">
                <span style="width:22px"><?= e($r['icon'] ?: '📄') ?></span>
                <span class="grow"><?= e($r['title']) ?></span>
                <?php foreach ($props as $p): $v = $r['values'][$p['key']] ?? ''; if ($v === '') continue; ?>
                    <span><?= render_value($p, $v) ?></span>
                <?php endforeach; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="post" class="row" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_row">
        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
        <input name="title" placeholder="+ New item" class="db-newinput" style="max-width:240px">
    </form>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty" style="margin-top:14px">No rows yet. Add one above.</div>
<?php endif; ?>

<!-- add property -->
<div class="picker" id="proppicker" hidden>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_prop">
        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
        <div class="field"><label>Property name</label><input name="name" placeholder="Priority" required></div>
        <div class="field"><label>Type</label>
            <select name="type" id="proptype">
                <?php foreach (property_types() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field" id="propopts"><label>Options (comma separated)</label>
            <input name="options" placeholder="Low, Medium, High"></div>
        <button type="submit" style="margin-top:8px">Add property</button>
    </form>
</div>
