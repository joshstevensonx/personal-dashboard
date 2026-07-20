<?php
/**
 * Key/value settings store (theme, accent, layout, shortcuts).
 * Values are stored as strings; arrays are JSON-encoded transparently.
 */

function setting_defaults(): array
{
    return [
        'theme'      => 'auto',      // auto | dark | light
        'preset'     => 'midnight',  // midnight | slate | nord | paper
        'accent'     => '#6ea8fe',
        'density'    => 'comfortable',
        'shortcuts'  => '{}',        // JSON: { "commandId": "key" }
        'layout'     => '[]',        // JSON: ordered dashboard widget ids
        'start_page' => 'index.php',
        'ui_style'   => 'notion',    // notion | classic
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

/** Built-in theme presets: [name => [bg, panel, panel2, line, text, muted, accent]] */
function theme_presets(): array
{
    return [
        'midnight' => ['label' => 'Midnight', 'dark' => true,
            'vars' => ['bg' => '#0f1115', 'panel' => '#181b22', 'panel2' => '#1f232c',
                       'line' => '#2a2f3a', 'text' => '#e7e9ee', 'muted' => '#9aa3b2']],
        'slate'    => ['label' => 'Slate', 'dark' => true,
            'vars' => ['bg' => '#111418', 'panel' => '#1b1f26', 'panel2' => '#232832',
                       'line' => '#313846', 'text' => '#e3e7ee', 'muted' => '#98a2b3']],
        'nord'     => ['label' => 'Nord', 'dark' => true,
            'vars' => ['bg' => '#2e3440', 'panel' => '#3b4252', 'panel2' => '#434c5e',
                       'line' => '#4c566a', 'text' => '#eceff4', 'muted' => '#b3bccb']],
        'paper'    => ['label' => 'Paper', 'dark' => false,
            'vars' => ['bg' => '#f6f7f9', 'panel' => '#ffffff', 'panel2' => '#f0f2f5',
                       'line' => '#dfe3e8', 'text' => '#1c1f24', 'muted' => '#61697a']],
    ];
}
