<?php
/**
 * Key/value settings store (theme, accent, layout, shortcuts).
 * Values are stored as strings; arrays are JSON-encoded transparently.
 */

function setting_defaults(): array
{
    return [
        // appearance
        'theme'         => 'auto',        // auto | light | dark
        'accent'        => '#2383e2',
        'font'          => 'sans',        // sans | serif | mono
        'font_size'     => 'lg',          // sm | md | lg | xl
        'radius'        => 'soft',        // square | soft | round | pill
        'page_width'    => 'normal',      // narrow | normal | wide | full
        'density'       => 'comfortable', // comfortable | compact
        'sidebar_width' => 'md',          // sm | md | lg
        // behaviour
        'shortcuts'     => '{}',
        'layout'        => '[]',
        'start_page'    => 'index.php',
        'notion_token'  => '',
    ];
}

/** Named starting points for the appearance controls. */
function theme_presets(): array
{
    return [
        'default'  => ['label' => 'Default',  'accent' => '#2383e2', 'font' => 'sans',
                       'radius' => 'soft',   'theme' => 'auto'],
        'mono'     => ['label' => 'Mono',     'accent' => '#5f5e5b', 'font' => 'mono',
                       'radius' => 'square', 'theme' => 'light'],
        'editorial'=> ['label' => 'Editorial','accent' => '#9065b0', 'font' => 'serif',
                       'radius' => 'round',  'theme' => 'light'],
        'midnight' => ['label' => 'Midnight', 'accent' => '#6ea8fe', 'font' => 'sans',
                       'radius' => 'round',  'theme' => 'dark'],
        'forest'   => ['label' => 'Forest',   'accent' => '#0f7b6c', 'font' => 'sans',
                       'radius' => 'soft',   'theme' => 'auto'],
        'ember'    => ['label' => 'Ember',    'accent' => '#d9730d', 'font' => 'sans',
                       'radius' => 'pill',   'theme' => 'dark'],
    ];
}

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT key, value FROM settings") as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $defaults = setting_defaults();
    return $default ?? ($defaults[$key] ?? null);
}

function setting_json(string $key, $fallback = [])
{
    $raw = setting($key);
    if (!is_string($raw) || $raw === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function set_setting(string $key, $value): void
{
    if (is_array($value)) {
        $value = json_encode($value);
    }
    $st = db()->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')"
    );
    $st->execute([$key, (string)$value]);
}

