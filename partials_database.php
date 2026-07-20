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

<!-- saved view tabs -->
<div class="db-tabs">
    <?php foreach ($views as $v): $on = $activeView && (int)$v['id'] === (int)$activeView['id']; ?>
        <a class="db-tab <?= $on ? 'on' : '' ?>" href="page.php?id=<?= (int)$pageId ?>&view=<?= (int)$v['id'] ?>">
            <?= e(view_types()[$v['type']] ?? $v['type']) ?><?= $v['name'] !== ucfirst($v['type']) ? ' · ' . e($v['name']) : '' ?>
        </a>
    <?php endforeach; ?>
    <button class="db-tab" type="button" id="addview">+ View</button>

    <span style="flex:1"></span>
    <button class="db-tab" type="button" id="editview">⚙ Configure</button>

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

<!-- add view -->
<div class="picker" id="viewpicker" hidden>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_view">
        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
        <div class="field"><label>View name</label><input name="name" placeholder="By status"></div>
        <div class="field"><label>Type</label>
            <select name="type">
                <?php foreach (view_types() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select></div>
        <button type="submit" style="margin-top:8px">Add view</button>
    </form>
</div>

<!-- configure current view -->
<?php if ($activeView): ?>
<div class="picker" id="viewconfig" hidden style="width:280px">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="update_view">
        <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
        <input type="hidden" name="view_id" value="<?= (int)$activeView['id'] ?>">
        <div class="field"><label>Name</label><input name="name" value="<?= e($activeView['name']) ?>"></div>
        <div class="field"><label>Type</label>
            <select name="type">
                <?php foreach (view_types() as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $activeView['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Group by</label>
            <select name="group_by">
                <option value="">—</option>
                <?php foreach ($props as $p): ?>
                    <option value="<?= e($p['key']) ?>" <?= ($activeView['group_by'] ?? '') === $p['key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Sort by</label>
            <select name="sort_key">
                <option value="">—</option>
                <option value="__title" <?= ($activeView['sort_key'] ?? '') === '__title' ? 'selected' : '' ?>>Name</option>
                <?php foreach ($props as $p): ?>
                    <option value="<?= e($p['key']) ?>" <?= ($activeView['sort_key'] ?? '') === $p['key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Direction</label>
            <select name="sort_dir">
                <option value="asc"  <?= ($activeView['sort_dir'] ?? 'asc') === 'asc' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= ($activeView['sort_dir'] ?? '') === 'desc' ? 'selected' : '' ?>>Descending</option>
            </select></div>
        <div class="field"><label>Filter property</label>
            <select name="filter_key">
                <option value="">—</option>
                <?php foreach ($props as $p): ?>
                    <option value="<?= e($p['key']) ?>" <?= ($activeView['filter_key'] ?? '') === $p['key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Condition</label>
            <select name="filter_op">
                <?php foreach (['contains' => 'Contains', 'is' => 'Is exactly', 'not_empty' => 'Is not empty', 'empty' => 'Is empty'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= ($activeView['filter_op'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Value</label><input name="filter_value" value="<?= e((string)($activeView['filter_value'] ?? '')) ?>"></div>
        <div style="display:flex;gap:6px;margin-top:10px">
            <button type="submit">Save view</button>
        </div>
    </form>
    <?php if (count($views) > 1): ?>
        <form method="post" onsubmit="return confirm('Delete this view?')" style="margin-top:6px">
            <?= csrf_field() ?><input type="hidden" name="action" value="del_view">
            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
            <input type="hidden" name="view_id" value="<?= (int)$activeView['id'] ?>">
            <button class="nbtn" type="submit">Delete view</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($view === 'table'): ?>
    <div class="db-scroll">
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
                            <?php if (in_array($p['type'], ['rollup', 'formula'], true)): ?>
                                <span class="computed"><?= e((string)$val) ?></span>
                            <?php elseif ($p['type'] === 'relation'): ?>
                                <?php $o = prop_options($p); $target = (int)($o['target'] ?? 0); ?>
                                <?php foreach (pages_titles(relation_ids($val)) as $rp): ?>
                                    <a class="rel-chip" href="page.php?id=<?= (int)$rp['id'] ?>">
                                        <?= e($rp['icon'] ?: '📄') ?> <?= e($rp['title']) ?></a>
                                <?php endforeach; ?>
                                <?php if ($target): ?>
                                    <details class="rel-edit"><summary class="db-mini">edit</summary>
                                        <form method="post">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="set_relation">
                                            <input type="hidden" name="page_id" value="<?= (int)$pageId ?>">
                                            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                                            <input type="hidden" name="key" value="<?= e($p['key']) ?>">
                                            <select name="related[]" multiple size="5" style="min-width:150px">
                                                <?php $sel = relation_ids($val);
                                                foreach (relation_candidates($target) as $cand): ?>
                                                    <option value="<?= (int)$cand['id'] ?>" <?= in_array((int)$cand['id'], $sel, true) ? 'selected' : '' ?>>
                                                        <?= e($cand['title']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="db-tab" type="submit">Save</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            <?php elseif ($p['type'] === 'checkbox'): ?>
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

<?php elseif ($view === 'calendar'): ?>
    <?php
    // Use the first date property as the calendar axis.
    $dateKey = '';
    foreach ($props as $p) { if ($p['type'] === 'date') { $dateKey = $p['key']; break; } }
    $month = $_GET['m'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = date('Y-m'); }
    $mStart = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: new DateTime('first day of this month');
    $mStart->setTime(0, 0);
    $gStart = (clone $mStart); $gStart->modify('-' . ((int)$gStart->format('N') - 1) . ' days');
    $mEnd = (clone $mStart)->modify('last day of this month');
    $gEnd = (clone $mEnd); $dEnd = (int)$gEnd->format('N'); if ($dEnd < 7) { $gEnd->modify('+' . (7 - $dEnd) . ' days'); }
    $byDay = [];
    if ($dateKey !== '') {
        foreach ($rows as $r) {
            $d = substr((string)($r['values'][$dateKey] ?? ''), 0, 10);
            if ($d !== '') { $byDay[$d][] = $r; }
        }
    }
    $prevM = (clone $mStart)->modify('-1 month')->format('Y-m');
    $nextM = (clone $mStart)->modify('+1 month')->format('Y-m');
    ?>
    <?php if ($dateKey === ''): ?>
        <div class="empty">Add a <strong>Date</strong> property to use the calendar view.</div>
    <?php else: ?>
        <div class="row" style="align-items:center;margin:12px 0">
            <a class="db-tab" href="page.php?id=<?= (int)$pageId ?>&view=<?= (int)($activeView['id'] ?? 0) ?>&m=<?= e($prevM) ?>">←</a>
            <strong><?= e($mStart->format('F Y')) ?></strong>
            <a class="db-tab" href="page.php?id=<?= (int)$pageId ?>&view=<?= (int)($activeView['id'] ?? 0) ?>&m=<?= e($nextM) ?>">→</a>
        </div>
        <div class="cal">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                <div class="dow"><?= $d ?></div>
            <?php endforeach; ?>
            <?php $cur = clone $gStart; $today = date('Y-m-d');
            while ($cur <= $gEnd): $ds = $cur->format('Y-m-d');
                $out = $cur->format('Y-m') !== $mStart->format('Y-m'); ?>
                <div class="day<?= $out ? ' out' : '' ?><?= $ds === $today ? ' today' : '' ?>">
                    <div class="n"><?= (int)$cur->format('j') ?></div>
                    <?php foreach ($byDay[$ds] ?? [] as $r): ?>
                        <a class="ev" href="page.php?id=<?= (int)$r['id'] ?>" title="<?= e($r['title']) ?>"><?= e($r['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php $cur->modify('+1 day'); endwhile; ?>
        </div>
    <?php endif; ?>

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
                <?php foreach (property_types_all() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field" id="propopts"><label>Options (comma separated)</label>
            <input name="options" placeholder="Low, Medium, High"></div>

        <div class="field" id="proprel"><label>Related database</label>
            <select name="rel_target">
                <?php foreach ($pdo->query("SELECT id, title FROM pages WHERE is_database = 1 AND archived = 0 ORDER BY title") as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= e($d['title']) ?></option>
                <?php endforeach; ?>
            </select></div>

        <div class="field" id="proprollup">
            <label>Rollup: via relation</label>
            <select name="ru_relation">
                <?php foreach ($props as $p): if ($p['type'] !== 'relation') continue; ?>
                    <option value="<?= e($p['key']) ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="margin-top:6px">Property to roll up</label>
            <input name="ru_prop" placeholder="property key, e.g. amount">
            <label style="margin-top:6px">Aggregate</label>
            <select name="ru_agg">
                <?php foreach (rollup_aggregations() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>

        <div class="field" id="propformula"><label>Formula</label>
            <input name="fx" placeholder="{price} * {qty}">
            <span class="muted" style="font-size:12px">Use {key} for properties. Functions: round, abs, min, max, if, concat, len.</span>
        </div>

        <button type="submit" style="margin-top:8px">Add property</button>
    </form>
</div>
