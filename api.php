<?php
/**
 * JSON API — used by the shell (settings writes) and available for a future
 * native client. Session-authenticated, same single user as the web UI.
 *
 * GET  api.php?action=ping
 * GET  api.php?action=settings
 * POST api.php?action=set_setting   {"key":"theme","value":"dark"}
 * GET  api.php?action=summary       counts for the dashboard widgets
 */
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    out(['error' => 'unauthorised'], 401);
}

$action = $_GET['action'] ?? '';
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;
}

switch ($action) {
    case 'ping':
        out(['ok' => true, 'app' => APP_NAME, 'time' => date('c')]);

    case 'settings':
        $keys = array_keys(setting_defaults());
        $vals = [];
        foreach ($keys as $k) { $vals[$k] = setting($k); }
        out(['settings' => $vals, 'presets' => array_keys(theme_presets())]);

    case 'set_setting':
        $key = $body['key'] ?? '';
        $val = $body['value'] ?? '';
        if (!array_key_exists($key, setting_defaults())) {
            out(['error' => 'unknown setting'], 400);
        }
        set_setting($key, $val);
        out(['ok' => true, 'key' => $key, 'value' => $val]);

    case 'summary':
        $pdo = db();
        out(['summary' => [
            'inbox_open'    => (int)$pdo->query("SELECT COUNT(*) FROM inbox WHERE done = 0")->fetchColumn(),
            'subscriptions' => (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE active = 1")->fetchColumn(),
            'dates'         => (int)$pdo->query("SELECT COUNT(*) FROM important_dates")->fetchColumn(),
            'bookmarks'     => (int)$pdo->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn(),
            'snippets'      => (int)$pdo->query("SELECT COUNT(*) FROM snippets")->fetchColumn(),
            'devices'       => (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn(),
        ]]);

    default:
        out(['error' => 'unknown action'], 404);
}
