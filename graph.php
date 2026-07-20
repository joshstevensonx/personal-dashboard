<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/notes.php';
require_once __DIR__ . '/partials.php';
require_login();
$pdo = db();

$notes = $pdo->query("SELECT id, title FROM notes WHERE deleted_at IS NULL")->fetchAll();
$links = $pdo->query(
    "SELECT l.source_id, l.target_id FROM note_links l
     JOIN notes s ON s.id = l.source_id AND s.deleted_at IS NULL
     JOIN notes t ON t.id = l.target_id AND t.deleted_at IS NULL
     WHERE l.target_id IS NOT NULL"
)->fetchAll();

$degree = [];
foreach ($links as $l) {
    $degree[$l['source_id']] = ($degree[$l['source_id']] ?? 0) + 1;
    $degree[$l['target_id']] = ($degree[$l['target_id']] ?? 0) + 1;
}

$nodes = array_map(fn($n) => [
    'id' => (int)$n['id'],
    'title' => $n['title'],
    'deg' => $degree[$n['id']] ?? 0,
], $notes);

$edges = array_map(fn($l) => ['s' => (int)$l['source_id'], 't' => (int)$l['target_id']], $links);

$orphans = array_values(array_filter($nodes, fn($n) => $n['deg'] === 0));

page_header('graph.php');
?>
<h1>Note graph</h1>
<p class="sub"><?= count($nodes) ?> notes · <?= count($edges) ?> links · <?= count($orphans) ?> unlinked.
   Drag to pan, scroll to zoom, click a node to open it.</p>

<div class="card" style="padding:0;overflow:hidden">
    <canvas id="graph" style="width:100%;height:560px;display:block;cursor:grab"></canvas>
</div>

<?php if ($orphans): ?>
    <h2 style="margin:22px 0 10px;font-size:14px" class="muted">UNLINKED NOTES</h2>
    <div class="row">
        <?php foreach (array_slice($orphans, 0, 40) as $o): ?>
            <a class="pill" href="notes.php?id=<?= (int)$o['id'] ?>"><?= e($o['title']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
window.GRAPH = { nodes: <?= json_encode($nodes, JSON_UNESCAPED_UNICODE) ?>,
                 edges: <?= json_encode($edges) ?> };
</script>
<script src="assets/graph.js"></script>
<?php page_footer();
