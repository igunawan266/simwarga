<?php
// Router for `php -S` used by ToolsTest: records every request and answers like a small CRM API.
$log = getenv('TOOL_LOG') ?: sys_get_temp_dir() . '/vao-tool-endpoint.jsonl';
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'query' => $_GET,
    'body' => json_decode(file_get_contents('php://input') ?: 'null', true), 'api_key' => $_SERVER['HTTP_X_API_KEY'] ?? null,
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (str_starts_with($_SERVER['REQUEST_URI'], '/orders/')) {
    echo json_encode(['order' => basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), 'status' => 'dikirim', 'eta' => '2 hari']);
} elseif (str_starts_with($_SERVER['REQUEST_URI'], '/fail')) {
    http_response_code(500);
    echo '{"error":"internal"}';
} else {
    echo json_encode(['ok' => true]);
}
