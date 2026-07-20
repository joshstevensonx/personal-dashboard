<?php
/**
 * Shared helpers: sessions, auth, CSRF, escaping, flash messages, date math.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Auth -------------------------------------------------------------------
function is_logged_in(): bool
{
    return !empty($_SESSION['uid']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function attempt_login(string $user, string $pass): bool
{
    if (hash_equals(APP_USERNAME, $user) && password_verify($pass, APP_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['uid'] = 1;
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

// ---- CSRF -------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['csrf'] ?? '';
        if (!hash_equals(csrf_token(), $sent)) {
            http_response_code(400);
            exit('Bad CSRF token. Go back and try again.');
        }
    }
}

// ---- Output / helpers -------------------------------------------------------
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * JSON that is safe to embed inside a <script> block.
 *
 * Plain json_encode() does NOT escape "<", so user content containing
 * "</script>" would close the tag early and execute as HTML — a stored XSS.
 * JSON_HEX_TAG (plus the other HEX flags) encodes those characters as <
 * etc., which JSON.parse and JS string literals still read back correctly.
 */
function json_for_html($data): string
{
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

// ---- Date helpers -----------------------------------------------------------
/** Days from today until $date (YYYY-MM-DD). Negative = past. */
function days_until(string $date): int
{
    $today = new DateTime('today');
    $target = DateTime::createFromFormat('Y-m-d', $date);
    if (!$target) {
        return 0;
    }
    $target->setTime(0, 0, 0);
    return (int)$today->diff($target)->format('%r%a');
}

/** For recurring yearly dates: next occurrence from today. */
function next_occurrence(string $date, bool $recurring): string
{
    if (!$recurring) {
        return $date;
    }
    $today = new DateTime('today');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d) {
        return $date;
    }
    $d->setDate((int)$today->format('Y'), (int)$d->format('m'), (int)$d->format('d'));
    if ($d < $today) {
        $d->modify('+1 year');
    }
    return $d->format('Y-m-d');
}

/** Advance a subscription renewal by its cycle until it is in the future. */
function advance_renewal(string $date, string $cycle): string
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d) {
        return $date;
    }
    $step = ['weekly' => '+1 week', 'monthly' => '+1 month', 'yearly' => '+1 year'][$cycle] ?? '+1 month';
    $today = new DateTime('today');
    $guard = 0;
    while ($d < $today && $guard++ < 500) {
        $d->modify($step);
    }
    return $d->format('Y-m-d');
}

function money(float $amount, string $currency): string
{
    return e($currency) . ' ' . number_format($amount, 2);
}

/**
 * Public URL for a stored attachment.
 * Handles both storage styles: legacy rows saved as "uploads/abc.png" (inside
 * the web root) and new rows saved as a bare filename (outside the web root,
 * streamed by media.php).
 */
function attachment_url(string $stored): string
{
    $name = basename($stored);
    $external = defined('UPLOAD_PATH') && UPLOAD_PATH !== __DIR__ . '/uploads';
    if ($external) {
        return 'media.php?f=' . rawurlencode($name);
    }
    return 'uploads/' . rawurlencode($name);
}

/** Human label for a days-until integer. */
function countdown_label(int $days): string
{
    if ($days === 0) return 'Today';
    if ($days === 1) return 'Tomorrow';
    if ($days === -1) return 'Yesterday';
    if ($days < 0) return abs($days) . ' days ago';
    return "in $days days";
}
