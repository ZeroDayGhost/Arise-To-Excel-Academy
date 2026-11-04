<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

$type = $_GET['type'] ?? '';
$allowed = ['students', 'fees'];
if (!in_array($type, $allowed, true)) {
    redirect('admin/dashboard.php');
}

// permission checks per export type
if ($type === 'students' && !current_admin_has_permission($pdo, 'students.export')) {
    flash('error', 'You do not have permission to export students.');
    redirect('admin/students.php');
}
if ($type === 'fees' && !current_admin_has_permission($pdo, 'fees.export')) {
    flash('error', 'You do not have permission to export fees.');
    redirect('admin/fees.php');
}
$today = date('Y-m-d');
$currentContext = current_academic_context($pdo);

if ($type === 'students') {
    $filename = "students_export_{$today}.csv";
    $enrollmentStatus = (string) ($_GET['status'] ?? 'Active');
    if ($enrollmentStatus !== 'All' && !in_array($enrollmentStatus, student_enrollment_statuses(), true)) {
        $enrollmentStatus = 'Active';
    }
    $statusSql = $enrollmentStatus === 'All' ? '' : ' WHERE students.enrollment_status = :enrollment_status';
    $currentClassLevelSql = student_class_sql_for_year('students', (string) $currentContext['academic_year']);
    $statement = $pdo->prepare(
        "SELECT
            students.registration_no,
            students.full_name,
            students.enrollment_status,
            students.gender,
            students.parent_name,
            {$currentClassLevelSql} AS class_level,
            students.guardian_phone,
            :display_academic_year AS academic_year,
            :display_term AS term,
            COALESCE(current_balances.required_amount, 0) AS required_amount,
            COALESCE(current_balances.paid_amount, 0) AS paid_amount,
            COALESCE(current_balances.balance, 0) AS balance,
            students.created_at
         FROM students
         LEFT JOIN fee_balances AS current_balances
           ON current_balances.student_id = students.id
          AND current_balances.academic_year = :balance_academic_year
          AND current_balances.term = :balance_term
         {$statusSql}
         ORDER BY students.full_name ASC"
    );
    $studentExportParams = [
        'display_academic_year' => $currentContext['academic_year'],
        'display_term' => $currentContext['term'],
        'balance_academic_year' => $currentContext['academic_year'],
        'balance_term' => $currentContext['term'],
    ];
    if ($enrollmentStatus !== 'All') {
        $studentExportParams['enrollment_status'] = $enrollmentStatus;
    }
    $statement->execute($studentExportParams);
    $rows = $statement->fetchAll();

    $headers = ['Admission No', 'Student Name', 'Enrollment Status', 'Gender', 'Parent Name', 'Class Level', 'Parent Phone', 'Academic Year', 'Term', 'Required Amount', 'Paid Amount', 'Balance', 'Created At'];
} elseif ($type === 'fees') {
    $exportYears = array_values(array_filter(
        array_map('strval', $pdo->query("SELECT DISTINCT year FROM fees WHERE year REGEXP '^[0-9]{4}$' ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN)),
        static fn(string $year): bool => preg_match('/^\d{4}$/', $year) === 1
    ));
    $currentYear = (string) $currentContext['academic_year'];
    if (!in_array($currentYear, $exportYears, true)) {
        $exportYears[] = $currentYear;
    }

    $exportYear = trim((string) ($_GET['year'] ?? $currentYear));
    if (!preg_match('/^\d{4}$/', $exportYear) || !in_array($exportYear, $exportYears, true)) {
        $exportYear = $currentYear;
    }
    $exportTerm = trim((string) ($_GET['term'] ?? $currentContext['term']));
    if ($exportTerm !== 'all' && !in_array($exportTerm, term_options(), true)) {
        $exportTerm = $currentContext['term'];
    }

    $filters = ['fees.year = :export_year'];
    $params = ['export_year' => $exportYear];
    if ($exportTerm !== 'all') {
        $filters[] = 'fees.term = :export_term';
        $params['export_term'] = $exportTerm;
    }
    $termFilePart = $exportTerm === 'all' ? 'all_terms' : strtolower(str_replace(' ', '_', $exportTerm));
    $filename = "fees_export_{$exportYear}_{$termFilePart}_{$today}.csv";
    $paymentClassLevelSql = student_class_sql('students', 'fees.year');
    $statement = $pdo->prepare(
        "SELECT
            fees.receipt_no,
            fees.payment_date,
            students.registration_no,
            students.full_name,
            students.enrollment_status,
            {$paymentClassLevelSql} AS class_level,
            fees.term,
            fees.year,
            fees.mpesa_code,
            fees.mpesa_reference_text,
            fees.payment_method,
            fees.payment_status,
            fees.amount_paid,
            fees.balance_after_payment,
            fees.created_at
         FROM fees
         JOIN students ON students.id = fees.student_id
         WHERE " . implode(' AND ', $filters) . "
         ORDER BY fees.payment_date DESC, fees.id DESC"
    );
    $statement->execute($params);
    $rows = $statement->fetchAll();

    $headers = ['Receipt No', 'Payment Date', 'Admission No', 'Student Name', 'Enrollment Status', 'Class Level', 'Term', 'Year', 'M-PESA Code', 'M-PESA Reference Text', 'Payment Method', 'Status', 'Amount Paid', 'Term Balance', 'Created At'];
} else {
    redirect('admin/dashboard.php');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);

foreach ($rows as $row) {
    fputcsv($output, array_values($row));
}

fclose($output);
exit;
