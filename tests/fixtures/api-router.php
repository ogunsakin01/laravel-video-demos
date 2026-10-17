<?php

// The API of a separate frontend (php -S with this router), for the
// recorder's frontend support: the package's sign-in link, a page's data that
// needs the token, the extra header and the sample database's header, and
// data that fails on the server.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Precognition');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');

$sandbox = str_starts_with($_SERVER['HTTP_X_VIDEO_DEMO_SANDBOX'] ?? '', '5-abcdefghijkl.sqlite.');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);

    return;
}

if (str_starts_with($path, '/video-demo/studio/_recorder/sign-in/')) {
    if (! $sandbox) {
        http_response_code(403);
        echo json_encode(['error' => 'The sample database header was not sent.']);

        return;
    }
    echo json_encode(['cookies' => [], 'localStorage' => ['token' => 't-123'], 'sessionStorage' => [], 'headers' => ['X-Team' => '7']]);

    return;
}

switch ($path) {
    case '/api/me':
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer t-123' || ($_SERVER['HTTP_X_TEAM'] ?? '') !== '7' || ! $sandbox) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthenticated.']);
            break;
        }
        echo json_encode(['name' => 'Sample Ada']);
        break;
    case '/api/jobs':
        $job = json_decode(file_get_contents('php://input'), true) ?: [];
        if (($job['location'] ?? '') === '') {
            http_response_code(422);
            echo json_encode(['message' => 'The location field is required.']);
            break;
        }
        echo json_encode(['title' => $job['title'] ?? '', 'location' => $job['location']]);
        break;
    case '/api/save':
        http_response_code(419);
        echo json_encode(['message' => 'CSRF token mismatch.']);
        break;
    case '/api/reports':
        http_response_code(500);
        echo json_encode(['message' => 'Undefined variable $total']);
        break;
    default:
        http_response_code(404);
        echo json_encode(['message' => 'Not found.']);
}
