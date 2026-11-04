<?php
require_once __DIR__ . '/../config/app.php';

if (!isset($_SESSION['admin_id'])) {
    $sessionExpired = !empty($_SESSION['_session_expired']);
    unset($_SESSION['_session_expired']);
    flash('error', $sessionExpired ? 'You were signed out after 5 minutes of inactivity.' : 'Please sign in to view the parent portal.');
    redirect('admin/login.php');
}

app_session_record_activity();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require_valid_request();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/fee_helpers.php';
require_once __DIR__ . '/parent_portal_helpers.php';

$parentUserId = (int) $_SESSION['admin_id'];
if (!parent_portal_is_parent_account($pdo, $parentUserId)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!parent_portal_schema_ready($pdo)) {
    http_response_code(503);
    exit('The parent portal is not available yet. Please contact the school administration.');
}

$parentAccount = get_user_by_id($pdo, $parentUserId);
if (!$parentAccount) {
    http_response_code(403);
    exit('Access denied.');
}
$_SESSION['admin_name'] = $parentAccount['name'];
