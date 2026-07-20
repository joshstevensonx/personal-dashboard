<?php
/** Shared page chrome: header (with nav) and footer. */
require_once __DIR__ . '/lib.php';

function page_header(string $active = ''): void
{
    $nav = [
        'index.php'         => 'Dashboard',
        'inbox.php'         => 'Inbox',
        'subscriptions.php' => 'Subscriptions',
        'dates.php'         => 'Dates',
        'bookmarks.php'     => 'Bookmarks',
        'remote.php'        => 'Remote',
    ];
    $name = e(APP_NAME);
    echo "<!doctype html><html lang='en'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>$name</title><link rel='stylesheet' href='assets/style.css'></head><body>";
    echo "<header class='topbar'><a class='brand' href='index.php'>$name</a><nav>";
    foreach ($nav as $file => $label) {
        $cls = ($file === $active) ? "class='on'" : '';
        echo "<a $cls href='" . e($file) . "'>" . e($label) . "</a>";
    }
    echo "<a class='out' href='logout.php'>Sign out</a></nav></header><main>";

    if ($msg = flash()) {
        echo "<div class='flash'>" . e($msg) . "</div>";
    }
}

function page_footer(): void
{
    echo "</main><footer>" . e(APP_NAME) . " &middot; running on your host</footer>";
    // Tiny helper: copy-to-clipboard buttons.
    echo "<script>
        document.addEventListener('click', function(ev){
            var b = ev.target.closest('[data-copy]');
            if(!b) return;
            navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function(){
                var t = b.textContent; b.textContent='Copied!';
                setTimeout(function(){ b.textContent = t; }, 1200);
            });
        });
    </script>";
    echo "</body></html>";
}
