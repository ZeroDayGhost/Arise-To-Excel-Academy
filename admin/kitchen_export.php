<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/kitchen_export_helpers.php';

kitchen_require_access($pdo);
$canSeeKitchenRecorder = rbac_user_is_administrator($pdo, (int) ($_SESSION['admin_id'] ?? 0));
ensure_kitchen_tables($pdo);

$format = (string) ($_GET['format'] ?? '');
if (!in_array($format, ['excel', 'pdf'], true)) {
    http_response_code(400);
    exit('Choose Excel or PDF for the export.');
}

$currentContext = current_academic_context($pdo);
$year = preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? ''))
    ? (string) $_GET['year']
    : (string) $currentContext['academic_year'];
$termOptions = term_options();
$term = array_key_exists('term', $_GET) && in_array((string) $_GET['term'], $termOptions, true)
    ? (string) $_GET['term']
    : (array_key_exists('term', $_GET) ? '' : (string) $currentContext['term']);
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? ''))
    ? (string) $_GET['month']
    : '';
$category = in_array((string) ($_GET['category'] ?? ''), ['Food', 'Gas', 'Other'], true)
    ? (string) $_GET['category']
    : '';

$legacyStart = $year . '-01-01';
$legacyEnd = $year . '-12-31';
if ($term !== '') {
    $calendarStmt = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
    $calendarStmt->execute(['year' => $year, 'term' => $term]);
    $termDates = $calendarStmt->fetch(PDO::FETCH_ASSOC);
    if ($termDates) {
        $legacyStart = (string) $termDates['start_date'];
        $legacyEnd = (string) $termDates['end_date'];
    } else {
        $legacyStart = $legacyEnd = '1000-01-01';
    }
}

$categorySql = "CASE
    WHEN kp.id IS NOT NULL AND kp.category = 'Gas' THEN 'Gas'
    WHEN kp.id IS NOT NULL AND kp.category = 'Food' THEN 'Food'
    WHEN kp.id IS NOT NULL THEN 'Other'
    WHEN e.category = 'Gas Refill' OR e.description LIKE '%Kitchen category: Gas |%' THEN 'Gas'
    WHEN e.category IN ('Kitchen Purchases', 'WHOLESALE') OR e.description LIKE '%Kitchen category: Food |%' THEN 'Food'
    ELSE 'Other'
END";
$where = ["(e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill') OR e.category LIKE 'Kitchen %')"];
$params = [];
if ($term !== '') {
    $where[] = '((kp.id IS NOT NULL AND kp.academic_year = :period_year AND kp.term = :period_term) OR (kp.id IS NULL AND e.expense_date BETWEEN :legacy_start AND :legacy_end))';
    $params += ['period_year' => $year, 'period_term' => $term, 'legacy_start' => $legacyStart, 'legacy_end' => $legacyEnd];
} else {
    $where[] = '((kp.id IS NOT NULL AND kp.academic_year = :period_year) OR (kp.id IS NULL AND YEAR(e.expense_date) = :legacy_year))';
    $params += ['period_year' => $year, 'legacy_year' => $year];
}
if ($month !== '') {
    $monthStart = $month . '-01';
    $where[] = 'e.expense_date BETWEEN :month_start AND :month_end';
    $params['month_start'] = $monthStart;
    $params['month_end'] = date('Y-m-t', strtotime($monthStart));
}
if ($category !== '') {
    $where[] = "({$categorySql}) = :category_filter";
    $params['category_filter'] = $category;
}

$recordedBySelect = $canSeeKitchenRecorder ? 'a.name AS recorded_by' : 'NULL AS recorded_by';
$statement = $pdo->prepare(
     "SELECT e.expense_date, e.item_name, kp.category AS kitchen_category,
            CASE WHEN kp.id IS NOT NULL THEN kp.quantity ELSE e.quantity END AS quantity,
            CASE WHEN kp.id IS NOT NULL THEN kp.unit ELSE e.unit END AS unit,
            CASE WHEN kp.id IS NOT NULL THEN kp.unit_price ELSE e.amount END AS unit_price,
            e.total_amount, e.description, {$categorySql} AS report_category, {$recordedBySelect}
     FROM school_expenses e
     LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id
     LEFT JOIN admin a ON a.id = kp.recorded_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY e.expense_date DESC, e.id DESC"
);
$statement->execute($params);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

$totals = ['Food' => 0.0, 'Gas' => 0.0, 'Other' => 0.0, 'Total' => 0.0];
foreach ($rows as $row) {
    $rowCategory = in_array($row['report_category'], ['Food', 'Gas', 'Other'], true) ? $row['report_category'] : 'Other';
    $amount = (float) $row['total_amount'];
    $totals[$rowCategory] += $amount;
    $totals['Total'] += $amount;
}

$monthLabel = $month !== '' ? date('F Y', strtotime($month . '-01')) : 'All months';
$termLabel = $term !== '' ? $term : 'All terms';
$categoryLabel = $category !== '' ? $category : 'All categories';
$periodLabel = $month !== '' ? $monthLabel : ($term !== '' ? $year . ' - ' . $term : 'Academic year ' . $year);
$meta = [
    'school' => defined('SCHOOL_NAME') ? (string) SCHOOL_NAME : 'Arise To Excel Academy',
    'academic_year' => $year,
    'term' => $termLabel,
    'month' => $monthLabel,
    'category' => $categoryLabel,
    'period' => $periodLabel,
    'filters' => "Academic year: {$year} | Term: {$termLabel} | Month: {$monthLabel} | Category: {$categoryLabel}",
    'generated' => date('j M Y, H:i'),
    'show_recorded_by' => $canSeeKitchenRecorder,
];

$filenameTerm = $term === '' ? 'all_terms' : strtolower(str_replace(' ', '_', $term));
$filename = 'kitchen_expenditure_' . $year . '_' . $filenameTerm . '_' . date('Y-m-d');
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo kitchen_export_spreadsheet_xml($rows, $totals, $meta);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
echo kitchen_export_build_pdf($rows, $totals, $meta);
exit;
