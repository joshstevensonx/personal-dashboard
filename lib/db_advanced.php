<?php
/**
 * Relations, rollups and formulas — the parts of a Notion database that
 * reference other data rather than storing it directly.
 *
 * Storage:
 *   relation  options {"target": <database page id>}   value = "12,34,56" (page ids)
 *   rollup    options {"relation": "<key>", "target_prop": "<key>", "agg": "sum"}
 *   formula   options {"expr": "{price} * {qty}"}
 * Rollup and formula values are computed on read — never stored.
 */

/** Property types including the computed ones. */
function property_types_all(): array
{
    return property_types() + [
        'relation' => 'Relation',
        'rollup'   => 'Rollup',
        'formula'  => 'Formula',
    ];
}

function prop_options(array $prop): array
{
    $o = json_decode((string)($prop['options'] ?? ''), true);
    return is_array($o) ? $o : [];
}

/* -------------------------------------------------------------- relations -- */

/** Page ids referenced by a relation value. */
function relation_ids(?string $value): array
{
    if (!$value) return [];
    return array_values(array_filter(array_map('intval', explode(',', $value))));
}

/** Titles for a set of page ids, in the given order. */
function pages_titles(array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    foreach (db()->query("SELECT id, title, icon FROM pages WHERE id IN ($in)") as $r) {
        $out[(int)$r['id']] = $r;
    }
    // Preserve the stored order.
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($out[$id])) $ordered[] = $out[$id];
    }
    return $ordered;
}

/** Candidate rows for a relation picker. */
function relation_candidates(int $targetDb, int $limit = 200): array
{
    $st = db()->prepare("SELECT id, title, icon FROM pages
                         WHERE parent_id = ? AND archived = 0 ORDER BY title LIMIT ?");
    $st->execute([$targetDb, $limit]);
    return $st->fetchAll();
}

/* ---------------------------------------------------------------- rollups -- */

function rollup_aggregations(): array
{
    return [
        'count'    => 'Count',
        'sum'      => 'Sum',
        'avg'      => 'Average',
        'min'      => 'Min',
        'max'      => 'Max',
        'values'   => 'Show values',
        'checked'  => 'Count checked',
        'percent'  => 'Percent checked',
    ];
}

/**
 * Compute a rollup for one row.
 * Follows the row's relation property to the linked pages, reads a property
 * from each, then aggregates.
 */
function compute_rollup(array $prop, array $row): string
{
    $o = prop_options($prop);
    $relKey = (string)($o['relation'] ?? '');
    $targetProp = (string)($o['target_prop'] ?? '');
    $agg = (string)($o['agg'] ?? 'count');
    if ($relKey === '') return '';

    $ids = relation_ids($row['values'][$relKey] ?? '');
    if (!$ids) return $agg === 'count' ? '0' : '';

    if ($agg === 'count' && $targetProp === '') {
        return (string)count($ids);
    }

    $in = implode(',', array_map('intval', $ids));
    $st = db()->prepare("SELECT page_id, value FROM page_values WHERE key = ? AND page_id IN ($in)");
    $st->execute([$targetProp]);
    $vals = [];
    foreach ($st as $r) { $vals[] = (string)$r['value']; }

    $nums = array_map('floatval', array_filter($vals, 'is_numeric'));

    switch ($agg) {
        case 'count':   return (string)count($vals);
        case 'sum':     return $nums ? rtrim(rtrim(number_format(array_sum($nums), 2, '.', ''), '0'), '.') : '0';
        case 'avg':     return $nums ? rtrim(rtrim(number_format(array_sum($nums) / count($nums), 2, '.', ''), '0'), '.') : '';
        case 'min':     return $nums ? (string)min($nums) : '';
        case 'max':     return $nums ? (string)max($nums) : '';
        case 'checked': return (string)count(array_filter($vals, fn($v) => $v === '1'));
        case 'percent':
            if (!count($ids)) return '';
            $c = count(array_filter($vals, fn($v) => $v === '1'));
            return round($c / count($ids) * 100) . '%';
        case 'values':  return implode(', ', array_filter($vals));
    }
    return '';
}

/* --------------------------------------------------------------- formulas -- */

/**
 * Evaluate a formula for one row.
 *
 * Supports {property} references, numbers, strings, + - * / ( ), and the
 * functions round(), abs(), min(), max(), if(cond, a, b), concat(), len().
 * Comparisons: > < >= <= == !=
 *
 * This is a small hand-written parser — deliberately NOT eval(), which would
 * let a formula run arbitrary PHP.
 */
function compute_formula(array $prop, array $row, array $allProps = []): string
{
    $o = prop_options($prop);
    $expr = (string)($o['expr'] ?? '');
    if (trim($expr) === '') return '';

    // Substitute {property} with its literal value.
    $expr = preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($row) {
        $key = trim($m[1]);
        // A real property always wins; {title}/{name} fall back to the page
        // title only when no property of that name exists.
        if (array_key_exists($key, $row['values'] ?? [])) {
            $v = $row['values'][$key];
        } elseif ($key === 'title' || $key === 'name') {
            $v = $row['title'] ?? '';
        } else {
            $v = '';
        }
        if (is_numeric($v)) return $v;
        return '"' . str_replace('"', '', (string)$v) . '"';
    }, $expr);

    try {
        $t = new FormulaParser($expr);
        $val = $t->parse();
        if (is_bool($val)) return $val ? 'true' : 'false';
        if (is_float($val)) {
            if (is_nan($val) || is_infinite($val)) return '';
            return rtrim(rtrim(number_format($val, 4, '.', ''), '0'), '.');
        }
        return (string)$val;
    } catch (Throwable $e) {
        return '⚠ ' . $e->getMessage();
    }
}

