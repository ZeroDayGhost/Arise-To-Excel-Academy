<?php
require_once __DIR__ . '/../config/app.php';

// Every admin page includes this file to enforce session-based login.
if (!is_admin_logged_in()) {
    $sessionExpired = !empty($_SESSION['_session_expired']);
    unset($_SESSION['_session_expired']);
    flash('error', $sessionExpired ? 'You were signed out after 5 minutes of inactivity.' : 'Please log in to continue.');
    redirect('admin/login.php');
}

app_session_record_activity();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require_valid_request();
}

$retiredAcademicAssessmentPages = [
    'academic_dashboard.php',
    'academic_records.php',
    'academic_analytics.php',
    'subjects.php',
    'exams.php',
    'marks_entry.php',
    'results.php',
    'report_cards.php',
    'class_rankings.php',
    'subject_rankings.php',
    'academic_reports.php',
    'academic_settings.php',
];
if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $retiredAcademicAssessmentPages, true)) {
    redirect('admin/dashboard.php');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/fee_helpers.php';

if (!current_admin_is_active($pdo)) {
    session_destroy();
    flash('error', 'Your account is inactive. Please contact the administrator.');
    redirect('admin/login.php');
}

// Parent accounts share the existing login store but never enter admin routes.
if (in_array('Parent', current_admin_roles($pdo), true)) {
    redirect('parent/dashboard.php');
}

rbac_enforce_page_access($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && academic_view_is_historical($pdo)
    && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['academic_view.php', 'academic_calendar.php', 'academic_settings.php'], true)) {
    flash('error', 'Historical academic periods are read-only. Return to the active period before saving changes.');
    $returnTo = $_SERVER['HTTP_REFERER'] ?? url('admin/dashboard.php');
    $returnHost = parse_url($returnTo, PHP_URL_HOST);
    if ($returnHost !== null && strcasecmp($returnHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
        $returnTo = url('admin/dashboard.php');
    }
    redirect($returnTo);
}
