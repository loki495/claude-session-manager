<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/api/session' && $method === 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['id' => 'ses_stub_create', 'title' => 'Stub session']);
    return;
}

if ($path === '/api/session' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([['id' => 'ses_stub_create'], ['id' => 'ses_stub_other']]);
    return;
}

if ($path === '/session/ses_stub_create' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['id' => 'ses_stub_create', 'model' => ['providerID' => 'stub', 'id' => 'model']]);
    return;
}

if ($path === '/session/ses_stub_create/prompt_async' && $method === 'POST') {
    http_response_code(204);
    return;
}

if ($path === '/session/missing' && $method === 'GET') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing session']);
    return;
}

http_response_code(500);
header('Content-Type: application/json');
echo json_encode(['error' => 'unexpected stub request']);
