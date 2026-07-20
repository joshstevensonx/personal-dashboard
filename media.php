<?php
/**
 * Serves note attachments that live OUTSIDE the web root.
 *
 * Attachments are stored in <domain>/dashboard-data/uploads so that git deploys
 * can't delete them. That folder isn't reachable over the web, so this script
 * streams the file — and requires a login first, which is a security win too:
 * your attachments are no longer publicly guessable URLs.
 *
 *   media.php?f=<stored filename>
 */
require_once __DIR__ . '/lib.php';
require_login();

$name = (string)($_GET['f'] ?? '');

// Only ever a bare filename — no directory traversal.
$name = basename($name);
if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
    http_response_code(400);
    exit('Bad file reference.');
}

$dir = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/uploads';
$full = $dir . '/' . $name;

// Confirm the resolved path really is inside the upload directory.
$realDir = realpath($dir);
$realFile = realpath($full);
if ($realDir === false || $realFile === false || strpos($realFile, $realDir) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    exit('Not found.');
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$types = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/plain',
    'csv' => 'text/csv',
];
$mime = $types[$ext] ?? 'application/octet-stream';

// SVG and unknown types are forced to download rather than rendered inline,
// since an SVG can carry script.
$inline = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'md', 'csv'], true);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');

readfile($realFile);
