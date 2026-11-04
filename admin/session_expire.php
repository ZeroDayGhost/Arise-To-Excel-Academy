<?php
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['expired' => false, 'error' => 'POST required.']);
    exit;
}

if (!is_admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['expired' => true]);
    exit;
}

csrf_require_valid_request();
app_destroy_session();
echo json_encode(['expired' => true]);
