<?php

// A stand-in for the Messages API, run with php -S. Saves each request to
// $FAKE_DIR/request-N.json and answers with $FAKE_DIR/response-N.json.

$dir = getenv('FAKE_DIR');
$n = count(glob("$dir/request-*.json")) + 1;

file_put_contents("$dir/request-$n.json", json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders()),
    'body' => json_decode(file_get_contents('php://input'), true),
], JSON_PRETTY_PRINT));

header('Content-Type: application/json');

if (! file_exists("$dir/response-$n.json")) {
    http_response_code(500);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => "No scripted response $n"]]);

    return;
}

echo file_get_contents("$dir/response-$n.json");