/**
 * Recursive-descent expression parser. Safe by construction: it only ever
 * produces numbers, strings and booleans from a fixed grammar.
 */
class FormulaParser
{
    private array $t = [];
    private int $i = 0;

    public function __construct(string $expr)
    {
        preg_match_all('/\s*("(?:[^"\\\\]|\\\\.)*"|\d+\.?\d*|>=|<=|==|!=|[-+*\/()<>,]|[A-Za-z_]\w*)/', $expr, $m);
        $this->t = array_map('trim', $m[1]);
    }

    private function peek(): ?string { return $this->t[$this->i] ?? null; }
    private function next(): ?string { return $this->t[$this->i++] ?? null; }
    private function expect(string $s): void
    {
        if ($this->next() !== $s) throw new RuntimeException("expected $s");
    }

    public function parse()
    {
        $v = $this->comparison();
        if ($this->i < count($this->t)) throw new RuntimeException('unexpected input');
        return $v;
    }

    private function comparison()
    {
        $l = $this->addsub();
        $op = $this->peek();
        if (in_array($op, ['>', '<', '>=', '<=', '==', '!='], true)) {
            $this->next();
            $r = $this->addsub();
            return match ($op) {
                '>' => $l > $r, '<' => $l < $r, '>=' => $l >= $r,
                '<=' => $l <= $r, '==' => $l == $r, '!=' => $l != $r,
            };
        }
        return $l;
    }

    private function addsub()
    {
        $v = $this->muldiv();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->next();
            $r = $this->muldiv();
            if ($op === '+') {
                $v = (is_numeric($v) && is_numeric($r)) ? $v + $r : ((string)$v . (string)$r);
            } else {
                $v = $v - $r;
            }
        }
        return $v;
    }

    private function muldiv()
    {
        $v = $this->unary();
        while (in_array($this->peek(), ['*', '/'], true)) {
            $op = $this->next();
            $r = $this->unary();
            if ($op === '*') { $v = $v * $r; }
            else {
                if ((float)$r == 0.0) throw new RuntimeException('divide by zero');
                $v = $v / $r;
            }
        }
        return $v;
    }

    private function unary()
    {
        if ($this->peek() === '-') { $this->next(); return -$this->unary(); }
        return $this->primary();
    }

    private function primary()
    {
        $tok = $this->next();
        if ($tok === null) throw new RuntimeException('unexpected end');

        if ($tok === '(') { $v = $this->comparison(); $this->expect(')'); return $v; }
        if ($tok[0] === '"') return stripslashes(substr($tok, 1, -1));
        if (is_numeric($tok)) return $tok + 0;

        // function call
        if (preg_match('/^[A-Za-z_]\w*$/', $tok)) {
            $fn = strtolower($tok);
            if ($this->peek() !== '(') {
                if ($fn === 'true') return true;
                if ($fn === 'false') return false;
                throw new RuntimeException("unknown name $tok");
            }
            $this->expect('(');
            $args = [];
            if ($this->peek() !== ')') {
                $args[] = $this->comparison();
                while ($this->peek() === ',') { $this->next(); $args[] = $this->comparison(); }
            }
            $this->expect(')');
            return $this->call($fn, $args);
        }
        throw new RuntimeException("unexpected $tok");
    }

    private function call(string $fn, array $a)
    {
        switch ($fn) {
            case 'round':  return round((float)($a[0] ?? 0), (int)($a[1] ?? 0));
            case 'abs':    return abs((float)($a[0] ?? 0));
            case 'min':    return $a ? min($a) : 0;
            case 'max':    return $a ? max($a) : 0;
            case 'if':     return !empty($a[0]) ? ($a[1] ?? '') : ($a[2] ?? '');
            case 'concat': return implode('', array_map('strval', $a));
            case 'len':    return mb_strlen((string)($a[0] ?? ''));
            case 'upper':  return mb_strtoupper((string)($a[0] ?? ''));
            case 'lower':  return mb_strtolower((string)($a[0] ?? ''));
            case 'floor':  return floor((float)($a[0] ?? 0));
            case 'ceil':   return ceil((float)($a[0] ?? 0));
        }
        throw new RuntimeException("unknown function $fn()");
    }
}

/** Resolve every computed property for a set of rows (in place). */
function apply_computed(array $props, array &$rows): void
{
    foreach ($props as $p) {
        if (!in_array($p['type'], ['rollup', 'formula'], true)) continue;
        foreach ($rows as &$r) {
            $r['values'][$p['key']] = $p['type'] === 'rollup'
                ? compute_rollup($p, $r)
                : compute_formula($p, $r, $props);
        }
        unset($r);
    }
}

/* ------------------------------------------------------------ saved views -- */

function db_views(int $databaseId): array
{
    $st = db()->prepare("SELECT * FROM db_views WHERE database_id = ? ORDER BY position, id");
    $st->execute([$databaseId]);
    $views = $st->fetchAll();
    if (!$views) {
        // Every database has at least one view.
        db()->prepare("INSERT INTO db_views (database_id, name, type) VALUES (?, 'Table', 'table')")
            ->execute([$databaseId]);
        $st->execute([$databaseId]);
        $views = $st->fetchAll();
    }
    return $views;
}

function view_types(): array
{
    return ['table' => 'Table', 'board' => 'Board', 'gallery' => 'Gallery',
            'list' => 'List', 'calendar' => 'Calendar'];
}

function hidden_props(array $view): array
{
    $h = json_decode((string)($view['hidden_props'] ?? ''), true);
    return is_array($h) ? $h : [];
}
