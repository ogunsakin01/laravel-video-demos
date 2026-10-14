<?php

// A small app for the page check (php -S with this router): one good page,
// one that fails on the server, one that needs signing in and one whose
// JavaScript throws.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/':
    case '/login':
        echo '<!doctype html><title>Fine</title><h1>Welcome</h1>';
        break;
    case '/reports':
        http_response_code(500);
        echo '<!doctype html><title>Server Error</title><div class="exception-message">Undefined variable $total</div>';
        break;
    case '/settings':
        header('Location: /login', true, 302);
        break;
    case '/chart':
        echo '<!doctype html><title>Chart</title><h1>Chart</h1><script>window.drawChart.render()</script>';
        break;
    case '/server-recording':
        http_response_code(500);
        echo '<!doctype html><title>Server Error</title><div class="exception-message">Call to a member function on null</div>';
        break;
    case '/feed.json':
        header('Content-Type: application/json');
        echo '{"items":[]}';
        break;
    default:
        http_response_code(404);
        echo '<!doctype html><title>Not Found</title><h1>404 Not Found</h1>';
}
