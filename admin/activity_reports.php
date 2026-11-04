<?php
require_once __DIR__ . '/../includes/auth.php';

$filters = [];
foreach (['year' => 'year', 'term' => 'term', 'class' => 'class_level', 'activity_id' => 'activity_id', 'date_from' => 'date_from', 'date_to' => 'date_to'] as $source => $target) {
    if (isset($_GET[$source]) && is_scalar($_GET[$source]) && (string) $_GET[$source] !== '') {
        $filters[$target] = (string) $_GET[$source];
    }
}
if (isset($_GET['type']) && in_array((string) $_GET['type'], ['Swimming', 'Trip', 'Other'], true)) {
    $filters['activity_type'] = (string) $_GET['type'];
}
if (isset($_GET['student_id']) && (int) $_GET['student_id'] > 0) {
    $filters['activity_student_id'] = (string) (int) $_GET['student_id'];
}
if (($_GET['view'] ?? '') === 'swimming') { $filters['activity_type'] = 'Swimming'; }
if (($_GET['view'] ?? '') === 'trips') { $filters['activity_type'] = 'Trip'; }
if (($_GET['view'] ?? '') === 'other') { $filters['activity_type'] = 'Other'; }
if (($_GET['view'] ?? '') === 'outstanding') { $filters['paid_status'] = 'Outstanding'; }
if (isset($_GET['status']) && in_array((string) $_GET['status'], ['Paid', 'Unpaid', 'Outstanding'], true)) { $filters['paid_status'] = (string) $_GET['status']; }
if (($_GET['status'] ?? '') === 'Partially Paid') { $filters['paid_status'] = 'Partial'; }

$query = http_build_query(array_filter($filters, static fn($value) => $value !== ''));
redirect('admin/reports.php' . ($query !== '' ? '?' . $query : ''));
