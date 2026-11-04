<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/dashboard.php');
}

try {
    if (($_POST['view_period'] ?? '') === 'active' || ($_POST['academic_year'] ?? '') === 'active') {
        clear_academic_view_context();
        flash('success', 'Returned to the active academic period.');
    } else {
        $year = trim((string) ($_POST['academic_year'] ?? ''));
        $term = trim((string) ($_POST['term'] ?? ''));
        set_academic_view_context($pdo, $year, $term);
        flash('success', "Viewing {$year} {$term}. This period is read-only; the active school year has not changed.");
    }
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
}

$returnTo = (string) ($_POST['return_to'] ?? '');
$path = parse_url($returnTo, PHP_URL_PATH) ?: '';
$host = parse_url($returnTo, PHP_URL_HOST);
$allowedPrefix = rtrim(BASE_URL, '/') . '/admin/';
if ($host !== null && strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
    $returnTo = '';
} elseif (!str_starts_with($path, $allowedPrefix) || str_contains($path, '..')) {
    $returnTo = '';
}
redirect($returnTo !== '' ? $returnTo : 'admin/dashboard.php');
