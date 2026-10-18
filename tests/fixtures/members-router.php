<?php

// A small app with public pages, a cookie banner and a members' area (php -S
// with this router). /sign-me-in/{id} stands in for the package's sign-in
// link; /dashboard needs it, and /unverified sends to email verification.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$user = $_COOKIE['user'] ?? null;
$banner = isset($_COOKIE['consent']) ? '' : '<div class="cookie-notice" style="position:fixed;bottom:0;left:0;padding:12px;background:#222;color:#fff">'
    .'<p>We use cookies to keep you signed in.</p>'
    .'<button onclick="document.cookie=\'consent=yes; path=/\'; this.parentElement.remove()">Accept all</button> <button>Necessary only</button></div>';

if (str_starts_with($path, '/sign-me-in/')) {
    setcookie('user', basename($path), ['path' => '/']);
    header('Location: /', true, 302);

    return;
}

switch ($path) {
    case '/':
        // An "OK" that isn't a cookie banner's is never clicked for it.
        echo '<!doctype html><title>Home</title><h1>Join us</h1><form onsubmit="return false"><button onclick="document.title=\'Wrong\'">OK</button></form>'.$banner;
        break;
    case '/dashboard':
        if ($user === null) {
            header('Location: /login', true, 302);
            break;
        }
        echo '<!doctype html><title>Dashboard</title><h1>Welcome back, member '.htmlspecialchars($user).'</h1>'.$banner;
        break;
    case '/unverified':
        header('Location: /email/verify', true, 302);
        break;
    case '/login':
    case '/email/verify':
        echo '<!doctype html><title>Sign in</title><h1>Please sign in</h1>';
        break;
    default:
        http_response_code(404);
        echo '<!doctype html><title>Not Found</title><h1>404 Not Found</h1>';
}
