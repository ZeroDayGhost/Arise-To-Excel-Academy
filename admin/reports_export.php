<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
require_once __DIR__ . '/../includes/payment_transactions.php';

ensure_kitchen_tables($pdo);
activities_ensure_schema($pdo);

$classLevels = class_level_options();
$terms = term_options();
$enrollmentStatuses = student_enrollment_statuses();
$currentContext = current_academic_context($pdo);
$formats = ['pdf', 'excel', 'xlsx', 'csv', 'print'];
$format = in_array(($_GET['format'] ?? 'excel'), $formats, true) ? $_GET['format'] : 'excel';
$filters = [
    'class_level' => in_array(($_GET['class_level'] ?? ''), $classLevels, true) ? $_GET['class_level'] : '',
    'gender' => in_array(($_GET['gender'] ?? ''), gender_options(), true) ? $_GET['gender'] : '',
    'enrollment_status' => (($_GET['enrollment_status'] ?? 'Active') === 'All' || in_array(($_GET['enrollment_status'] ?? 'Active'), $enrollmentStatuses, true)) ? (string) ($_GET['enrollment_status'] ?? 'Active') : 'Active',
    'year' => preg_match('/^\d{4}$/', ($_GET['year'] ?? '')) ? $_GET['year'] : '',
    'term' => in_array(($_GET['term'] ?? ''), $terms, true) ? $_GET['term'] : '',
    'date_from' => valid_date_value($_GET['date_from'] ?? '') ? $_GET['date_from'] : '',
    'date_to' => valid_date_value($_GET['date_to'] ?? '') ? $_GET['date_to'] : '',
    'paid_status' => in_array(($_GET['paid_status'] ?? ''), array_merge(paid_status_options(), ['Outstanding']), true) ? $_GET['paid_status'] : '',
    'feeding' => in_array(($_GET['feeding'] ?? ''), ['yes', 'no'], true) ? $_GET['feeding'] : '',
    'transport' => in_array(($_GET['transport'] ?? ''), ['yes', 'no'], true) ? $_GET['transport'] : '',
    'include_payments' => ($_GET['include_payments'] ?? '') === '1' ? '1' : '',
    'activity_type' => in_array((string) ($_GET['activity_type'] ?? ''), ['Swimming', 'Trip', 'Other'], true) ? (string) $_GET['activity_type'] : '',
    'activity_id' => (filter_var($_GET['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0) > 0 ? (string) (int) $_GET['activity_id'] : '',
    'activity_student_id' => (filter_var($_GET['activity_student_id'] ?? '', FILTER_VALIDATE_INT) ?: 0) > 0 ? (string) (int) $_GET['activity_student_id'] : '',
];
$canViewActivitiesReport = current_admin_has_permission($pdo, 'reports.export');
if ($filters['year'] === '' && $filters['term'] !== '') {
    $filters['year'] = $currentContext['academic_year'];
}
$activityFilters = [
    'year' => $filters['year'], 'term' => $filters['term'], 'class' => $filters['class_level'],
    'status' => $filters['paid_status'], 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
    'type' => $filters['activity_type'], 'activity_id' => $filters['activity_id'],
    'student_id' => $filters['activity_student_id'],
    'gender' => $filters['gender'], 'enrollment_status' => $filters['enrollment_status'],
];
$activityReportData = $canViewActivitiesReport
    ? activity_report_data($pdo, $activityFilters)
    : ['participants' => [], 'expected' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'by_term' => [], 'by_year' => [], 'by_type' => []];
$activityPaymentRows = $canViewActivitiesReport ? activity_payment_report_rows($pdo, $activityFilters) : [];
$combinedPaymentRows = $canViewActivitiesReport ? payment_transaction_report_rows($pdo, $filters) : [];
function report_export_rows(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function report_export_scalar(PDO $pdo, string $sql, array $params = []): float
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (float) $statement->fetchColumn();
}

function report_export_fee_filter(array $filters): array
{
    $where = ["fees.payment_status = 'Recorded'"];
    $params = [];

    if ($filters['class_level'] !== '') {
        $where[] = student_class_sql('students', 'fees.year') . ' = :fee_class_level';
        $params['fee_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $where[] = 'students.gender = :fee_gender';
        $params['fee_gender'] = $filters['gender'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $where[] = 'students.enrollment_status = :fee_enrollment_status';
        $params['fee_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'fees.year = :fee_year';
        $params['fee_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'fees.term = :fee_term';
        $params['fee_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'fees.payment_date >= :fee_date_from';
        $params['fee_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'fees.payment_date <= :fee_date_to';
        $params['fee_date_to'] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function report_export_feeding_payment_filter(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['class_level'] !== '') {
        $where[] = student_class_sql('students', 'feeding_subscriptions.academic_year') . ' = :feeding_class_level';
        $params['feeding_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $where[] = 'students.gender = :feeding_gender';
        $params['feeding_gender'] = $filters['gender'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $where[] = 'students.enrollment_status = :feeding_enrollment_status';
        $params['feeding_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'feeding_subscriptions.academic_year = :feeding_year';
        $params['feeding_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'feeding_subscriptions.term = :feeding_term';
        $params['feeding_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'feeding_payments.payment_date >= :feeding_date_from';
        $params['feeding_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'feeding_payments.payment_date <= :feeding_date_to';
        $params['feeding_date_to'] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function report_export_transport_payment_filter(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['class_level'] !== '') {
        $where[] = student_class_sql('students', 'transport_accounts.academic_year') . ' = :transport_class_level';
        $params['transport_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $where[] = 'students.gender = :transport_gender';
        $params['transport_gender'] = $filters['gender'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $where[] = '(transport_students.is_outside = 1 OR students.enrollment_status = :transport_enrollment_status)';
        $params['transport_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'transport_accounts.academic_year = :transport_year';
        $params['transport_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'transport_accounts.term = :transport_term';
        $params['transport_term'] = $filters['term'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'transport_payments.payment_date >= :transport_date_from';
        $params['transport_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'transport_payments.payment_date <= :transport_date_to';
        $params['transport_date_to'] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function report_export_dated_expense_filter(array $filters, string $dateColumn, string $prefix): array
{
    $where = [];
    $params = [];

    if ($filters['year'] !== '') {
        $where[] = "YEAR({$dateColumn}) = :{$prefix}_year";
        $params["{$prefix}_year"] = $filters['year'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = "{$dateColumn} >= :{$prefix}_date_from";
        $params["{$prefix}_date_from"] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = "{$dateColumn} <= :{$prefix}_date_to";
        $params["{$prefix}_date_to"] = $filters['date_to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function report_export_daycare_payment_filter(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];
    if ($filters['year'] !== '') {
        $where[] = 'YEAR(dp.payment_date) = :daycare_year';
        $params['daycare_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $calendarStmt = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
        $calendarStmt->execute(['year' => $filters['year'], 'term' => $filters['term']]);
        $calendarRow = $calendarStmt->fetch();
        if ($calendarRow) {
            $where[] = 'DATE(dp.payment_date) BETWEEN :daycare_term_start AND :daycare_term_end';
            $params['daycare_term_start'] = $calendarRow['start_date'];
            $params['daycare_term_end'] = $calendarRow['end_date'];
        } else {
            $where[] = '1 = 0';
        }
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'DATE(dp.payment_date) >= :daycare_date_from';
        $params['daycare_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'DATE(dp.payment_date) <= :daycare_date_to';
        $params['daycare_date_to'] = $filters['date_to'];
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function report_export_payment_history(PDO $pdo, array $filters): array
{
    [$where, $params] = report_export_fee_filter($filters);
    return report_export_rows(
        $pdo,
        "SELECT
            fees.receipt_no,
            students.full_name,
            students.enrollment_status,
            fees.amount_paid,
            fees.payment_method,
            fees.mpesa_code,
            fees.payment_date,
            fees.created_at
         FROM fees
         JOIN students ON students.id = fees.student_id{$where}
         ORDER BY fees.payment_date DESC, fees.id DESC",
        $params
    );
}

function report_export_summary(array $rows): array
{
    $studentIds = [];
    $expected = 0.0;
    $paid = 0.0;
    $balance = 0.0;

    foreach ($rows as $row) {
        $studentIds[(int) $row['student_id']] = true;
        $expected += (float) $row['required_amount'];
        $paid += (float) $row['paid_amount'];
        $balance += (float) $row['balance'];
    }

    return [
        'total_students' => count($studentIds),
        'total_expected' => $expected,
        'total_paid' => $paid,
        'total_balance' => $balance,
        'collection_percentage' => $expected > 0.005 ? round(($paid / $expected) * 100, 2) : 0.0,
    ];
}

function report_export_filter_label(array $filters): string
{
    global $pdo;
    $activityLabel = 'All';
    $studentLabel = 'All';
    if (isset($pdo) && $pdo instanceof PDO && !empty($filters['activity_id'])) {
        $statement = $pdo->prepare('SELECT name FROM activities WHERE id = :id');
        $statement->execute(['id' => (int) $filters['activity_id']]);
        $activityLabel = (string) ($statement->fetchColumn() ?: ('ID ' . (int) $filters['activity_id']));
    }
    if (isset($pdo) && $pdo instanceof PDO && !empty($filters['activity_student_id'])) {
        $statement = $pdo->prepare('SELECT full_name FROM students WHERE id = :id');
        $statement->execute(['id' => (int) $filters['activity_student_id']]);
        $studentLabel = (string) ($statement->fetchColumn() ?: ('ID ' . (int) $filters['activity_student_id']));
    }
    $parts = [
        'Class: ' . ($filters['class_level'] ?: 'All'),
        'Gender: ' . ($filters['gender'] ?: 'All'),
        'Enrollment: ' . ($filters['enrollment_status'] ?? 'Active'),
        'Year: ' . ($filters['year'] ?: 'All'),
        'Term: ' . ($filters['term'] ?: 'All'),
        'Date: ' . ($filters['date_from'] ?: 'Any') . ' to ' . ($filters['date_to'] ?: 'Any'),
        'Status: ' . ($filters['paid_status'] ?: 'All'),
        'Feeding: ' . ($filters['feeding'] ?: 'All'),
        'Transport: ' . ($filters['transport'] ?: 'All'),
        'Activity type: ' . ($filters['activity_type'] ?: 'All'),
        'Activity: ' . $activityLabel,
        'Activity student: ' . $studentLabel,
    ];

    return implode(' | ', $parts);
}

function report_export_redirect_path(array $filters): string
{
    $query = array_filter($filters, fn($value) => $value !== '');
    return 'admin/reports.php' . ($query ? '?' . http_build_query($query) : '');
}

function report_export_extra_sections(PDO $pdo, array $filters, array $feeTermRows, array $paymentHistory): array
{
    global $currentContext, $activityReportData, $activityPaymentRows, $combinedPaymentRows, $canViewActivitiesReport;

    $studentConditions = [];
    $studentParams = [];
    $classReportYear = $filters['year'] !== '' ? $filters['year'] : (string) $currentContext['academic_year'];
    $studentClassSql = student_class_sql_for_year('students', $classReportYear);
    if ($filters['class_level'] !== '') {
        $studentConditions[] = $studentClassSql . ' = :student_class_level';
        $studentParams['student_class_level'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $studentConditions[] = 'gender = :student_gender';
        $studentParams['student_gender'] = $filters['gender'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $studentConditions[] = 'enrollment_status = :student_enrollment_status';
        $studentParams['student_enrollment_status'] = $filters['enrollment_status'];
    }
    $studentWhere = $studentConditions ? ' WHERE ' . implode(' AND ', $studentConditions) : '';

    [$feeWhere, $feeParams] = report_export_fee_filter($filters);
    [$feedingWhere, $feedingParams] = report_export_feeding_payment_filter($filters);
    [$transportWhere, $transportParams] = report_export_transport_payment_filter($filters);
    [$expenseWhere, $expenseParams] = report_export_dated_expense_filter($filters, 'expense_date', 'expense');
    [$fuelWhere, $fuelParams] = report_export_dated_expense_filter($filters, 'fuel_date', 'fuel');

    $totalStudents = (int) report_export_scalar($pdo, "SELECT COUNT(*) FROM students{$studentWhere}", $studentParams);
    $studentsPerClass = report_export_rows(
        $pdo,
        "SELECT {$studentClassSql} AS class_level, COUNT(*) AS student_count
         FROM students
         {$studentWhere}
         GROUP BY {$studentClassSql}
         ORDER BY class_level ASC",
        $studentParams
    );
    $totalFeePaid = report_export_scalar($pdo, "SELECT COALESCE(SUM(fees.amount_paid), 0) FROM fees JOIN students ON students.id = fees.student_id{$feeWhere}", $feeParams);
    [$kitchenExpenseWhere, $kitchenExpenseParams] = kitchen_expense_filter($pdo, $filters);
    $kitchenExpenses = report_export_scalar($pdo, "SELECT COALESCE(SUM(e.total_amount), 0) FROM school_expenses e LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id{$kitchenExpenseWhere}", $kitchenExpenseParams);
    $schoolExpenses = report_export_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses WHERE NOT (category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases', 'Gas Refill') OR category LIKE 'Kitchen %')" . ($expenseWhere ? ' AND ' . ltrim($expenseWhere, ' WHERE ') : ''), $expenseParams);
    $fuelExpenses = report_export_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions{$fuelWhere}", $fuelParams);
    $uniformWhere = '';
    $uniformParams = [];
    if ($filters['date_from'] !== '') {
        $uniformWhere .= ' AND us.payment_date >= :uniform_date_from';
        $uniformParams['uniform_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $uniformWhere .= ' AND us.payment_date <= :uniform_date_to';
        $uniformParams['uniform_date_to'] = $filters['date_to'];
    }
    if ($filters['year'] !== '') {
        $uniformWhere .= ' AND YEAR(us.payment_date) = :uniform_year';
        $uniformParams['uniform_year'] = $filters['year'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $uniformWhere .= ' AND (s.id IS NULL OR s.enrollment_status = :uniform_enrollment_status)';
        $uniformParams['uniform_enrollment_status'] = $filters['enrollment_status'];
    }
    $uniformSalesTotal = report_export_scalar(
        $pdo,
        "SELECT COALESCE(SUM(us.amount_paid), 0) FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id WHERE 1=1{$uniformWhere}",
        $uniformParams
    );
    $feedingPayments = report_export_scalar(
        $pdo,
        "SELECT COALESCE(SUM(feeding_payments.amount_paid), 0)
         FROM feeding_payments
         JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
         JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}",
        $feedingParams
    );
    $transportPayments = report_export_scalar(
        $pdo,
        "SELECT COALESCE(SUM(transport_payments.amount_paid), 0)
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}",
        $transportParams
    );

    $daycarePaymentsAvailable = false;
    $daycareCollected = 0.0;
    $daycarePaymentRows = [];
    if (current_admin_has_permission($pdo, 'daycare.payments.view')) {
        $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'daycare_payments'");
        $tableCheck->execute();
        $daycarePaymentsAvailable = (int) $tableCheck->fetchColumn() > 0;
        if ($daycarePaymentsAvailable) {
            [$daycareWhere, $daycareParams] = report_export_daycare_payment_filter($pdo, $filters);
            $daycareCollected = report_export_scalar(
                $pdo,
                "SELECT COALESCE(SUM(CASE WHEN dp.payment_status = 'Recorded' THEN dp.amount ELSE 0 END), 0) FROM daycare_payments dp{$daycareWhere}",
                $daycareParams
            );
            $daycarePaymentRows = report_export_rows(
                $pdo,
                "SELECT DATE(dp.payment_date) AS payment_date, c.full_name, c.admission_no, dp.receipt_no, dp.category, dp.billing_period, dp.payment_method, dp.payment_reference, dp.amount, dp.payment_status
                 FROM daycare_payments dp JOIN daycare_children c ON c.id = dp.child_id{$daycareWhere}
                 ORDER BY dp.payment_date DESC, dp.id DESC",
                $daycareParams
            );
        }
    }

    $feeBalanceRows = array_values(array_filter($feeTermRows, fn($row) => (float) $row['balance'] > 0.005));
    $totalRequiredFees = array_sum(array_map(fn($row) => (float) $row['required_amount'], $feeTermRows));
    $totalPaidForBalances = array_sum(array_map(fn($row) => (float) $row['paid_amount'], $feeTermRows));
    $totalFeeBalances = array_sum(array_map(fn($row) => (float) $row['balance'], $feeTermRows));
    $activityIncome = activity_income_total($pdo, [
        'year' => $filters['year'], 'term' => $filters['term'], 'class' => $filters['class_level'],
        'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
        'type' => $filters['activity_type'], 'activity_id' => $filters['activity_id'],
        'student_id' => $filters['activity_student_id'], 'gender' => $filters['gender'], 'enrollment_status' => $filters['enrollment_status'],
    'status' => $filters['paid_status'],
    ]);
    $totalIncome = $totalFeePaid + $feedingPayments + $transportPayments + $uniformSalesTotal + $daycareCollected + $activityIncome;
    $totalExpenditure = $kitchenExpenses + $schoolExpenses + $fuelExpenses;
    $netIncome = $totalIncome - $totalExpenditure;

    $outstandingTermBalances = $feeBalanceRows;
    usort($outstandingTermBalances, fn($left, $right) => (float) $right['balance'] <=> (float) $left['balance']);

    $unpaidByTerm = [];
    foreach ($feeBalanceRows as $row) {
        $termKey = $row['academic_year'] . '|' . $row['term'];
        if (!isset($unpaidByTerm[$termKey])) {
            $unpaidByTerm[$termKey] = [
                'academic_year' => $row['academic_year'],
                'term' => $row['term'],
                'total_balance' => 0.0,
            ];
        }
        $unpaidByTerm[$termKey]['total_balance'] += (float) $row['balance'];
    }
    usort($unpaidByTerm, fn($left, $right) => [$right['academic_year'], $left['term']] <=> [$left['academic_year'], $right['term']]);

    $paymentsByTerm = report_export_rows(
        $pdo,
        "SELECT fees.year, fees.term, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
         FROM fees
         JOIN students ON students.id = fees.student_id{$feeWhere}
         GROUP BY fees.year, fees.term
         ORDER BY fees.year DESC, FIELD(fees.term, 'Term 1', 'Term 2', 'Term 3')",
        $feeParams
    );
    $dailyCollections = report_export_rows(
        $pdo,
        "SELECT fees.payment_date AS collection_date, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
         FROM fees
         JOIN students ON students.id = fees.student_id{$feeWhere}
         GROUP BY fees.payment_date
         ORDER BY fees.payment_date DESC
         LIMIT 31",
        $feeParams
    );
    $monthlyCollections = report_export_rows(
        $pdo,
        "SELECT DATE_FORMAT(fees.payment_date, '%Y-%m') AS collection_month, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
         FROM fees
         JOIN students ON students.id = fees.student_id{$feeWhere}
         GROUP BY DATE_FORMAT(fees.payment_date, '%Y-%m')
         ORDER BY collection_month DESC
         LIMIT 24",
        $feeParams
    );
    $schoolExpenseCondition = "NOT (e.category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases', 'Gas Refill') OR e.category LIKE 'Kitchen %')";
    $expenseSummaries = report_export_rows(
        $pdo,
        "SELECT e.category AS category,
                COALESCE(SUM(e.total_amount), 0) AS total_amount
         FROM school_expenses e
         WHERE {$schoolExpenseCondition}" . ($expenseWhere ? ' AND ' . ltrim($expenseWhere, ' WHERE ') : '') . "
         GROUP BY e.category
         ORDER BY total_amount DESC",
        $expenseParams
    );
    $feedingByTerm = report_export_rows(
        $pdo,
        "SELECT feeding_subscriptions.term, COALESCE(SUM(feeding_payments.amount_paid), 0) AS total_paid
         FROM feeding_payments
         JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
         JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}
         GROUP BY feeding_subscriptions.term
         ORDER BY FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3')",
        $feedingParams
    );
    $transportByTerm = report_export_rows(
        $pdo,
        "SELECT transport_accounts.term, COALESCE(SUM(transport_payments.amount_paid), 0) AS total_paid
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}
         GROUP BY transport_accounts.term
         ORDER BY FIELD(transport_accounts.term, 'Term 1', 'Term 2', 'Term 3')",
        $transportParams
    );

    $feedingBalanceWhere = [];
    $feedingBalanceParams = [];
    if ($filters['class_level'] !== '') {
        $feedingBalanceWhere[] = student_class_sql('students', 'feeding_subscriptions.academic_year') . ' = :feeding_balance_class';
        $feedingBalanceParams['feeding_balance_class'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $feedingBalanceWhere[] = 'students.gender = :feeding_balance_gender';
        $feedingBalanceParams['feeding_balance_gender'] = $filters['gender'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $feedingBalanceWhere[] = 'students.enrollment_status = :feeding_balance_enrollment_status';
        $feedingBalanceParams['feeding_balance_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['year'] !== '') {
        $feedingBalanceWhere[] = 'feeding_subscriptions.academic_year = :feeding_balance_year';
        $feedingBalanceParams['feeding_balance_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $feedingBalanceWhere[] = 'feeding_subscriptions.term = :feeding_balance_term';
        $feedingBalanceParams['feeding_balance_term'] = $filters['term'];
    }
    $feedingBalanceSql = "SELECT
            students.registration_no,
            students.full_name,
            " . student_class_sql('students', 'feeding_subscriptions.academic_year') . " AS class_level,
            students.enrollment_status,
            feeding_subscriptions.academic_year,
            feeding_subscriptions.term,
            feeding_subscriptions.feeding_amount AS required_amount,
            COALESCE(SUM(feeding_payments.amount_paid), 0) AS paid_amount,
            feeding_subscriptions.feeding_amount - COALESCE(SUM(feeding_payments.amount_paid), 0) AS balance
         FROM feeding_subscriptions
         JOIN students ON students.id = feeding_subscriptions.student_id
         LEFT JOIN feeding_payments ON feeding_payments.feeding_subscription_id = feeding_subscriptions.id";
    if ($feedingBalanceWhere) {
        $feedingBalanceSql .= ' WHERE ' . implode(' AND ', $feedingBalanceWhere);
    }
    $feedingBalanceSql .= " GROUP BY feeding_subscriptions.id
         HAVING balance > 0
         ORDER BY feeding_subscriptions.academic_year DESC, FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3'), students.full_name ASC";
    $feedingBalanceRows = report_export_rows($pdo, $feedingBalanceSql, $feedingBalanceParams);

    $feedingReportRows = [];
    $feedingPaymentRows = [];
    if ($filters['feeding'] !== 'no') {
        $feedingReportWhere = [];
        $feedingReportParams = [];
        $feedingPaymentJoin = '';
        if ($filters['class_level'] !== '') {
            $feedingReportWhere[] = student_class_sql('students', 'feeding_subscriptions.academic_year') . ' = :feeding_report_class';
            $feedingReportParams['feeding_report_class'] = $filters['class_level'];
        }
        if ($filters['gender'] !== '') {
            $feedingReportWhere[] = 'students.gender = :feeding_report_gender';
            $feedingReportParams['feeding_report_gender'] = $filters['gender'];
        }
        if ($filters['enrollment_status'] !== 'All') {
            $feedingReportWhere[] = 'students.enrollment_status = :feeding_report_enrollment_status';
            $feedingReportParams['feeding_report_enrollment_status'] = $filters['enrollment_status'];
        }
        if ($filters['year'] !== '') {
            $feedingReportWhere[] = 'feeding_subscriptions.academic_year = :feeding_report_year';
            $feedingReportParams['feeding_report_year'] = $filters['year'];
        }
        if ($filters['term'] !== '') {
            $feedingReportWhere[] = 'feeding_subscriptions.term = :feeding_report_term';
            $feedingReportParams['feeding_report_term'] = $filters['term'];
        }
        if ($filters['date_from'] !== '') {
            $feedingPaymentJoin .= ' AND feeding_payments.payment_date >= :feeding_report_date_from';
            $feedingReportParams['feeding_report_date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $feedingPaymentJoin .= ' AND feeding_payments.payment_date <= :feeding_report_date_to';
            $feedingReportParams['feeding_report_date_to'] = $filters['date_to'];
        }

        $feedingReportSql = "SELECT
                feeding_subscriptions.*,
                students.registration_no,
                students.full_name,
                " . student_class_sql('students', 'feeding_subscriptions.academic_year') . " AS class_level,
                students.gender,
                students.parent_name,
                students.guardian_phone,
                feeding_subscriptions.feeding_amount AS required_amount,
                COALESCE(SUM(feeding_payments.amount_paid), 0) AS paid_amount,
                feeding_subscriptions.feeding_amount - COALESCE(SUM(feeding_payments.amount_paid), 0) AS balance
             FROM feeding_subscriptions
             JOIN students ON students.id = feeding_subscriptions.student_id
             LEFT JOIN feeding_payments ON feeding_payments.feeding_subscription_id = feeding_subscriptions.id{$feedingPaymentJoin}";
        if ($feedingReportWhere) {
            $feedingReportSql .= ' WHERE ' . implode(' AND ', $feedingReportWhere);
        }
        $feedingReportSql .= " GROUP BY feeding_subscriptions.id
             ORDER BY feeding_subscriptions.academic_year DESC, FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3'), students.full_name ASC";
        $feedingReportRows = report_export_rows($pdo, $feedingReportSql, $feedingReportParams);

        $feedingPaymentRows = report_export_rows(
            $pdo,
            "SELECT
                feeding_payments.reference_no,
                feeding_payments.amount_paid,
                feeding_payments.payment_date,
                feeding_payments.created_at,
                students.registration_no,
                students.full_name,
                " . student_class_sql('students', 'feeding_subscriptions.academic_year') . " AS class_level,
                feeding_subscriptions.academic_year,
                feeding_subscriptions.term
             FROM feeding_payments
             JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
             JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}
             ORDER BY feeding_payments.payment_date DESC, feeding_payments.id DESC",
            $feedingParams
        );
    }

    $transportAccountRows = [];
    $transportPaymentRows = [];
    if ($filters['transport'] !== 'no') {
        $transportAccountWhere = [];
        $transportAccountParams = [];
        $transportPaymentJoin = '';
        if ($filters['class_level'] !== '') {
            $transportAccountWhere[] = student_class_sql('students', 'transport_accounts.academic_year') . ' = :transport_account_class';
            $transportAccountParams['transport_account_class'] = $filters['class_level'];
        }
        if ($filters['gender'] !== '') {
            $transportAccountWhere[] = 'transport_students.gender = :transport_account_gender';
            $transportAccountParams['transport_account_gender'] = $filters['gender'];
        }
        if ($filters['enrollment_status'] !== 'All') {
            $transportAccountWhere[] = '(transport_students.is_outside = 1 OR students.enrollment_status = :transport_account_enrollment_status)';
            $transportAccountParams['transport_account_enrollment_status'] = $filters['enrollment_status'];
        }
        if ($filters['year'] !== '') {
            $transportAccountWhere[] = 'transport_accounts.academic_year = :transport_account_year';
            $transportAccountParams['transport_account_year'] = $filters['year'];
        }
        if ($filters['term'] !== '') {
            $transportAccountWhere[] = 'transport_accounts.term = :transport_account_term';
            $transportAccountParams['transport_account_term'] = $filters['term'];
        }
        if ($filters['date_from'] !== '') {
            $transportPaymentJoin .= ' AND transport_payments.payment_date >= :transport_account_date_from';
            $transportAccountParams['transport_account_date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $transportPaymentJoin .= ' AND transport_payments.payment_date <= :transport_account_date_to';
            $transportAccountParams['transport_account_date_to'] = $filters['date_to'];
        }

        $transportAccountSql = "SELECT
                transport_accounts.*,
                transport_students.student_name,
                transport_students.gender,
                transport_students.parent_name,
                transport_students.parent_phone,
                transport_students.pickup_location,
                transport_students.is_outside,
                students.registration_no,
                " . student_class_sql('students', 'transport_accounts.academic_year') . " AS class_level,
                COALESCE(SUM(transport_payments.amount_paid), 0) AS paid_amount,
                transport_accounts.amount_due - COALESCE(SUM(transport_payments.amount_paid), 0) AS balance
             FROM transport_accounts
             JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
             LEFT JOIN students ON students.id = transport_students.student_id
             LEFT JOIN transport_payments ON transport_payments.transport_account_id = transport_accounts.id{$transportPaymentJoin}";
        if ($transportAccountWhere) {
            $transportAccountSql .= ' WHERE ' . implode(' AND ', $transportAccountWhere);
        }
        $transportAccountSql .= " GROUP BY transport_accounts.id
             ORDER BY transport_accounts.academic_year DESC, FIELD(transport_accounts.term, 'Term 1', 'Term 2', 'Term 3'), transport_students.student_name ASC";
        $transportAccountRows = report_export_rows($pdo, $transportAccountSql, $transportAccountParams);

        $transportPaymentRows = report_export_rows(
            $pdo,
            "SELECT
                transport_payments.reference_no,
                transport_payments.amount_paid,
                transport_payments.payment_date,
                transport_payments.created_at,
                transport_students.student_name,
                transport_students.pickup_location,
                transport_accounts.academic_year,
                transport_accounts.term
             FROM transport_payments
             JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
             JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
             LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}
             ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC",
            $transportParams
        );
    }

    $kitchenRows = report_export_rows(
        $pdo,
        "SELECT e.item_name,
                CASE WHEN kp.id IS NOT NULL THEN kp.quantity ELSE e.quantity END AS quantity,
                CASE WHEN kp.id IS NOT NULL THEN kp.unit_price ELSE e.amount END AS unit_price,
                e.total_amount,
                e.expense_date AS item_date,
                CASE WHEN kp.category = 'Gas' OR e.category = 'Gas Refill' OR e.description LIKE '%Kitchen category: Gas%' THEN 'Gas'
                     WHEN kp.category = 'Food' OR e.category IN ('Kitchen Purchases', 'WHOLESALE') OR e.description LIKE '%Kitchen category: Food%' THEN 'Food'
                     ELSE 'Other' END AS category,
                CASE WHEN kp.id IS NOT NULL THEN kp.description ELSE e.description END AS notes
         FROM school_expenses e
         LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id{$kitchenExpenseWhere}
         ORDER BY e.expense_date DESC, e.id DESC",
        $kitchenExpenseParams
    );
    $schoolExpenseRows = report_export_rows(
        $pdo,
        "SELECT e.item_name, e.category, e.amount, e.quantity, e.unit, e.total_amount, e.expense_date
         FROM school_expenses e
         WHERE {$schoolExpenseCondition}" . ($expenseWhere ? ' AND ' . ltrim($expenseWhere, ' WHERE ') : '') . "
         ORDER BY e.expense_date DESC, e.id DESC",
        $expenseParams
    );

    $feesByClass = [];
    foreach ($feeTermRows as $row) {
        $classKey = (string) $row['class_level'];
        if (!isset($feesByClass[$classKey])) {
            $feesByClass[$classKey] = [
                'class_level' => $classKey,
                'expected' => 0.0,
                'paid' => 0.0,
                'balance' => 0.0,
            ];
        }
        $feesByClass[$classKey]['expected'] += (float) $row['required_amount'];
        $feesByClass[$classKey]['paid'] += (float) $row['paid_amount'];
        $feesByClass[$classKey]['balance'] += (float) $row['balance'];
    }

    $dashboardMetricRows = [
        ['Total Students', (string) $totalStudents],
        ['Feeding Report Records', (string) count($feedingReportRows)],
        ['Transport Account Records', (string) count($transportAccountRows)],
        ['Term Fee Balances', report_export_money($totalFeeBalances)],
        ['Term Fee Paid', report_export_money($totalFeePaid)],
        ['Transport Payments', report_export_money($transportPayments)],
        ['Activities & Trips Collections', report_export_money($activityIncome)],
        ['Kitchen Expenses', report_export_money($kitchenExpenses)],
        ['Net Income', report_export_money($netIncome)],
        ['Feeding Payments', report_export_money($feedingPayments)],
        ['School Expenses', report_export_money($schoolExpenses)],
        ['Total Income', report_export_money($totalIncome)],
        ['Total Expenditure', report_export_money($totalExpenditure)],
    ];
    if ($daycarePaymentsAvailable) {
        $dashboardMetricRows[] = ['Daycare Payments', report_export_money($daycareCollected)];
    }
    if ($canViewActivitiesReport) {
        $dashboardMetricRows[] = ['Activity Expected (not income)', report_export_money((float) $activityReportData['expected'])];
        $dashboardMetricRows[] = ['Activity Outstanding', report_export_money((float) $activityReportData['outstanding'])];
    }

    $sections = [];
    $sections[] = [
        'title' => 'Dashboard Metrics',
        'headers' => ['Metric', 'Value'],
        'rows' => $dashboardMetricRows,
    ];
    $sections[] = [
        'title' => 'Fee Collection Summary',
        'headers' => ['Category', 'Amount'],
        'rows' => [
            ['Expected Fees', report_export_money($totalRequiredFees)],
            ['Paid Fees', report_export_money($totalPaidForBalances)],
            ['Outstanding Fees', report_export_money($totalFeeBalances)],
            ['Collection Rate', ($totalRequiredFees > 0 ? round(($totalPaidForBalances / $totalRequiredFees) * 100, 2) : 0) . '%'],
        ],
    ];
    $sections[] = [
        'title' => 'Paid vs Unpaid',
        'headers' => ['Category', 'Value'],
        'rows' => [
            ['Paid Amount', report_export_money($totalPaidForBalances)],
            ['Unpaid Balance', report_export_money($totalFeeBalances)],
        ],
    ];
    $sections[] = [
        'title' => 'Fees by Class',
        'headers' => ['Class', 'Expected', 'Paid', 'Balance'],
        'rows' => array_map(fn($row) => [
            $row['class_level'],
            report_export_money($row['expected']),
            report_export_money($row['paid']),
            report_export_money($row['balance']),
        ], array_values($feesByClass)),
    ];
    $sections[] = [
        'title' => 'Fee Payment History',
        'headers' => ['Receipt Number', 'Student Name', 'Enrollment', 'Amount', 'Payment Method', 'Mpesa Code', 'Date'],
        'rows' => array_map(fn($payment) => [
            $payment['receipt_no'],
            $payment['full_name'],
            $payment['enrollment_status'],
            report_export_money((float) $payment['amount_paid']),
            $payment['payment_method'],
            $payment['mpesa_code'],
            $payment['payment_date'],
        ], $paymentHistory),
    ];
    if ($canViewActivitiesReport) {
        $sections[] = [
            'title' => 'Combined Parent Transactions (reference only; not additional income)',
            'headers' => ['Date', 'Combined Receipt', 'Parent / Payer', 'Method', 'M-Pesa Code', 'Allocations', 'Total Received', 'Status'],
            'rows' => array_map(fn($transaction) => [
                $transaction['payment_date'], $transaction['receipt_no'], $transaction['parent_name'] ?: 'Not specified',
                $transaction['payment_method'], $transaction['mpesa_code'] ?: '', $transaction['allocation_summary'],
                report_export_money((float) $transaction['total_amount']), $transaction['status'],
            ], $combinedPaymentRows),
        ];
    }
    if ($daycarePaymentsAvailable) {
        $sections[] = [
            'title' => 'Daycare Payment History',
            'headers' => ['Payment Date', 'Child', 'Admission Number', 'Receipt', 'Category', 'Billing Period', 'Payment Method', 'Payment Reference', 'Amount', 'Status'],
            'rows' => array_map(fn($payment) => [
                $payment['payment_date'],
                $payment['full_name'],
                $payment['admission_no'],
                $payment['receipt_no'],
                $payment['category'],
                $payment['billing_period'],
                $payment['payment_method'],
                $payment['payment_reference'],
                report_export_money((float) $payment['amount']),
                $payment['payment_status'],
            ], $daycarePaymentRows),
        ];
    }
    $sections[] = [
        'title' => 'Feeding Report',
        'headers' => ['Admission No', 'Student Name', 'Gender', 'Parent', 'Phone', 'Class', 'Academic Year', 'Term', 'Required Amount', 'Paid Amount', 'Balance', 'Status', 'Added', 'Updated'],
        'rows' => array_map(fn($row) => [
            $row['registration_no'],
            $row['full_name'],
            $row['gender'],
            $row['parent_name'],
            $row['guardian_phone'] ?: 'N/A',
            $row['class_level'],
            $row['academic_year'],
            $row['term'],
            report_export_money((float) $row['required_amount']),
            report_export_money((float) $row['paid_amount']),
            report_export_money((float) $row['balance']),
            $row['status'],
            $row['created_at'],
            $row['updated_at'],
        ], $feedingReportRows),
    ];
    $sections[] = [
        'title' => 'Feeding Payment History',
        'headers' => ['Reference Number', 'Student Name', 'Class', 'Academic Year', 'Term', 'Amount', 'Payment Date', 'Recorded At'],
        'rows' => array_map(fn($row) => [
            $row['reference_no'] ?: 'N/A',
            $row['full_name'] . ' (' . $row['registration_no'] . ')',
            $row['class_level'],
            $row['academic_year'],
            $row['term'],
            report_export_money((float) $row['amount_paid']),
            $row['payment_date'],
            $row['created_at'],
        ], $feedingPaymentRows),
    ];
    $sections[] = [
        'title' => 'Feeding Balances',
        'headers' => ['Student Name', 'Class', 'Enrollment', 'Academic Year', 'Term', 'Required Amount', 'Paid Amount', 'Balance'],
        'rows' => array_map(fn($row) => [
            $row['full_name'] . ' (' . $row['registration_no'] . ')',
            $row['class_level'],
            $row['enrollment_status'],
            $row['academic_year'],
            $row['term'],
            report_export_money((float) $row['required_amount']),
            report_export_money((float) $row['paid_amount']),
            report_export_money((float) $row['balance']),
        ], $feedingBalanceRows),
    ];
    $sections[] = [
        'title' => 'Transport Accounts',
        'headers' => ['Student Name', 'Admission/Type', 'Gender', 'Parent', 'Phone', 'Route', 'Academic Year', 'Term', 'Due', 'Paid', 'Balance', 'Status', 'Added', 'Updated'],
        'rows' => array_map(fn($row) => [
            $row['student_name'],
            ($row['registration_no'] ?: ($row['is_outside'] ? 'Outside Student' : 'N/A')) . ' / ' . ($row['class_level'] ?: 'N/A'),
            $row['gender'],
            $row['parent_name'],
            $row['parent_phone'] ?: 'N/A',
            $row['pickup_location'],
            $row['academic_year'],
            $row['term'],
            report_export_money((float) $row['amount_due']),
            report_export_money((float) $row['paid_amount']),
            report_export_money((float) $row['balance']),
            $row['status'],
            $row['created_at'],
            $row['updated_at'],
        ], $transportAccountRows),
    ];
    $sections[] = [
        'title' => 'Transport Payment History',
        'headers' => ['Reference Number', 'Student Name', 'Route', 'Academic Year', 'Term', 'Amount', 'Payment Date', 'Recorded At'],
        'rows' => array_map(fn($row) => [
            $row['reference_no'] ?: 'N/A',
            $row['student_name'],
            $row['pickup_location'],
            $row['academic_year'],
            $row['term'],
            report_export_money((float) $row['amount_paid']),
            $row['payment_date'],
            $row['created_at'],
        ], $transportPaymentRows),
    ];
    $balanceSectionRows = fn($rows) => array_map(fn($row) => [
        $row['full_name'] . ' (' . $row['registration_no'] . ')',
        $row['class_level'],
        $row['enrollment_status'],
        $row['academic_year'],
        $row['term'],
        report_export_money((float) $row['required_amount']),
        report_export_money((float) $row['paid_amount']),
        report_export_money((float) $row['balance']),
    ], $rows);
    $sections[] = ['title' => 'Term Fee Balances', 'headers' => ['Student', 'Class', 'Enrollment', 'Academic Year', 'Term', 'Required Amount', 'Paid Amount', 'Balance'], 'rows' => $balanceSectionRows($feeTermRows)];
    $sections[] = ['title' => 'Outstanding Term Balances', 'headers' => ['Student', 'Class', 'Enrollment', 'Academic Year', 'Term', 'Required Amount', 'Paid Amount', 'Balance'], 'rows' => $balanceSectionRows($outstandingTermBalances)];
    $sections[] = [
        'title' => 'Unpaid Balance Totals by Term',
        'headers' => ['Academic Year', 'Term', 'Total Unpaid'],
        'rows' => array_map(fn($row) => [$row['academic_year'], $row['term'], report_export_money((float) $row['total_balance'])], $unpaidByTerm),
    ];
    $sections[] = [
        'title' => 'Fee Payments by Academic Term',
        'headers' => ['Academic Year', 'Term', 'Total Paid'],
        'rows' => array_map(fn($row) => [$row['year'], $row['term'], report_export_money((float) $row['total_paid'])], $paymentsByTerm),
    ];
    $sections[] = [
        'title' => 'Daily Collections',
        'headers' => ['Date', 'Total'],
        'rows' => array_map(fn($row) => [$row['collection_date'], report_export_money((float) $row['total_paid'])], $dailyCollections),
    ];
    $sections[] = [
        'title' => 'Monthly Collections',
        'headers' => ['Month', 'Total'],
        'rows' => array_map(fn($row) => [$row['collection_month'], report_export_money((float) $row['total_paid'])], $monthlyCollections),
    ];
    $expenseRows = [
        ['Kitchen Expenses', report_export_money($kitchenExpenses)],
        ['Fuel Expenses', report_export_money($fuelExpenses)],
    ];
    foreach ($expenseSummaries as $row) {
        $expenseRows[] = [$row['category'], report_export_money((float) $row['total_amount'])];
    }
    $sections[] = ['title' => 'Expense Summaries', 'headers' => ['Category', 'Total'], 'rows' => $expenseRows];
    if ($canViewActivitiesReport) {
        $sections[] = [
            'title' => 'Activities & Trips Collections by Type',
            'headers' => ['Activity Type', 'Expected', 'Collected', 'Outstanding'],
            'rows' => array_map(static fn($row) => [
                $row['activity_type'], report_export_money((float) $row['expected']),
                report_export_money((float) $row['collected']), report_export_money((float) $row['outstanding']),
            ], $activityReportData['by_type']),
        ];
        $sections[] = [
            'title' => 'Activities & Trips Collections by Term',
            'headers' => ['Academic Year', 'Term', 'Expected', 'Collected', 'Outstanding'],
            'rows' => array_map(static fn($row) => [
                $row['academic_year'], $row['term'], report_export_money((float) $row['expected']),
                report_export_money((float) $row['collected']), report_export_money((float) $row['outstanding']),
            ], $activityReportData['by_term']),
        ];
        $sections[] = [
            'title' => 'Activities & Trips Collections by Academic Year',
            'headers' => ['Academic Year', 'Expected', 'Collected', 'Outstanding'],
            'rows' => array_map(static fn($year, $row) => [
                (string) $year, report_export_money((float) $row['expected']),
                report_export_money((float) $row['collected']), report_export_money((float) $row['outstanding']),
            ], array_keys($activityReportData['by_year']), array_values($activityReportData['by_year'])),
        ];
        $sections[] = [
            'title' => 'Activities & Trips Student Payments',
            'headers' => ['Activity', 'Type', 'Student', 'Admission No.', 'Class', 'Year', 'Term', 'Expected', 'Paid', 'Balance', 'Status'],
            'rows' => array_map(static fn($row) => [
                $row['activity_name'], $row['activity_type'], $row['student_name'], $row['registration_no'], $row['class_level'],
                $row['academic_year'], $row['term'], report_export_money((float) $row['expected_amount']),
                report_export_money((float) $row['amount_paid']), report_export_money((float) $row['outstanding']), $row['payment_status'],
            ], $activityReportData['participants']),
        ];
        $sections[] = [
            'title' => 'Activities & Trips Payment Transactions',
            'headers' => ['Payment Date', 'Receipt', 'Activity', 'Type', 'Student', 'Admission No.', 'Class', 'Academic Period'],
            'rows' => array_map(static fn($row) => [
                $row['payment_date'], $row['receipt_no'], $row['activity_name'], $row['activity_type'],
                $row['student_name'], $row['registration_no'], $row['class_level'], $row['academic_year'] . ' · ' . $row['term'],
            ], $activityPaymentRows),
        ];
        $sections[] = [
            'title' => 'Activity Payment Methods and Status',
            'headers' => ['Receipt', 'Method', 'Reference', 'Amount', 'Status', 'Reversal / Refund Reason'],
            'rows' => array_map(static fn($row) => [
                $row['receipt_no'], $row['payment_method'], (string) ($row['reference_no'] ?? ''),
                report_export_money((float) $row['amount']), $row['payment_status'], (string) ($row['reversal_reason'] ?? ''),
            ], $activityPaymentRows),
        ];
    }
    $sections[] = [
        'title' => 'Kitchen Expense Records',
        'headers' => ['Item', 'Category', 'Quantity', 'Unit Price', 'Total', 'Date', 'Notes'],
        'rows' => array_map(fn($row) => [
            $row['item_name'],
            $row['category'],
            number_format((float) $row['quantity'], 2),
            report_export_money((float) $row['unit_price']),
            report_export_money((float) $row['total_amount']),
            $row['item_date'],
            preg_replace('/^Kitchen category: [^|]+\\s*\\|\\s*(?:Kitchen entry|Purchase) #\\d+(?:\\s*\\|\\s*Stock tracked: (?:Yes|No))?\\s*\\|?\\s*/', '', (string) $row['notes']),
        ], $kitchenRows),
    ];
    $sections[] = [
        'title' => 'School Expense Records',
        'headers' => ['Item', 'Category', 'Amount', 'Quantity', 'Unit', 'Total', 'Date'],
        'rows' => array_map(fn($row) => [
            $row['item_name'],
            $row['category'],
            report_export_money((float) $row['amount']),
            number_format((float) $row['quantity'], 2),
            $row['unit'] ?? '',
            report_export_money((float) $row['total_amount']),
            $row['expense_date'],
        ], $schoolExpenseRows),
    ];
    $sections[] = [
        'title' => 'Students per Class',
        'headers' => ['Class Level', 'Students'],
        'rows' => array_map(fn($row) => [$row['class_level'], (string) (int) $row['student_count']], $studentsPerClass),
    ];
    $sections[] = [
        'title' => 'Transport Payments',
        'headers' => ['Term', 'Total Paid'],
        'rows' => array_map(fn($row) => [$row['term'], report_export_money((float) $row['total_paid'])], $transportByTerm),
    ];
    $sections[] = [
        'title' => 'Feeding Payments',
        'headers' => ['Term', 'Total Paid'],
        'rows' => array_map(fn($row) => [$row['term'], report_export_money((float) $row['total_paid'])], $feedingByTerm),
    ];
    $sections[] = [
        'title' => 'Income vs Expenditure',
        'headers' => ['Category', 'Amount'],
        'rows' => [
            ['Income', report_export_money($totalIncome)],
            ['Expenditure', report_export_money($totalExpenditure)],
            ['Net Income', report_export_money($netIncome)],
        ],
    ];

    $uniformWhere = '';
    $uniformParams = [];
    if ($filters['date_from'] !== '') {
        $uniformWhere .= ' AND us.payment_date >= :uniform_date_from';
        $uniformParams['uniform_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $uniformWhere .= ' AND us.payment_date <= :uniform_date_to';
        $uniformParams['uniform_date_to'] = $filters['date_to'];
    }
    if ($filters['year'] !== '') {
        $uniformWhere .= ' AND YEAR(us.payment_date) = :uniform_year';
        $uniformParams['uniform_year'] = $filters['year'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $uniformWhere .= ' AND (s.id IS NULL OR s.enrollment_status = :uniform_enrollment_status)';
        $uniformParams['uniform_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['class_level'] !== '') {
        $uniformWhere .= ' AND ' . student_class_sql('s', 'YEAR(us.payment_date)') . ' = :uniform_class_level';
        $uniformParams['uniform_class_level'] = $filters['class_level'];
    }

    $uniformSalesRows = report_export_rows(
        $pdo,
        "SELECT us.receipt_no, s.registration_no, s.full_name, " . student_class_sql('s', 'YEAR(us.payment_date)') . " AS class_level, us.grand_total, us.amount_paid, us.balance, us.payment_method, us.payment_date
         FROM uniform_sales us
         LEFT JOIN students s ON s.id = us.student_id
         WHERE 1=1{$uniformWhere}
         ORDER BY us.payment_date DESC",
        $uniformParams
    );

    $uniformSalesTotal = report_export_scalar(
        $pdo,
        "SELECT COALESCE(SUM(us.amount_paid), 0) FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id WHERE 1=1{$uniformWhere}",
        $uniformParams
    );

    $sections[] = [
        'title' => 'Uniform Sales Report',
        'headers' => ['Receipt No', 'Admission No', 'Student Name', 'Class', 'Grand Total', 'Amount Paid', 'Balance', 'Payment Method', 'Date'],
        'rows' => array_map(fn($row) => [
            $row['receipt_no'],
            $row['registration_no'] ?: 'N/A',
            $row['full_name'] ?: 'N/A',
            $row['class_level'] ?: 'N/A',
            report_export_money((float) $row['grand_total']),
            report_export_money((float) $row['amount_paid']),
            report_export_money((float) $row['balance']),
            $row['payment_method'],
            $row['payment_date'],
        ], $uniformSalesRows),
    ];

    $fuelWhere = '';
    $fuelParams = [];
    if ($filters['date_from'] !== '') {
        $fuelWhere .= ' AND fuel_date >= :fuel_date_from';
        $fuelParams['fuel_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $fuelWhere .= ' AND fuel_date <= :fuel_date_to';
        $fuelParams['fuel_date_to'] = $filters['date_to'];
    }
    if ($filters['year'] !== '') {
        $fuelWhere .= ' AND YEAR(fuel_date) = :fuel_year';
        $fuelParams['fuel_year'] = $filters['year'];
    }

    $fuelTransactionRows = report_export_rows(
        $pdo,
        "SELECT ft.id, v.vehicle_name, ft.fuel_date, ft.total_amount, ft.litres, ft.cost_per_litre, ft.created_at
         FROM fuel_transactions ft
         LEFT JOIN vehicles v ON v.id = ft.vehicle_id
         WHERE 1=1{$fuelWhere}
         ORDER BY ft.fuel_date DESC",
        $fuelParams
    );

    $fuelTotalSpent = report_export_scalar(
        $pdo,
        "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE 1=1{$fuelWhere}",
        $fuelParams
    );

    $fuelByMonth = report_export_rows(
        $pdo,
        "SELECT DATE_FORMAT(fuel_date, '%Y-%m') AS month, COALESCE(SUM(total_amount), 0) AS total_amount
         FROM fuel_transactions
         WHERE 1=1{$fuelWhere}
         GROUP BY DATE_FORMAT(fuel_date, '%Y-%m')
         ORDER BY month DESC",
        $fuelParams
    );

    $sections[] = [
        'title' => 'Fuel Transactions Report',
        'headers' => ['Transaction ID', 'Vehicle', 'Date', 'Amount Spent', 'Litres', 'Cost per Litre', 'Recorded At'],
        'rows' => array_map(fn($row) => [
            $row['id'],
            $row['vehicle_name'] ?: 'N/A',
            $row['fuel_date'],
            report_export_money((float) $row['total_amount']),
            $row['litres'] > 0 ? number_format((float) $row['litres'], 2) : 'N/A',
            $row['cost_per_litre'] > 0 ? report_export_money((float) $row['cost_per_litre']) : 'N/A',
            $row['created_at'],
        ], $fuelTransactionRows),
    ];

    $sections[] = [
        'title' => 'Fuel Spending by Month',
        'headers' => ['Month', 'Total Amount'],
        'rows' => array_map(fn($row) => [
            $row['month'],
            report_export_money((float) $row['total_amount']),
        ], $fuelByMonth),
    ];

    return $sections;
}

function report_export_sections_have_data(array $sections): bool
{
    $summaryOnly = [
        'Dashboard Metrics' => true,
        'Fee Collection Summary' => true,
        'Paid vs Unpaid' => true,
        'Expense Summaries' => true,
        'Income vs Expenditure' => true,
    ];

    foreach ($sections as $section) {
        if (!isset($summaryOnly[$section['title']]) && !empty($section['rows'])) {
            return true;
        }
    }

    return false;
}

function report_export_main_headers(): array
{
    return ['Admission No', 'Student Name', 'Gender', 'Class Level', 'Enrollment', 'Academic Year', 'Term', 'Expected Fees', 'Paid Amount', 'Balance', 'Fee Status', 'Last Payment Date'];
}

function report_export_main_row(array $row): array
{
    return [
        $row['registration_no'],
        $row['full_name'],
        $row['gender'],
        $row['class_level'],
        $row['enrollment_status'],
        $row['academic_year'],
        $row['term'],
        (float) $row['required_amount'],
        (float) $row['paid_amount'],
        (float) $row['balance'],
        strtoupper($row['status']),
        $row['last_payment_date'] ?: '',
    ];
}

function report_export_money(float $amount): string
{
    return 'KES ' . number_format($amount, 2);
}

function xlsx_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function xlsx_cell(int $row, int $col, $value, int $style = 0, bool $numeric = false): string
{
    $ref = xlsx_col($col) . $row;
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';

    if ($numeric && is_numeric($value)) {
        return '<c r="' . $ref . '"' . $styleAttr . '><v>' . (float) $value . '</v></c>';
    }

    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t>' . xlsx_xml((string) $value) . '</t></is></c>';
}

function xlsx_zip_store(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $time = 0;
    $date = 0;

    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $nameLength = strlen($name);
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, $nameLength, 0) . $name;
        $local .= $localHeader . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 32, $offset) . $name;
        $offset += strlen($localHeader) + $size;
    }

    $centralOffset = strlen($local);
    $centralSize = strlen($central);
    $count = count($files);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0);

    return $local . $central . $end;
}

function build_report_xlsx(array $rows, array $summary, array $payments, array $filters, array $extraSections = []): string
{
    $sheetRows = [];
    $sheetRows[] = [['value' => SCHOOL_NAME . ' - Fee Collection Report', 'style' => 1]];
    $sheetRows[] = [['value' => SCHOOL_ADDRESS . ' | ' . SCHOOL_PHONE . ' | ' . SCHOOL_EMAIL, 'style' => 2]];
    $sheetRows[] = [['value' => 'Generated: ' . date('Y-m-d H:i') . ' | Generated By: ' . ($_SESSION['admin_name'] ?? 'Admin'), 'style' => 2]];
    $sheetRows[] = [['value' => report_export_filter_label($filters), 'style' => 2]];
    $sheetRows[] = [];
    $sheetRows[] = [['value' => 'Summary', 'style' => 3], ['value' => 'Value', 'style' => 3]];
    $sheetRows[] = [['value' => 'Total Students'], ['value' => $summary['total_students'], 'numeric' => true]];
    $sheetRows[] = [['value' => 'Total Fees Expected'], ['value' => $summary['total_expected'], 'style' => 6, 'numeric' => true]];
    $sheetRows[] = [['value' => 'Total Paid'], ['value' => $summary['total_paid'], 'style' => 6, 'numeric' => true]];
    $sheetRows[] = [['value' => 'Total Balance'], ['value' => $summary['total_balance'], 'style' => 6, 'numeric' => true]];
    $sheetRows[] = [['value' => 'Collection Percentage'], ['value' => $summary['collection_percentage'] . '%']];
    $sheetRows[] = [];
    $tableHeaderRow = count($sheetRows) + 1;
    $sheetRows[] = array_map(fn($header) => ['value' => $header, 'style' => 5], report_export_main_headers());

    foreach ($rows as $row) {
        $values = report_export_main_row($row);
        $statusStyle = $values[9] === 'PAID' ? 7 : ($values[9] === 'PARTIAL' ? 8 : 9);
        $sheetRows[] = [
            ['value' => $values[0]], ['value' => $values[1]], ['value' => $values[2]], ['value' => $values[3]], ['value' => $values[4]], ['value' => $values[5]],
            ['value' => $values[6], 'style' => 6, 'numeric' => true],
            ['value' => $values[7], 'style' => 6, 'numeric' => true],
            ['value' => $values[8], 'style' => 6, 'numeric' => true],
            ['value' => $values[9], 'style' => $statusStyle],
            ['value' => $values[10]],
        ];
    }

    $lastTableRow = count($sheetRows);
    $sheetRows[] = [
        ['value' => 'Totals', 'style' => 10], [], [], [], [], [],
        ['value' => $summary['total_expected'], 'style' => 10, 'numeric' => true],
        ['value' => $summary['total_paid'], 'style' => 10, 'numeric' => true],
        ['value' => $summary['total_balance'], 'style' => 10, 'numeric' => true],
        ['value' => $summary['collection_percentage'] . '%', 'style' => 10],
    ];

    if ($filters['include_payments'] === '1' && !$extraSections) {
        $sheetRows[] = [];
        $sheetRows[] = [['value' => 'Payment History', 'style' => 3]];
        $sheetRows[] = array_map(fn($header) => ['value' => $header, 'style' => 5], ['Receipt Number', 'Student Name', 'Amount', 'Payment Method', 'Mpesa Code', 'Date']);
        foreach ($payments as $payment) {
            $sheetRows[] = [
                ['value' => $payment['receipt_no']],
                ['value' => $payment['full_name']],
                ['value' => (float) $payment['amount_paid'], 'style' => 6, 'numeric' => true],
                ['value' => $payment['payment_method']],
                ['value' => $payment['mpesa_code']],
                ['value' => $payment['payment_date']],
            ];
        }
    }

    foreach ($extraSections as $section) {
        $sheetRows[] = [];
        $sheetRows[] = [['value' => $section['title'], 'style' => 3]];
        $sheetRows[] = array_map(fn($header) => ['value' => $header, 'style' => 5], $section['headers']);
        if (!$section['rows']) {
            $sheetRows[] = [['value' => 'No records found.']];
            continue;
        }
        foreach ($section['rows'] as $sectionRow) {
            $sheetRows[] = array_map(fn($value) => ['value' => (string) $value], $sectionRow);
        }
    }

    $sheetData = '';
    foreach ($sheetRows as $rowIndex => $cells) {
        $excelRow = $rowIndex + 1;
        $sheetData .= '<row r="' . $excelRow . '">';
        foreach ($cells as $colIndex => $cell) {
            $sheetData .= xlsx_cell($excelRow, $colIndex + 1, $cell['value'] ?? '', (int) ($cell['style'] ?? 0), (bool) ($cell['numeric'] ?? false));
        }
        $sheetData .= '</row>';
    }

    $columns = [16, 28, 12, 16, 14, 10, 16, 16, 16, 12, 18];
    $colsXml = '<cols>';
    foreach ($columns as $index => $width) {
        $colsXml .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $autoFilter = $lastTableRow >= $tableHeaderRow ? '<autoFilter ref="A' . $tableHeaderRow . ':K' . $lastTableRow . '"/>' : '';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . ($tableHeaderRow - 1) . '" topLeftCell="A' . ($tableHeaderRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . $colsXml
        . '<sheetData>' . $sheetData . '</sheetData>'
        . $autoFilter
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;KES&quot; #,##0.00"/></numFmts>
<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><color rgb="FF0F172A"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><color rgb="FF0F172A"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>
<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEDD5"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="11"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFill="1"/><xf numFmtId="164" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/></cellXfs>
</styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Fee Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheetXml,
        'xl/styles.xml' => $stylesXml,
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Fee Collection Report</dc:title><dc:creator>' . xlsx_xml($_SESSION['admin_name'] ?? 'Admin') . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Arise To Excel Fees</Application></Properties>',
    ];

    return xlsx_zip_store($files);
}

function pdf_escape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_text(float $x, float $y, string $text, float $size = 8, string $font = 'F1', string $color = '0 0 0'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . pdf_escape($text) . ") Tj ET\n";
}

function pdf_rect(float $x, float $y, float $w, float $h, string $fill, string $stroke = ''): string
{
    $cmd = "{$fill} rg {$x} {$y} {$w} {$h} re f\n";
    if ($stroke !== '') {
        $cmd .= "{$stroke} RG {$x} {$y} {$w} {$h} re S\n";
    }
    return $cmd;
}

function pdf_document(array $pageStreams, ?array $image): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $imageId = 0;
    $nextId = 5;
    if ($image) {
        $imageId = $nextId++;
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n{$image['data']}\nendstream";
    }

    $kids = [];
    foreach ($pageStreams as $stream) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $kids[] = "{$pageId} 0 R";
        $xObject = $imageId ? " /XObject << /Im1 {$imageId} 0 R >>" : '';
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$xObject} >> /Contents {$contentId} 0 R >>";
        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= max(array_keys($objects)); $id++) {
        $pdf .= isset($offsets[$id]) ? str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT) . " 00000 n \n" : "0000000000 65535 f \n";
    }
    $pdf .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function build_report_pdf(array $rows, array $summary, array $payments, array $filters, array $extraSections = []): string
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    $image = null;
    if (is_file($logoPath) && ($size = @getimagesize($logoPath))) {
        $image = ['data' => file_get_contents($logoPath), 'width' => $size[0], 'height' => $size[1]];
    }

    $columns = [55, 118, 42, 62, 56, 42, 72, 68, 66, 48, 72, 59];
    $headers = report_export_main_headers();
    $pageStreams = [];
    $chunks = array_chunk($rows, 20);
    if (!$chunks) {
        $chunks = [[]];
    }

    foreach ($chunks as $pageIndex => $chunk) {
        $stream = '';
        $stream .= pdf_rect(0, 0, 842, 595, '1 1 1');
        $stream .= pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
        if ($image) {
            $stream .= "q 48 0 0 48 30 535 cm /Im1 Do Q\n";
        }
        $stream .= pdf_text(88, 566, SCHOOL_NAME, 15, 'F2', '0.06 0.09 0.16');
        $stream .= pdf_text(88, 548, SCHOOL_ADDRESS . ' | ' . SCHOOL_PHONE . ' | ' . SCHOOL_EMAIL, 8, 'F1', '0.30 0.36 0.45');
        $stream .= pdf_text(604, 566, 'Fee Collection Report', 13, 'F2', '0.10 0.22 0.57');
        $stream .= pdf_text(604, 548, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.30 0.36 0.45');
        $stream .= pdf_text(604, 534, 'Generated By: ' . ($_SESSION['admin_name'] ?? 'Admin'), 8, 'F1', '0.30 0.36 0.45');
        $stream .= pdf_text(30, 505, report_export_filter_label($filters), 7, 'F1', '0.30 0.36 0.45');

        if ($pageIndex === 0) {
            $summaryCards = [
                ['Total Students', (string) $summary['total_students']],
                ['Expected', report_export_money($summary['total_expected'])],
                ['Paid', report_export_money($summary['total_paid'])],
                ['Balance', report_export_money($summary['total_balance'])],
                ['Collection', $summary['collection_percentage'] . '%'],
            ];
            $x = 30;
            foreach ($summaryCards as $card) {
                $stream .= pdf_rect($x, 456, 150, 38, '0.97 0.98 1', '0.88 0.91 0.95');
                $stream .= pdf_text($x + 10, 479, $card[0], 7, 'F1', '0.39 0.45 0.55');
                $stream .= pdf_text($x + 10, 463, $card[1], 9, 'F2', '0.06 0.09 0.16');
                $x += 156;
            }
            $tableY = 424;
        } else {
            $tableY = 480;
        }

        $x = 30;
        $stream .= pdf_rect(30, $tableY, array_sum($columns), 20, '0.11 0.30 0.85');
        foreach ($headers as $index => $header) {
            $stream .= pdf_text($x + 3, $tableY + 7, $header, 5.8, 'F2', '1 1 1');
            $x += $columns[$index];
        }

        $y = $tableY - 18;
        foreach ($chunk as $rowIndex => $row) {
            $values = report_export_main_row($row);
            $fill = $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1';
            $stream .= pdf_rect(30, $y, array_sum($columns), 18, $fill, '0.88 0.91 0.95');
            $x = 30;
            foreach ($values as $index => $value) {
                $text = is_float($value) ? number_format($value, 2) : (string) $value;
                if ($index === 10) {
                    $color = $text === 'PAID' ? '0.08 0.50 0.21' : ($text === 'PARTIAL' ? '0.76 0.34 0.03' : '0.72 0.11 0.11');
                    $stream .= pdf_text($x + 3, $y + 6, $text, 5.8, 'F2', $color);
                } else {
                    $stream .= pdf_text($x + 3, $y + 6, mb_strimwidth($text, 0, $index === 1 ? 28 : 16, ''), 5.8, 'F1', '0.06 0.09 0.16');
                }
                $x += $columns[$index];
            }
            $y -= 18;
        }
        $stream .= pdf_text(30, 24, SCHOOL_NAME, 7, 'F1', '0.39 0.45 0.55');
        $stream .= pdf_text(760, 24, 'Page ' . ($pageIndex + 1) . ' of ' . count($chunks), 7, 'F1', '0.39 0.45 0.55');
        $pageStreams[] = $stream;
    }

    if ($filters['include_payments'] === '1' && !$extraSections) {
        $paymentChunks = array_chunk($payments, 24);
        foreach ($paymentChunks as $paymentIndex => $chunk) {
            $stream = pdf_rect(0, 0, 842, 595, '1 1 1');
            $stream .= pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
            $stream .= pdf_text(30, 566, SCHOOL_NAME, 14, 'F2', '0.06 0.09 0.16');
            $stream .= pdf_text(30, 548, 'Payment History', 12, 'F2', '0.10 0.22 0.57');
            $headers = ['Receipt Number', 'Student Name', 'Amount', 'Payment Method', 'Mpesa Code', 'Date'];
            $widths = [120, 210, 90, 100, 120, 90];
            $x = 30;
            $y = 480;
            $stream .= pdf_rect(30, $y, array_sum($widths), 20, '0.11 0.30 0.85');
            foreach ($headers as $i => $header) {
                $stream .= pdf_text($x + 4, $y + 7, $header, 7, 'F2', '1 1 1');
                $x += $widths[$i];
            }
            $y -= 19;
            foreach ($chunk as $rowIndex => $payment) {
                $values = [$payment['receipt_no'], $payment['full_name'], number_format((float) $payment['amount_paid'], 2), $payment['payment_method'], $payment['mpesa_code'], $payment['payment_date']];
                $stream .= pdf_rect(30, $y, array_sum($widths), 18, $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1', '0.88 0.91 0.95');
                $x = 30;
                foreach ($values as $i => $value) {
                    $stream .= pdf_text($x + 4, $y + 6, mb_strimwidth((string) $value, 0, 30, ''), 7, 'F1', '0.06 0.09 0.16');
                    $x += $widths[$i];
                }
                $y -= 18;
            }
            $stream .= pdf_text(30, 24, SCHOOL_NAME, 7, 'F1', '0.39 0.45 0.55');
            $stream .= pdf_text(735, 24, 'Payment Page ' . ($paymentIndex + 1), 7, 'F1', '0.39 0.45 0.55');
            $pageStreams[] = $stream;
        }
    }

    foreach ($extraSections as $section) {
        $headers = $section['headers'];
        $sectionRows = $section['rows'] ?: [['No records found.']];
        $chunks = array_chunk($sectionRows, 22);
        $columnCount = max(1, count($headers));
        $widths = array_fill(0, $columnCount, 760 / $columnCount);

        foreach ($chunks as $sectionPage => $chunk) {
            $stream = pdf_rect(0, 0, 842, 595, '1 1 1');
            $stream .= pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
            if ($image) {
                $stream .= "q 48 0 0 48 30 535 cm /Im1 Do Q\n";
            }
            $stream .= pdf_text(88, 566, SCHOOL_NAME, 15, 'F2', '0.06 0.09 0.16');
            $stream .= pdf_text(88, 548, SCHOOL_ADDRESS . ' | ' . SCHOOL_PHONE . ' | ' . SCHOOL_EMAIL, 8, 'F1', '0.30 0.36 0.45');
            $stream .= pdf_text(604, 566, $section['title'], 12, 'F2', '0.10 0.22 0.57');
            $stream .= pdf_text(604, 548, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.30 0.36 0.45');

            $x = 30;
            $y = 485;
            $stream .= pdf_rect(30, $y, 760, 20, '0.11 0.30 0.85');
            foreach ($headers as $index => $header) {
                $stream .= pdf_text($x + 4, $y + 7, mb_strimwidth((string) $header, 0, 24, ''), 6.4, 'F2', '1 1 1');
                $x += $widths[$index] ?? $widths[0];
            }

            $y -= 19;
            foreach ($chunk as $rowIndex => $sectionRow) {
                $stream .= pdf_rect(30, $y, 760, 18, $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1', '0.88 0.91 0.95');
                $x = 30;
                foreach (array_values($sectionRow) as $index => $value) {
                    if ($index >= $columnCount) {
                        break;
                    }
                    $maxChars = max(10, (int) (($widths[$index] ?? $widths[0]) / 4.5));
                    $stream .= pdf_text($x + 4, $y + 6, mb_strimwidth((string) $value, 0, $maxChars, ''), 6.1, 'F1', '0.06 0.09 0.16');
                    $x += $widths[$index] ?? $widths[0];
                }
                $y -= 18;
            }

            $stream .= pdf_text(30, 24, SCHOOL_NAME, 7, 'F1', '0.39 0.45 0.55');
            $stream .= pdf_text(704, 24, $section['title'] . ' ' . ($sectionPage + 1), 7, 'F1', '0.39 0.45 0.55');
            $pageStreams[] = $stream;
        }
    }

    return pdf_document($pageStreams, $image);
}

function output_report_csv(array $rows, array $summary, array $payments, array $filters, array $extraSections = []): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fee_report_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, [SCHOOL_NAME . ' Fee Collection Report']);
    fputcsv($output, ['School Address', SCHOOL_ADDRESS, 'Phone Number', SCHOOL_PHONE, 'Email Address', SCHOOL_EMAIL]);
    fputcsv($output, ['Date Generated', date('Y-m-d H:i'), 'Generated By', $_SESSION['admin_name'] ?? 'Admin']);
    fputcsv($output, ['Filters', report_export_filter_label($filters)]);
    fputcsv($output, []);
    fputcsv($output, ['Summary']);
    fputcsv($output, ['Total Students', 'Total Fees Expected', 'Total Paid', 'Total Balance', 'Collection Percentage']);
    fputcsv($output, [$summary['total_students'], $summary['total_expected'], $summary['total_paid'], $summary['total_balance'], $summary['collection_percentage'] . '%']);
    fputcsv($output, []);
    fputcsv($output, report_export_main_headers());
    foreach ($rows as $row) {
        fputcsv($output, report_export_main_row($row));
    }
    fputcsv($output, ['Totals', '', '', '', '', '', $summary['total_expected'], $summary['total_paid'], $summary['total_balance'], $summary['collection_percentage'] . '%']);

    if ($filters['include_payments'] === '1' && !$extraSections) {
        fputcsv($output, []);
        fputcsv($output, ['Payment History']);
        fputcsv($output, ['Receipt Number', 'Student Name', 'Amount', 'Payment Method', 'Mpesa Code', 'Date']);
        foreach ($payments as $payment) {
            fputcsv($output, [$payment['receipt_no'], $payment['full_name'], $payment['amount_paid'], $payment['payment_method'], $payment['mpesa_code'], $payment['payment_date']]);
        }
    }

    foreach ($extraSections as $section) {
        fputcsv($output, []);
        fputcsv($output, [$section['title']]);
        fputcsv($output, $section['headers']);
        if (!$section['rows']) {
            fputcsv($output, ['No records found.']);
            continue;
        }
        foreach ($section['rows'] as $sectionRow) {
            fputcsv($output, $sectionRow);
        }
    }

    fclose($output);
}

function output_print_report(array $rows, array $summary, array $payments, array $filters, array $extraSections = []): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fee Collection Report | <?= h(SCHOOL_NAME) ?></title>
        <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
        <link rel="stylesheet" href="<?= asset('css/dashboard.css') ?>">
        <style>
            @page { size: A4 landscape; margin: 10mm; }
            body { background: #fff; color: #0f172a; }
            .print-report { padding: 20px; }
            .print-report-header { display: flex; gap: 16px; align-items: center; border-bottom: 2px solid #1179a8; padding-bottom: 14px; margin-bottom: 16px; }
            .print-report-logo { width: 70px; height: 70px; border-radius: 16px; object-fit: cover; }
            .print-summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin: 16px 0; }
            .print-summary div { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; background: #f8fafc; }
            .print-summary span { display: block; color: #64748b; font-size: 11px; }
            .print-summary strong { display: block; font-size: 15px; }
            .print-table { width: 100%; border-collapse: collapse; font-size: 10px; }
            .print-table th { background: #1179a8; color: #fff; }
            .print-table th, .print-table td { border: 1px solid #cbd5e1; padding: 6px; text-align: left; }
            .print-table tr:nth-child(even) td { background: #f8fafc; }
            .status-paid { color: #15803d; font-weight: 800; }
            .status-partial { color: #c2410c; font-weight: 800; }
            .status-unpaid { color: #b91c1c; font-weight: 800; }
            @media print { .no-print { display: none !important; } .print-report { padding: 0; } }
        </style>
    </head>
    <body>
    <main class="print-report">
        <div class="action-row no-print mb-3">
            <button class="btn btn-primary" onclick="window.print()">Print Report</button>
            <a class="btn btn-outline-primary" href="<?= url('admin/reports.php') ?>">Back to Reports</a>
        </div>
        <header class="print-report-header">
            <img class="print-report-logo" src="<?= asset('images/school-logo.jpg') ?>" alt="<?= h(SCHOOL_NAME) ?> logo">
            <div>
                <h1><?= h(SCHOOL_NAME) ?></h1>
                <p><?= h(SCHOOL_ADDRESS) ?> | <?= h(SCHOOL_PHONE) ?> | <?= h(SCHOOL_EMAIL) ?></p>
                <strong>Fee Collection Report</strong><br>
                <span><?= h(report_export_filter_label($filters)) ?></span><br>
                <span>Date Generated: <?= h(date('Y-m-d H:i')) ?> | Generated By: <?= h($_SESSION['admin_name'] ?? 'Admin') ?></span>
            </div>
        </header>
        <section class="print-summary">
            <div><span>Total Students</span><strong><?= h((string) $summary['total_students']) ?></strong></div>
            <div><span>Total Fees Expected</span><strong><?= h(report_export_money($summary['total_expected'])) ?></strong></div>
            <div><span>Total Paid</span><strong><?= h(report_export_money($summary['total_paid'])) ?></strong></div>
            <div><span>Total Balance</span><strong><?= h(report_export_money($summary['total_balance'])) ?></strong></div>
            <div><span>Collection Percentage</span><strong><?= h($summary['collection_percentage'] . '%') ?></strong></div>
        </section>
        <table class="print-table">
            <thead><tr><?php foreach (report_export_main_headers() as $header): ?><th><?= h($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $values = report_export_main_row($row); ?>
                    <tr>
                        <?php foreach ($values as $index => $value): ?>
                            <?php $statusClass = $index === 9 ? 'status-' . strtolower((string) $value) : ''; ?>
                            <td class="<?= h($statusClass) ?>"><?= h(is_float($value) ? report_export_money($value) : (string) $value) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="11">No report records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ($filters['include_payments'] === '1' && !$extraSections): ?>
            <h2>Payment History</h2>
            <table class="print-table">
                <thead><tr><th>Receipt Number</th><th>Student Name</th><th>Amount</th><th>Payment Method</th><th>Mpesa Code</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr><td><?= h($payment['receipt_no']) ?></td><td><?= h($payment['full_name']) ?></td><td><?= h(report_export_money((float) $payment['amount_paid'])) ?></td><td><?= h($payment['payment_method']) ?></td><td><?= h($payment['mpesa_code']) ?></td><td><?= h($payment['payment_date']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?><tr><td colspan="6">No payment history found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php foreach ($extraSections as $section): ?>
            <h2><?= h($section['title']) ?></h2>
            <table class="print-table">
                <thead>
                    <tr>
                        <?php foreach ($section['headers'] as $header): ?>
                            <th><?= h((string) $header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($section['rows'] as $sectionRow): ?>
                        <tr>
                            <?php foreach ($sectionRow as $value): ?>
                                <td><?= h((string) $value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$section['rows']): ?>
                        <tr><td colspan="<?= h((string) max(1, count($section['headers']))) ?>">No records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </main>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    </body>
    </html>
    <?php
}

$reportRows = fetch_fee_term_report($pdo, $filters);
$summary = report_export_summary($reportRows);
$paymentHistory = report_export_payment_history($pdo, $filters);
$extraSections = report_export_extra_sections($pdo, $filters, $reportRows, $paymentHistory);

if (!$reportRows && !report_export_sections_have_data($extraSections)) {
    flash('error', 'No records available for export.');
    redirect(report_export_redirect_path($filters));
}

if ($format === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="fee_report_' . date('Y-m-d') . '.pdf"');
    echo build_report_pdf($reportRows, $summary, $paymentHistory, $filters, $extraSections);
    exit;
}

if ($format === 'csv') {
    output_report_csv($reportRows, $summary, $paymentHistory, $filters, $extraSections);
    exit;
}

if ($format === 'print') {
    output_print_report($reportRows, $summary, $paymentHistory, $filters, $extraSections);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="fee_report_' . date('Y-m-d') . '.xlsx"');
echo build_report_xlsx($reportRows, $summary, $paymentHistory, $filters, $extraSections);
exit;
