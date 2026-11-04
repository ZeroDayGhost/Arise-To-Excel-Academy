<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
require_once __DIR__ . '/../includes/payment_transactions.php';

// Check permission
if (!current_admin_has_permission($pdo, 'reports.access')) {
    flash('error', 'You do not have permission to access Reports.');
    redirect('admin/dashboard.php');
}

ensure_kitchen_tables($pdo);
activities_ensure_schema($pdo);
$classLevels = class_level_options();
$terms = term_options();
$enrollmentStatuses = student_enrollment_statuses();
$currentContext = current_academic_context($pdo);
$activityTypes = ['Swimming' => 'Swimming', 'Trip' => 'School trips', 'Other' => 'Other activities'];
$activityReportActivities = $pdo->query('SELECT id, name, activity_type, academic_year, term FROM activities ORDER BY activity_date DESC, id DESC')->fetchAll();
$activityReportStudents = $pdo->query('SELECT id, full_name, registration_no FROM students ORDER BY full_name ASC')->fetchAll();

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
    'activity_type' => in_array((string) ($_GET['activity_type'] ?? ''), array_keys($activityTypes), true) ? (string) $_GET['activity_type'] : '',
    'activity_id' => (filter_var($_GET['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0) > 0 ? (string) (int) $_GET['activity_id'] : '',
    'activity_student_id' => (filter_var($_GET['activity_student_id'] ?? '', FILTER_VALIDATE_INT) ?: 0) > 0 ? (string) (int) $_GET['activity_student_id'] : '',
];
$canViewActivitiesReport = current_admin_has_permission($pdo, 'reports.view');

// Do not default report filters to the current term/year so reports
// show all records by default. Users can narrow results via filters.

// If the user chooses a term but leaves year empty, assume the current academic year
// so term filtering still works correctly and required amounts are calculated.
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

$transportFeeStructures = $pdo->query(
    "SELECT * FROM transport_fee_structures ORDER BY location_name ASC"
)->fetchAll();

$reportYear = $filters['year'] !== '' ? $filters['year'] : $currentContext['academic_year'];
$reportTerm = $filters['term'] !== '' ? $filters['term'] : $currentContext['term'];
$transportStudentsByLocation = $pdo->prepare(
    "SELECT
         transport_students.pickup_location,
         COUNT(*) AS student_count,
         COALESCE(SUM(transport_accounts.amount_due), 0) AS total_due
     FROM transport_students
     JOIN transport_accounts ON transport_accounts.transport_student_id = transport_students.id
     WHERE transport_accounts.academic_year = :year
       AND transport_accounts.term = :term
     GROUP BY transport_students.pickup_location
     ORDER BY transport_students.pickup_location ASC"
);
$transportStudentsByLocation->execute([
    'year' => $reportYear,
    'term' => $reportTerm,
]);
$transportStudentsByLocation = $transportStudentsByLocation->fetchAll();

function report_scalar(PDO $pdo, string $sql, array $params = []): float
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (float) $statement->fetchColumn();
}

function report_rows(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function fee_payment_filter(array $filters): array
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

function fee_balance_filter(array $filters, bool $defaultersOnly = false): array
{
    $where = [];
    $params = [];
    $where[] = student_fee_not_sponsored_sql('students', 'fee_balances.academic_year');

    if ($defaultersOnly) {
        $where[] = 'fee_balances.balance > 0';
    }
    if ($filters['class_level'] !== '') {
        $where[] = student_class_sql('students', 'fee_balances.academic_year') . ' = :balance_class_level';
        $params['balance_class_level'] = $filters['class_level'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $where[] = 'students.enrollment_status = :balance_enrollment_status';
        $params['balance_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['year'] !== '') {
        $where[] = 'fee_balances.academic_year = :balance_year';
        $params['balance_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $where[] = 'fee_balances.term = :balance_term';
        $params['balance_term'] = $filters['term'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function feeding_payment_filter(array $filters): array
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

function transport_payment_filter(array $filters): array
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

function dated_expense_filter(PDO $pdo, array $filters, string $dateColumn, string $prefix): array
{
    $where = [];
    $params = [];

    if ($filters['year'] !== '') {
        $where[] = "YEAR({$dateColumn}) = :{$prefix}_year";
        $params["{$prefix}_year"] = $filters['year'];
    }
    if ($filters['term'] !== '' && empty($filters['date_from']) && empty($filters['date_to'])) {
        $calendarStmt = $pdo->prepare(
            "SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term LIMIT 1"
        );
        $calendarStmt->execute(['academic_year' => $filters['year'], 'term' => $filters['term']]);
        $calendarRow = $calendarStmt->fetch();
        if ($calendarRow) {
            $where[] = "{$dateColumn} BETWEEN :{$prefix}_term_start AND :{$prefix}_term_end";
            $params["{$prefix}_term_start"] = $calendarRow['start_date'];
            $params["{$prefix}_term_end"] = $calendarRow['end_date'];
        }
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

function daycare_payment_filter(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['year'] !== '') {
        $where[] = 'YEAR(dp.payment_date) = :daycare_year';
        $params['daycare_year'] = $filters['year'];
    }
    if ($filters['term'] !== '') {
        $calendarStmt = $pdo->prepare(
            'SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term LIMIT 1'
        );
        $calendarStmt->execute(['academic_year' => $filters['year'], 'term' => $filters['term']]);
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
$totalStudents = (int) report_scalar($pdo, "SELECT COUNT(*) FROM students{$studentWhere}", $studentParams);
$studentsPerClass = report_rows(
    $pdo,
    "SELECT {$studentClassSql} AS class_level, COUNT(*) AS student_count
     FROM students
     {$studentWhere}
     GROUP BY {$studentClassSql}
     ORDER BY class_level ASC",
    $studentParams
);

[$feeWhere, $feeParams] = fee_payment_filter($filters);
[$feedingWhere, $feedingParams] = feeding_payment_filter($filters);
[$transportWhere, $transportParams] = transport_payment_filter($filters);
[$expenseWhere, $expenseParams] = dated_expense_filter($pdo, $filters, 'expense_date', 'expense');
[$fuelWhere, $fuelParams] = dated_expense_filter($pdo, $filters, 'fuel_date', 'fuel');

$totalFeePaid = report_scalar($pdo, "SELECT COALESCE(SUM(fees.amount_paid), 0) FROM fees JOIN students ON students.id = fees.student_id{$feeWhere}", $feeParams);
$kitchenExpenseCondition = "(category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases', 'Gas Refill') OR category LIKE 'Kitchen %')";
$schoolExpenseCondition = "NOT (category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases', 'Gas Refill') OR category LIKE 'Kitchen %')";
[$kitchenExpenseWhere, $kitchenExpenseParams] = kitchen_expense_filter($pdo, $filters);
$kitchenExpenses = report_scalar($pdo, "SELECT COALESCE(SUM(e.total_amount), 0) FROM school_expenses e LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id{$kitchenExpenseWhere}", $kitchenExpenseParams);
$schoolExpenses = report_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses WHERE {$schoolExpenseCondition}" . ($expenseWhere ? ' AND ' . ltrim($expenseWhere, ' WHERE ') : ''), $expenseParams);
$fuelExpenses = report_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions{$fuelWhere}", $fuelParams);

$uniformConditions = [];
$uniformParams = [];
$uniformJoin = '';
if ($filters['date_from'] !== '') {
    $uniformConditions[] = 'us.payment_date >= :uniform_date_from';
    $uniformParams['uniform_date_from'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $uniformConditions[] = 'us.payment_date <= :uniform_date_to';
    $uniformParams['uniform_date_to'] = $filters['date_to'];
}
if ($filters['enrollment_status'] !== 'All') {
    $uniformConditions[] = '(s.id IS NULL OR s.enrollment_status = :uniform_enrollment_status)';
    $uniformParams['uniform_enrollment_status'] = $filters['enrollment_status'];
}
if ($filters['class_level'] !== '') {
    $uniformConditions[] = student_class_sql('s', 'YEAR(us.payment_date)') . ' = :uniform_class_level';
    $uniformParams['uniform_class_level'] = $filters['class_level'];
}
// If a term is selected with a year, filter by the term's date range
if ($filters['term'] !== '' && $filters['year'] !== '') {
    $uniformJoin = ' JOIN academic_calendar ac ON YEAR(us.payment_date) = ac.academic_year';
    $uniformConditions[] = 'ac.term_name = :uniform_term';
    $uniformParams['uniform_term'] = $filters['term'];
    $uniformConditions[] = 'us.payment_date >= ac.start_date AND us.payment_date <= ac.end_date';
} elseif ($filters['year'] !== '') {
    // If only year is selected, filter by year
    $uniformConditions[] = 'YEAR(us.payment_date) = :uniform_year';
    $uniformParams['uniform_year'] = $filters['year'];
}
$uniformWhere = $uniformConditions ? ' WHERE ' . implode(' AND ', $uniformConditions) : '';
$uniformTotalPaid = report_scalar($pdo, "SELECT COALESCE(SUM(us.amount_paid), 0) FROM uniform_sales us{$uniformJoin} LEFT JOIN students s ON s.id = us.student_id" . $uniformWhere, $uniformParams);
$feedingPayments = report_scalar(
    $pdo,
    "SELECT COALESCE(SUM(feeding_payments.amount_paid), 0)
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}",
    $feedingParams
);
$transportPayments = report_scalar(
    $pdo,
    "SELECT COALESCE(SUM(transport_payments.amount_paid), 0)
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     LEFT JOIN students ON students.id = transport_students.student_id{$transportWhere}",
    $transportParams
);

$canViewDaycarePayments = current_admin_has_permission($pdo, 'daycare.payments.view');
$daycarePaymentsAvailable = false;
$daycareCollected = 0.0;
$daycarePaymentRows = [];
if ($canViewDaycarePayments) {
    $daycareTableCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name"
    );
    $daycareTableCheck->execute(['table_name' => 'daycare_payments']);
    $daycarePaymentsAvailable = (int) $daycareTableCheck->fetchColumn() > 0;
    if ($daycarePaymentsAvailable) {
        $daycareFilters = $filters;
        if ($daycareFilters['year'] === '' && $daycareFilters['term'] === '') {
            $daycareFilters['year'] = $currentContext['academic_year'];
            $daycareFilters['term'] = $currentContext['term'];
        }
        [$daycareWhere, $daycareParams] = daycare_payment_filter($pdo, $daycareFilters);
        $daycareCollected = report_scalar(
            $pdo,
            "SELECT COALESCE(SUM(CASE WHEN dp.payment_status = 'Recorded' THEN dp.amount ELSE 0 END), 0) FROM daycare_payments dp{$daycareWhere}",
            $daycareParams
        );
        $daycarePaymentRows = report_rows(
            $pdo,
            "SELECT DATE(dp.payment_date) AS payment_date, c.full_name, c.admission_no, dp.receipt_no, dp.category, dp.billing_period, dp.payment_method, dp.payment_reference, dp.amount, dp.payment_status
             FROM daycare_payments dp
             JOIN daycare_children c ON c.id = dp.child_id{$daycareWhere}
             ORDER BY dp.payment_date DESC, dp.id DESC",
            $daycareParams
        );
    }
}

$feeTermReport = fetch_fee_term_report($pdo, $filters);
$feeBalanceRows = array_values(array_filter($feeTermReport, fn($row) => (float) $row['balance'] > 0.005));
$totalRequiredFees = array_sum(array_map(fn($row) => (float) $row['required_amount'], $feeTermReport));
$totalPaidForBalances = array_sum(array_map(fn($row) => (float) $row['paid_amount'], $feeTermReport));
$totalFeeBalances = array_sum(array_map(fn($row) => (float) $row['balance'], $feeTermReport));

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

$activityIncome = activity_income_total($pdo, [
    'year' => $filters['year'], 'term' => $filters['term'], 'class' => $filters['class_level'],
    'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'type' => $filters['activity_type'], 'activity_id' => $filters['activity_id'],
    'student_id' => $filters['activity_student_id'], 'gender' => $filters['gender'], 'enrollment_status' => $filters['enrollment_status'],
    'status' => $filters['paid_status'],
]);
$totalIncome = $totalFeePaid + $feedingPayments + $transportPayments + $uniformTotalPaid + $daycareCollected + $activityIncome;
$totalExpenditure = $kitchenExpenses + $schoolExpenses + $fuelExpenses;
$netIncome = $totalIncome - $totalExpenditure;

// If user did not set explicit report year/term, align net income with dashboard scoping
if ($filters['year'] === '' && $filters['term'] === '') {
    $currentPeriodFilters = $filters;
    $currentPeriodFilters['year'] = $currentContext['academic_year'];
    $currentPeriodFilters['term'] = $currentContext['term'];
    [$currentPeriodFeeWhere, $currentPeriodFeeParams] = fee_payment_filter($currentPeriodFilters);
    $stmtFee = $pdo->prepare(
        "SELECT COALESCE(SUM(fees.amount_paid), 0) FROM fees
         JOIN students ON students.id = fees.student_id{$currentPeriodFeeWhere}"
    );
    $stmtFee->execute($currentPeriodFeeParams);
    $totalFeePaid = (float) $stmtFee->fetchColumn();

    // scope expenses by academic year
    $currentKitchenFilters = $filters;
    $currentKitchenFilters['year'] = $currentContext['academic_year'];
    $currentKitchenFilters['term'] = $currentContext['term'];
    [$currentKitchenWhere, $currentKitchenParams] = kitchen_expense_filter($pdo, $currentKitchenFilters);
    $stmtKitchenExp = $pdo->prepare("SELECT COALESCE(SUM(e.total_amount), 0) FROM school_expenses e LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id{$currentKitchenWhere}");
    $stmtKitchenExp->execute($currentKitchenParams);
    $kitchenExpenses = (float) $stmtKitchenExp->fetchColumn();

    $stmtSchoolExp = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses WHERE NOT (category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases', 'Gas Refill') OR category LIKE 'Kitchen %') AND YEAR(expense_date) = :year");
    $stmtSchoolExp->execute(['year' => $currentContext['academic_year']]);
    $schoolExpenses = (float) $stmtSchoolExp->fetchColumn();

    $stmtFuelExp = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE YEAR(fuel_date) = :year');
    $stmtFuelExp->execute(['year' => $currentContext['academic_year']]);
    $fuelExpenses = (float) $stmtFuelExp->fetchColumn();

    $currentYearFilters = $filters;
    $currentYearFilters['year'] = $currentContext['academic_year'];
    $currentYearFilters['term'] = '';
    [$currentFeedingWhere, $currentFeedingParams] = feeding_payment_filter($currentYearFilters);
    $stmtFeed = $pdo->prepare(
        "SELECT COALESCE(SUM(feeding_payments.amount_paid), 0)
         FROM feeding_payments
         JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
         JOIN students ON students.id = feeding_subscriptions.student_id{$currentFeedingWhere}"
    );
    $stmtFeed->execute($currentFeedingParams);
    $feedingPayments = (float) $stmtFeed->fetchColumn();

    [$currentTransportWhere, $currentTransportParams] = transport_payment_filter($currentYearFilters);
    $stmtTrans = $pdo->prepare(
        "SELECT COALESCE(SUM(transport_payments.amount_paid), 0)
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id{$currentTransportWhere}"
    );
    $stmtTrans->execute($currentTransportParams);
    $transportPayments = (float) $stmtTrans->fetchColumn();

    $activityIncome = activity_income_total($pdo, [
        'year' => $currentContext['academic_year'], 'term' => $currentContext['term'], 'class' => $filters['class_level'],
        'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'type' => $filters['activity_type'], 'activity_id' => $filters['activity_id'],
        'student_id' => $filters['activity_student_id'],
        'status' => $filters['paid_status'],
        'gender' => $filters['gender'], 'enrollment_status' => $filters['enrollment_status'],
    ]);

    $uniformConditions = ['YEAR(us.payment_date) = :uniform_active_year'];
    $uniformParams = ['uniform_active_year' => $currentContext['academic_year']];
    if ($filters['date_from'] !== '') {
        $uniformConditions[] = 'us.payment_date >= :uniform_active_date_from';
        $uniformParams['uniform_active_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $uniformConditions[] = 'us.payment_date <= :uniform_active_date_to';
        $uniformParams['uniform_active_date_to'] = $filters['date_to'];
    }
    if ($filters['class_level'] !== '') {
        $uniformConditions[] = student_class_sql_for_year('s', (string) $currentContext['academic_year']) . ' = :uniform_active_class';
        $uniformParams['uniform_active_class'] = $filters['class_level'];
    }
    if ($filters['gender'] !== '') {
        $uniformConditions[] = 's.gender = :uniform_active_gender';
        $uniformParams['uniform_active_gender'] = $filters['gender'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $uniformConditions[] = '(s.id IS NULL OR s.enrollment_status = :uniform_active_status)';
        $uniformParams['uniform_active_status'] = $filters['enrollment_status'];
    }
    $stmtUni = $pdo->prepare(
        'SELECT COALESCE(SUM(us.amount_paid), 0) FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id WHERE ' . implode(' AND ', $uniformConditions)
    );
    $stmtUni->execute($uniformParams);
    $uniformTotalPaid = (float) $stmtUni->fetchColumn();

    $totalIncome = $totalFeePaid + $uniformTotalPaid + $feedingPayments + $transportPayments + $daycareCollected + $activityIncome;
    $totalExpenditure = $kitchenExpenses + $schoolExpenses + $fuelExpenses;
    $netIncome = $totalIncome - $totalExpenditure;
}

$paymentsByTerm = report_rows(
    $pdo,
    "SELECT fees.year,
            fees.term,
            CONCAT(fees.year, ' ', fees.term) AS term_label,
            COALESCE(SUM(fees.amount_paid), 0) AS total_paid
     FROM fees
     JOIN students ON students.id = fees.student_id{$feeWhere}
     GROUP BY fees.year, fees.term
     ORDER BY fees.year DESC, FIELD(fees.term, 'Term 1', 'Term 2', 'Term 3')",
    $feeParams
);
$dailyCollections = report_rows(
    $pdo,
    "SELECT fees.payment_date AS collection_date, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
     FROM fees
     JOIN students ON students.id = fees.student_id{$feeWhere}
     GROUP BY fees.payment_date
     ORDER BY fees.payment_date DESC
     LIMIT 31",
    $feeParams
);
$monthlyCollections = report_rows(
    $pdo,
    "SELECT DATE_FORMAT(fees.payment_date, '%Y-%m') AS collection_month, COALESCE(SUM(fees.amount_paid), 0) AS total_paid
     FROM fees
     JOIN students ON students.id = fees.student_id{$feeWhere}
     GROUP BY DATE_FORMAT(fees.payment_date, '%Y-%m')
     ORDER BY collection_month DESC
     LIMIT 24",
    $feeParams
);
$expenseSummaries = report_rows(
    $pdo,
    "SELECT e.category AS category,
            COALESCE(SUM(e.total_amount), 0) AS total_amount
     FROM school_expenses e
     WHERE {$schoolExpenseCondition}" . ($expenseWhere ? ' AND ' . ltrim($expenseWhere, ' WHERE ') : '') . "
     GROUP BY e.category
     ORDER BY total_amount DESC",
    $expenseParams
);
$feedingByTerm = report_rows(
    $pdo,
    "SELECT feeding_subscriptions.term, COALESCE(SUM(feeding_payments.amount_paid), 0) AS total_paid
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id{$feedingWhere}
     GROUP BY feeding_subscriptions.term
     ORDER BY FIELD(feeding_subscriptions.term, 'Term 1', 'Term 2', 'Term 3')",
    $feedingParams
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
$feedingBalanceRows = report_rows($pdo, $feedingBalanceSql, $feedingBalanceParams);
$transportByTerm = report_rows(
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
            students.gender,
            students.parent_name,
            students.guardian_phone,
            " . student_class_sql('students', 'feeding_subscriptions.academic_year') . " AS class_level,
            students.enrollment_status,
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
    $feedingReportRows = report_rows($pdo, $feedingReportSql, $feedingReportParams);

    $feedingPaymentRows = report_rows(
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
    $transportAccountRows = report_rows($pdo, $transportAccountSql, $transportAccountParams);

    $transportPaymentRows = report_rows(
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

$kitchenRows = report_rows(
    $pdo,
    "SELECT e.item_name,
            CASE WHEN kp.id IS NOT NULL THEN kp.quantity ELSE e.quantity END AS quantity,
            e.unit,
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

$paymentHistory = [];
if ($filters['include_payments'] === '1') {
    $paymentHistory = report_rows(
        $pdo,
        "SELECT
            fees.receipt_no,
            students.full_name,
            students.enrollment_status,
            fees.amount_paid,
            fees.payment_method,
            fees.mpesa_code,
            fees.payment_date
         FROM fees
         JOIN students ON students.id = fees.student_id{$feeWhere}
         ORDER BY fees.payment_date DESC, fees.id DESC",
        $feeParams
    );
}

$expenseChartLabels = ['Kitchen Expenses', 'Fuel Expenses'];
$expenseChartValues = [$kitchenExpenses, $fuelExpenses];
foreach ($expenseSummaries as $row) {
    $expenseChartLabels[] = $row['category'];
    $expenseChartValues[] = (float) $row['total_amount'];
}

$exportFilters = array_filter($filters, fn($value) => $value !== '');
$excelUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'xlsx'])));
$pdfUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'pdf'])));
$csvUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'csv'])));
$printUrl = url('admin/reports_export.php?' . http_build_query(array_merge($exportFilters, ['format' => 'print'])));
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title no-print">
    <div>
        <p class="eyebrow">School Analytics</p>
        <h1>Reports Dashboard</h1>
    </div>
    <div class="action-row">
        <?php if (current_admin_has_permission($pdo, 'reports.export')): ?>
            <a class="btn btn-outline-primary" href="<?= h(url('admin/bulk_term_receipts.php')) ?>">
                <i class="fa-solid fa-file-circle-check" aria-hidden="true"></i> Generate Term Receipts
            </a>
        <?php endif; ?>
        <div class="dropdown export-dropdown">
            <button class="btn export-button dropdown-toggle" type="button" aria-haspopup="true" aria-expanded="false" data-export-toggle>
                <i class="fa-solid fa-file-export"></i> <span data-export-label>Export</span> <i class="fa-solid fa-chevron-down"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end modern-dropdown export-menu">
                <a class="dropdown-item" href="<?= h($pdfUrl) ?>" target="_blank" rel="noopener" data-export-option><i class="fa-regular fa-file-pdf"></i> Export PDF</a>
                <a class="dropdown-item" href="<?= h($excelUrl) ?>" data-export-option><i class="fa-regular fa-file-excel"></i> Export Excel (.xlsx)</a>
                <a class="dropdown-item" href="<?= h($csvUrl) ?>" data-export-option><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                <a class="dropdown-item" href="<?= h($printUrl) ?>" target="_blank" rel="noopener" data-export-option><i class="fa-solid fa-print"></i> Print report</a>
            </div>
        </div>
    </div>
</div>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-danger no-print"><?= h($message) ?></div>
<?php endif; ?>

<style>
.page-title {
    position: relative;
    z-index: 2500;
}

.export-dropdown {
    position: relative;
    z-index: 3000;
    pointer-events: auto !important;
}

.export-button {
    cursor: pointer;
    pointer-events: auto !important;
}

.export-menu {
    z-index: 3100;
    pointer-events: auto !important;
}

.export-menu.show {
    display: block !important;
}
</style>

<script>
function toggleReportExportMenu(event) {
    event.preventDefault();
    event.stopPropagation();

    var button = event.currentTarget;
    var dropdown = button.closest('.export-dropdown');
    var menu = dropdown ? dropdown.querySelector('.export-menu') : null;

    if (!menu) {
        return false;
    }

    document.querySelectorAll('.export-menu.show').forEach(function (openMenu) {
        if (openMenu !== menu) {
            openMenu.classList.remove('show');
        }
    });

    var isOpen = menu.classList.toggle('show');
    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    return false;
}

document.addEventListener('click', function (event) {
    if (!event.target.closest('.export-dropdown')) {
        document.querySelectorAll('.export-menu.show').forEach(function (menu) {
            menu.classList.remove('show');
        });
        document.querySelectorAll('[data-export-toggle]').forEach(function (button) {
            button.setAttribute('aria-expanded', 'false');
        });
    }
});
</script>

<section class="panel no-print">
    <form class="row g-3" method="get">
        <div class="col-md-2">
            <label class="form-label" for="class_level">Class</label>
            <select class="form-select" id="class_level" name="class_level">
                <option value="">All classes</option>
                <?php foreach ($classLevels as $classLevel): ?>
                    <option value="<?= h($classLevel) ?>" <?= $filters['class_level'] === $classLevel ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-select" id="gender" name="gender">
                <option value="">All genders</option>
                <?php foreach (gender_options() as $gender): ?>
                    <option value="<?= h($gender) ?>" <?= $filters['gender'] === $gender ? 'selected' : '' ?>><?= h($gender) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="enrollment_status">Enrollment</label>
            <select class="form-select" id="enrollment_status" name="enrollment_status">
                <option value="Active" <?= $filters['enrollment_status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                <?php foreach (array_slice($enrollmentStatuses, 1) as $status): ?>
                    <option value="<?= h($status) ?>" <?= $filters['enrollment_status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                <?php endforeach; ?>
                <option value="All" <?= $filters['enrollment_status'] === 'All' ? 'selected' : '' ?>>All</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="year">Year</label>
            <input class="form-control" id="year" name="year" maxlength="4" inputmode="numeric" value="<?= h($filters['year']) ?>" placeholder="<?= h(current_academic_year()) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="term">Term</label>
            <select class="form-select" id="term" name="term">
                <option value="">All terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= h($term) ?>" <?= $filters['term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="activity_type">Activity collection</label>
            <select class="form-select" id="activity_type" name="activity_type">
                <option value="">All activities</option>
                <?php foreach ($activityTypes as $type => $label): ?>
                    <option value="<?= h($type) ?>" <?= $filters['activity_type'] === $type ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="activity_id">Activity</label>
            <select class="form-select" id="activity_id" name="activity_id">
                <option value="">All activities</option>
                <?php foreach ($activityReportActivities as $activity): ?>
                    <option value="<?= (int) $activity['id'] ?>" <?= $filters['activity_id'] === (string) $activity['id'] ? 'selected' : '' ?>><?= h($activity['name'] . ' · ' . $activity['academic_year'] . ' ' . $activity['term']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="activity_student_id">Activity student statement</label>
            <select class="form-select" id="activity_student_id" name="activity_student_id">
                <option value="">All students</option>
                <?php foreach ($activityReportStudents as $student): ?>
                    <option value="<?= (int) $student['id'] ?>" <?= $filters['activity_student_id'] === (string) $student['id'] ? 'selected' : '' ?>><?= h($student['full_name'] . ' · ' . $student['registration_no']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="paid_status">Paid Status</label>
            <select class="form-select" id="paid_status" name="paid_status">
                <option value="">All statuses</option>
                <?php foreach (paid_status_options() as $status): ?>
                    <option value="<?= h($status) ?>" <?= $filters['paid_status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                <?php endforeach; ?>
                <option value="Outstanding" <?= $filters['paid_status'] === 'Outstanding' ? 'selected' : '' ?>>Outstanding (activity)</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_from">From</label>
            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= h($filters['date_from']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_to">To</label>
            <input class="form-control" type="date" id="date_to" name="date_to" value="<?= h($filters['date_to']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="feeding">Feeding</label>
            <select class="form-select" id="feeding" name="feeding">
                <option value="">All students</option>
                <option value="yes" <?= $filters['feeding'] === 'yes' ? 'selected' : '' ?>>Feeding only</option>
                <option value="no" <?= $filters['feeding'] === 'no' ? 'selected' : '' ?>>Non-feeding</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="transport">Transport</label>
            <select class="form-select" id="transport" name="transport">
                <option value="">All students</option>
                <option value="yes" <?= $filters['transport'] === 'yes' ? 'selected' : '' ?>>Transport only</option>
                <option value="no" <?= $filters['transport'] === 'no' ? 'selected' : '' ?>>Non-transport</option>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <label class="modern-check">
                <input type="checkbox" name="include_payments" value="1" <?= $filters['include_payments'] === '1' ? 'checked' : '' ?>>
                <span>Payment history</span>
            </label>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-primary w-100" type="submit">Apply Filters</button>
        </div>
    </form>
</section>

<div class="row g-4 mt-1">
    <div class="col-md-2"><div class="metric-card"><span><?= $totalStudents ?></span><p>Total Students</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($totalFeeBalances) ?></span><p>Term Fee Balances</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($totalFeePaid) ?></span><p>Term Fee Paid</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($transportPayments) ?></span><p>Transport Payments</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($kitchenExpenses) ?></span><p>Kitchen Expenses</p></div></div>
    <div class="col-md-2"><div class="metric-card"><span><?= money($netIncome) ?></span><p>Net Income</p></div></div>
</div>

<?php if ($canViewActivitiesReport): ?>
<section class="panel mt-4">
    <div class="panel-heading">
        <div><h2>Activities &amp; Trips Collections</h2><p class="panel-subtitle">Activity payments and outstanding student amounts, included in this report's Excel, PDF, CSV, and print exports. Only recorded payments count as income.</p></div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-4"><div class="metric-card"><span><?= money((float) $activityReportData['expected']) ?></span><p>Expected</p></div></div>
        <div class="col-sm-6 col-xl-4"><div class="metric-card"><span><?= money((float) $activityReportData['collected']) ?></span><p>Recorded collections</p></div></div>
        <div class="col-sm-6 col-xl-4"><div class="metric-card"><span><?= money((float) $activityReportData['outstanding']) ?></span><p>Outstanding</p></div></div>
    </div>
    <h3 class="h6 mt-3">Collections by activity type</h3>
    <div class="table-responsive mb-4"><table class="table table-striped align-middle mb-0"><thead><tr><th>Type</th><th>Expected</th><th>Collected</th><th>Outstanding</th></tr></thead><tbody>
    <?php foreach ($activityReportData['by_type'] as $typeRow): ?><tr><td><?= h($activityTypes[$typeRow['activity_type']] ?? $typeRow['activity_type']) ?></td><td><?= money((float) $typeRow['expected']) ?></td><td><?= money((float) $typeRow['collected']) ?></td><td><?= money((float) $typeRow['outstanding']) ?></td></tr><?php endforeach; ?>
    <?php if (!$activityReportData['by_type']): ?><tr><td colspan="4" class="text-center text-muted py-3">No activity collections match these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="row g-4 mb-4">
        <div class="col-xl-6"><h3 class="h6">Collections by term</h3><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Academic year</th><th>Term</th><th>Expected</th><th>Collected</th><th>Outstanding</th></tr></thead><tbody>
        <?php foreach ($activityReportData['by_term'] as $termRow): ?><tr><td><?= h($termRow['academic_year']) ?></td><td><?= h($termRow['term']) ?></td><td><?= money((float) $termRow['expected']) ?></td><td><?= money((float) $termRow['collected']) ?></td><td><?= money((float) $termRow['outstanding']) ?></td></tr><?php endforeach; ?>
        <?php if (!$activityReportData['by_term']): ?><tr><td colspan="5" class="text-center text-muted py-3">No term totals found.</td></tr><?php endif; ?>
        </tbody></table></div></div>
        <div class="col-xl-6"><h3 class="h6">Collections by academic year</h3><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Academic year</th><th>Expected</th><th>Collected</th><th>Outstanding</th></tr></thead><tbody>
        <?php foreach ($activityReportData['by_year'] as $year => $yearRow): ?><tr><td><?= h((string) $year) ?></td><td><?= money((float) $yearRow['expected']) ?></td><td><?= money((float) $yearRow['collected']) ?></td><td><?= money((float) $yearRow['outstanding']) ?></td></tr><?php endforeach; ?>
        <?php if (!$activityReportData['by_year']): ?><tr><td colspan="4" class="text-center text-muted py-3">No year totals found.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    </div>
    <h3 class="h6">Student activity balances</h3>
    <div class="table-responsive mb-4"><table class="table table-striped align-middle mb-0"><thead><tr><th>Activity</th><th>Student</th><th>Admission No.</th><th>Class</th><th>Academic period</th><th>Expected</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($activityReportData['participants'] as $activityRow): ?><tr><td><?= h($activityRow['activity_name']) ?></td><td><?= h($activityRow['student_name']) ?></td><td><?= h($activityRow['registration_no']) ?></td><td><?= h($activityRow['class_level']) ?></td><td><?= h($activityRow['academic_year'] . ' · ' . $activityRow['term']) ?></td><td><?= money((float) $activityRow['expected_amount']) ?></td><td><?= money((float) $activityRow['amount_paid']) ?></td><td><?= money((float) $activityRow['outstanding']) ?></td><td><?= h($activityRow['payment_status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$activityReportData['participants']): ?><tr><td colspan="9" class="text-center text-muted py-3">No student activity records match these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <h3 class="h6">Activity payment transactions</h3>
    <div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Payment date</th><th>Receipt</th><th>Activity</th><th>Student</th><th>Admission No.</th><th>Academic period</th><th>Method / reference</th><th>Amount</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($activityPaymentRows as $paymentRow): ?><tr><td><?= h($paymentRow['payment_date']) ?></td><td><?= h($paymentRow['receipt_no']) ?></td><td><?= h($paymentRow['activity_name']) ?><small class="d-block text-muted"><?= h($activityTypes[$paymentRow['activity_type']] ?? $paymentRow['activity_type']) ?></small></td><td><?= h($paymentRow['student_name']) ?></td><td><?= h($paymentRow['registration_no']) ?></td><td><?= h($paymentRow['academic_year'] . ' · ' . $paymentRow['term']) ?></td><td><?= h($paymentRow['payment_method'] . ($paymentRow['reference_no'] ? ' · ' . $paymentRow['reference_no'] : '')) ?></td><td><?= money((float) $paymentRow['amount']) ?></td><td><?= h($paymentRow['payment_status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$activityPaymentRows): ?><tr><td colspan="9" class="text-center text-muted py-3">No activity payment transactions match these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Accountant Fee Report</h2>
            <p class="panel-subtitle">Export-ready student fee status with expected fees, paid amount, balances, and last payment date.</p>
        </div>
        <strong><?= $totalRequiredFees > 0 ? round(($totalPaidForBalances / $totalRequiredFees) * 100, 2) : 0 ?>% collected</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Admission No</th>
                    <th>Student Name</th>
                    <th>Gender</th>
                    <th>Class Level</th>
                    <th>Enrollment</th>
                    <th>Academic Year</th>
                    <th>Term</th>
                    <th>Expected Fees</th>
                    <th>Paid Amount</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Last Payment Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($feeTermReport as $row): ?>
                    <?php $statusClass = 'status-' . strtolower($row['status']); ?>
                    <tr>
                        <td><?= h($row['registration_no']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['gender']) ?></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['enrollment_status']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['required_amount']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                        <td><span class="status-badge <?= h($statusClass) ?>"><?= h(strtoupper($row['status'])) ?></span></td>
                        <td><?= h($row['last_payment_date'] ?: 'N/A') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feeTermReport): ?><tr><td colspan="12">No fee report records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($filters['include_payments'] === '1'): ?>
    <?php if ($canViewActivitiesReport): ?>
    <section class="panel mt-4">
        <div class="panel-heading"><div><h2>Combined Parent Transactions</h2><p class="panel-subtitle mb-0">Each actual payment is shown once here; the allocation details show how it was split. This reference list is not added again to income totals.</p></div></div>
        <div class="table-responsive report-table"><table class="table table-striped align-middle"><thead><tr><th>Date</th><th>Combined receipt</th><th>Parent / payer</th><th>Method / M-Pesa code</th><th>Allocations</th><th>Total received</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($combinedPaymentRows as $transaction): ?><tr><td><?= h($transaction['payment_date']) ?></td><td><a href="<?= h(url('admin/payment_transaction_receipt.php?id=' . (int) $transaction['id'])) ?>" target="_blank" rel="noopener"><?= h($transaction['receipt_no']) ?></a></td><td><?= h($transaction['parent_name'] ?: 'Not specified') ?></td><td><?= h($transaction['payment_method'] . (!empty($transaction['mpesa_code']) ? ' · ' . $transaction['mpesa_code'] : '')) ?></td><td><?= nl2br(h($transaction['allocation_summary'])) ?></td><td><?= money((float) $transaction['total_amount']) ?></td><td><?= h($transaction['status']) ?></td></tr><?php endforeach; ?>
            <?php if (!$combinedPaymentRows): ?><tr><td colspan="7" class="text-center text-muted">No combined transactions match these filters.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>
    <section class="panel mt-4">
        <div class="panel-heading">
            <h2>Payment History</h2>
        </div>
        <div class="table-responsive report-table">
            <table class="table table-striped align-middle">
                <thead><tr><th>Receipt Number</th><th>Student Name</th><th>Enrollment</th><th>Amount</th><th>Payment Method</th><th>Mpesa Code</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($paymentHistory as $payment): ?>
                        <tr>
                            <td><?= h($payment['receipt_no']) ?></td>
                            <td><?= h($payment['full_name']) ?></td>
                            <td><?= h($payment['enrollment_status']) ?></td>
                            <td><?= money((float) $payment['amount_paid']) ?></td>
                            <td><?= h($payment['payment_method']) ?></td>
                            <td><?= h($payment['mpesa_code']) ?></td>
                            <td><?= h($payment['payment_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$paymentHistory): ?><tr><td colspan="7">No payment history found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php
    // Uniform sales table (show recent sales summary using report filters)
    $uniformFilterSql = '';
    $uniformFilterParams = [];
    $uniformFilterJoin = '';
    if ($filters['date_from'] !== '') {
        $uniformFilterSql .= ' WHERE us.payment_date >= :uniform_date_from';
        $uniformFilterParams['uniform_date_from'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $uniformFilterSql .= ($uniformFilterSql === '' ? ' WHERE ' : ' AND ') . 'us.payment_date <= :uniform_date_to';
        $uniformFilterParams['uniform_date_to'] = $filters['date_to'];
    }
    // If a term is selected with a year, filter by the term's date range
    if ($filters['term'] !== '' && $filters['year'] !== '') {
        $uniformFilterJoin = ' JOIN academic_calendar ac ON YEAR(us.payment_date) = ac.academic_year';
        $uniformFilterSql .= ($uniformFilterSql === '' ? ' WHERE ' : ' AND ') . 'ac.term_name = :uniform_term';
        $uniformFilterParams['uniform_term'] = $filters['term'];
        $uniformFilterSql .= ' AND us.payment_date >= ac.start_date AND us.payment_date <= ac.end_date';
    } elseif ($filters['year'] !== '') {
        // If only year is selected, filter by year
        $uniformFilterSql .= ($uniformFilterSql === '' ? ' WHERE ' : ' AND ') . 'YEAR(us.payment_date) = :uniform_year';
        $uniformFilterParams['uniform_year'] = $filters['year'];
    }
    if ($filters['enrollment_status'] !== 'All') {
        $uniformFilterSql .= ($uniformFilterSql === '' ? ' WHERE ' : ' AND ') . '(s.id IS NULL OR s.enrollment_status = :uniform_enrollment_status)';
        $uniformFilterParams['uniform_enrollment_status'] = $filters['enrollment_status'];
    }
    if ($filters['class_level'] !== '') {
        $uniformFilterSql .= ($uniformFilterSql === '' ? ' WHERE ' : ' AND ') . student_class_sql('s', 'YEAR(us.payment_date)') . ' = :uniform_class_level';
        $uniformFilterParams['uniform_class_level'] = $filters['class_level'];
    }

    $uniformStmt = $pdo->prepare(
        "SELECT us.receipt_no, s.registration_no, s.full_name, " . student_class_sql('s', 'YEAR(us.payment_date)') . " AS class_level, us.grand_total, us.amount_paid, us.balance, us.payment_date
         FROM uniform_sales us" . $uniformFilterJoin . "
         LEFT JOIN students s ON s.id = us.student_id" . $uniformFilterSql . " ORDER BY us.payment_date DESC LIMIT 100"
    );
    $uniformStmt->execute($uniformFilterParams);
    $uniformRows = $uniformStmt->fetchAll();
    $uniformTotalPaid = report_scalar(
        $pdo,
        "SELECT COALESCE(SUM(us.amount_paid), 0) FROM uniform_sales us" . $uniformFilterJoin . " LEFT JOIN students s ON s.id = us.student_id" . $uniformFilterSql,
        $uniformFilterParams
    );

    // Monthly fuel summary (show last 24 months or filtered year/date summary)
    $fuelStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(fuel_date, '%Y-%m') AS month, SUM(total_amount) AS total_spent
         FROM fuel_transactions" . $fuelWhere . " GROUP BY month ORDER BY month DESC LIMIT 24"
    );
    $fuelStmt->execute($fuelParams);
    $fuelRows = $fuelStmt->fetchAll();
    $fuelTotalSpent = report_scalar($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions" . $fuelWhere, $fuelParams);
    $fuelTransactionsStmt = $pdo->prepare(
        "SELECT ft.fuel_date, v.vehicle_name, ft.fuel_type, ft.total_amount, ft.fuel_station, ft.receipt_no
         FROM fuel_transactions ft
         LEFT JOIN vehicles v ON v.id = ft.vehicle_id" . $fuelWhere . "
         ORDER BY ft.fuel_date DESC, ft.id DESC LIMIT 100"
    );
    $fuelTransactionsStmt->execute($fuelParams);
    $fuelTransactions = $fuelTransactionsStmt->fetchAll();
?>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Uniform Sales</h2>
            <p class="panel-subtitle">Recent uniform sales summary.</p>
        </div>
        <strong><?= money($uniformTotalPaid) ?></strong>
    </div>
    <div class="table-responsive report-table mt-3">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Receipt</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Grand Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uniformRows as $r): ?>
                    <tr>
                        <td><?= h($r['receipt_no']) ?></td>
                        <td><?= h(($r['registration_no'] ?: '---') . ' - ' . ($r['full_name'] ?: 'Unknown')) ?></td>
                        <td><?= h($r['class_level'] ?: 'N/A') ?></td>
                        <td><?= money((float)$r['grand_total']) ?></td>
                        <td><?= money((float)$r['amount_paid']) ?></td>
                        <td><?= money((float)$r['balance']) ?></td>
                        <td><?= h($r['payment_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$uniformRows): ?><tr><td colspan="7">No uniform sales found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <h3 class="h5 mt-4">Fuel transaction history</h3>
    <div class="table-responsive report-table mt-3">
        <table class="table table-striped align-middle">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Fuel Type</th><th>Station</th><th>Receipt</th><th>Amount Spent</th></tr></thead>
            <tbody>
                <?php foreach ($fuelTransactions as $fuel): ?>
                    <tr>
                        <td><?= h($fuel['fuel_date']) ?></td>
                        <td><?= h($fuel['vehicle_name'] ?: 'N/A') ?></td>
                        <td><?= h($fuel['fuel_type']) ?></td>
                        <td><?= h($fuel['fuel_station']) ?></td>
                        <td><?= h($fuel['receipt_no']) ?></td>
                        <td><?= money((float) $fuel['total_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$fuelTransactions): ?><tr><td colspan="6">No fuel transactions found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Fuel - Monthly Summary</h2>
            <p class="panel-subtitle">Monthly fuel expenditure (last 24 months).</p>
        </div>
        <strong><?= money($fuelTotalSpent) ?></strong>
    </div>
    <div class="table-responsive report-table mt-3">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Total Spent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fuelRows as $f): ?>
                    <tr>
                        <td><?= h($f['month']) ?></td>
                        <td><?= money((float)$f['total_spent']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$fuelRows): ?><tr><td colspan="2">No fuel data found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Kitchen Expense Records</h2>
            <p class="panel-subtitle">Recorded kitchen spending, with historical entries retained.</p>
        </div>
        <strong><?= money($kitchenExpenses) ?></strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Item</th><th>Category</th><th>Quantity</th><th>Unit Price</th><th>Total</th><th>Date</th><th>Notes</th></tr>
            </thead>
            <tbody>
                <?php foreach ($kitchenRows as $row): ?>
                    <tr>
                        <td><?= h($row['item_name']) ?></td>
                        <td><?= (float) $row['quantity'] > 0 ? h(number_format((float) $row['quantity'], 2)) : '—' ?></td>
                        <td><?= (float) $row['unit_price'] > 0 ? money((float) $row['unit_price']) : '—' ?></td>
                        <td><?= money((float) $row['total_amount']) ?></td>
                        <td><?= h($row['item_date']) ?></td>
                        <td><?= h(trim((string) preg_replace('/^Kitchen category: [^|]+\\s*\\|\\s*(?:Kitchen entry|Purchase) #\\d+(?:\\s*\\|\\s*Stock tracked: (?:Yes|No))?\\s*\\|?\\s*/', '', (string) $row['notes']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$kitchenRows): ?><tr><td colspan="7">No kitchen expenses found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Feeding Report</h2>
            <p class="panel-subtitle">All feeding subscriptions with student, parent, required amount, paid amount, balance, and status details.</p>
        </div>
        <strong><?= count($feedingReportRows) ?> records</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Admission No</th><th>Student</th><th>Gender</th><th>Parent</th><th>Phone</th><th>Class</th><th>Year</th><th>Term</th><th>Required</th><th>Paid</th><th>Balance</th><th>Status</th><th>Added</th><th>Updated</th></tr>
            </thead>
            <tbody>
                <?php foreach ($feedingReportRows as $row): ?>
                    <tr class="<?= (float) $row['balance'] > 0.005 ? 'balance-high' : '' ?>">
                        <td><?= h($row['registration_no']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['gender']) ?></td>
                        <td><?= h($row['parent_name']) ?></td>
                        <td><?= h($row['guardian_phone'] ?: 'N/A') ?></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['required_amount']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedingReportRows): ?><tr><td colspan="14">No feeding records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Feeding Payment History</h2>
            <p class="panel-subtitle">All feeding payments with references, student details, academic term, payment date, and recorded date.</p>
        </div>
        <strong><?= money(array_sum(array_map(fn($row) => (float) $row['amount_paid'], $feedingPaymentRows))) ?></strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Reference</th><th>Student</th><th>Class</th><th>Academic Year</th><th>Term</th><th>Amount</th><th>Payment Date</th><th>Recorded At</th></tr>
            </thead>
            <tbody>
                <?php foreach ($feedingPaymentRows as $row): ?>
                    <tr>
                        <td><?= h($row['reference_no'] ?: 'N/A') ?></td>
                        <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['amount_paid']) ?></td>
                        <td><?= h($row['payment_date']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedingPaymentRows): ?><tr><td colspan="8">No feeding payment history found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Transport Accounts Report</h2>
            <p class="panel-subtitle">All transport students and accounts with parent, route, amount due, payments, balances, and status details.</p>
        </div>
        <strong><?= count($transportAccountRows) ?> records</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Student</th><th>Admission/Type</th><th>Gender</th><th>Parent</th><th>Phone</th><th>Route</th><th>Year</th><th>Term</th><th>Due</th><th>Paid</th><th>Balance</th><th>Status</th><th>Added</th><th>Updated</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transportAccountRows as $row): ?>
                    <tr class="<?= (float) $row['balance'] > 0.005 ? 'balance-high' : '' ?>">
                        <td><?= h($row['student_name']) ?></td>
                        <td><?= h($row['registration_no'] ?: ((int) $row['is_outside'] === 1 ? 'Outside student' : 'N/A')) ?><br><small><?= h($row['class_level'] ?: 'N/A') ?></small></td>
                        <td><?= h($row['gender']) ?></td>
                        <td><?= h($row['parent_name']) ?></td>
                        <td><?= h($row['parent_phone'] ?: 'N/A') ?></td>
                        <td><?= h($row['pickup_location']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['amount_due']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportAccountRows): ?><tr><td colspan="14">No transport account records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Transport Fee Structure Report</h2>
            <p class="panel-subtitle">Active and inactive route fee records used to calculate transport charges.</p>
        </div>
        <strong><?= count($transportFeeStructures) ?> records</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead><tr><th>Location</th><th>Fee Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($transportFeeStructures as $structure): ?>
                    <tr>
                        <td><?= h($structure['location_name']) ?></td>
                        <td><?= money((float) $structure['fee_amount']) ?></td>
                        <td><?= h($structure['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportFeeStructures): ?><tr><td colspan="3">No transport fee locations found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Students by Location</h2>
            <p class="panel-subtitle">Counts and current term totals grouped by pickup/drop location.</p>
        </div>
        <strong><?= count($transportStudentsByLocation) ?> locations</strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead><tr><th>Location</th><th>Students</th><th>Total Due</th></tr></thead>
            <tbody>
                <?php foreach ($transportStudentsByLocation as $row): ?>
                    <tr>
                        <td><?= h($row['pickup_location']) ?></td>
                        <td><?= h($row['student_count']) ?></td>
                        <td><?= money((float) $row['total_due']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportStudentsByLocation): ?><tr><td colspan="3">No transport location groups found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Transport Payment History</h2>
            <p class="panel-subtitle">All transport payments with references, route, academic term, payment date, and recorded date.</p>
        </div>
        <strong><?= money(array_sum(array_map(fn($row) => (float) $row['amount_paid'], $transportPaymentRows))) ?></strong>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>Reference</th><th>Student</th><th>Route</th><th>Academic Year</th><th>Term</th><th>Amount</th><th>Payment Date</th><th>Recorded At</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transportPaymentRows as $row): ?>
                    <tr>
                        <td><?= h($row['reference_no'] ?: 'N/A') ?></td>
                        <td><?= h($row['student_name']) ?></td>
                        <td><?= h($row['pickup_location']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['amount_paid']) ?></td>
                        <td><?= h($row['payment_date']) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportPaymentRows): ?><tr><td colspan="8">No transport payment history found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canViewDaycarePayments && $daycarePaymentsAvailable): ?>
<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2>Daycare Payment History</h2>
            <p class="panel-subtitle">Daycare collections for the selected report period. Reversed receipts remain listed; only recorded payments count toward the total.</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <strong><?= money($daycareCollected) ?></strong>
        </div>
    </div>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead><tr><th>Payment Date</th><th>Child</th><th>Admission No.</th><th>Receipt</th><th>Category / Period</th><th>Method / Reference</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($daycarePaymentRows as $row): ?>
                    <tr>
                        <td><?= h($row['payment_date']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['admission_no']) ?></td>
                        <td><?= h($row['receipt_no']) ?></td>
                        <td><?= h($row['category']) ?><?= $row['billing_period'] ? '<br><small>' . h($row['billing_period']) . '</small>' : '' ?></td>
                        <td><?= h($row['payment_method']) ?><?= $row['payment_reference'] ? '<br><small>' . h($row['payment_reference']) . '</small>' : '' ?></td>
                        <td><?= money((float) $row['amount']) ?></td>
                        <td><span class="badge <?= $row['payment_status'] === 'Recorded' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= h($row['payment_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$daycarePaymentRows): ?><tr><td colspan="8">No daycare payments found for the selected report period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel mt-4">
    <h2>Feeding Balances</h2>
    <div class="table-responsive report-table">
        <table class="table table-striped align-middle">
            <thead><tr><th>Student Name</th><th>Class</th><th>Enrollment</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th></tr></thead>
            <tbody>
                <?php foreach ($feedingBalanceRows as $row): ?>
                    <tr class="balance-high">
                        <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                        <td><?= h($row['class_level']) ?></td>
                        <td><?= h($row['enrollment_status']) ?></td>
                        <td><?= h($row['academic_year']) ?></td>
                        <td><?= h($row['term']) ?></td>
                        <td><?= money((float) $row['required_amount']) ?></td>
                        <td><?= money((float) $row['paid_amount']) ?></td>
                        <td><?= money((float) $row['balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedingBalanceRows): ?><tr><td colspan="8">No feeding balances found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <section class="panel">
            <h2>Students per Class</h2>
            <div class="chart-wrap compact-chart"><canvas id="studentsPerClassChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Fee Collection Summary</h2>
            <div class="chart-wrap compact-chart"><canvas id="feeCollectionSummaryChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Paid vs Unpaid</h2>
            <div class="chart-wrap compact-chart"><canvas id="paidUnpaidChart"></canvas></div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <section class="panel">
            <h2>Expenses Summary</h2>
            <div class="chart-wrap compact-chart"><canvas id="expensesSummaryChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Transport Payments</h2>
            <div class="chart-wrap compact-chart"><canvas id="transportPaymentsChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Feeding Payments</h2>
            <div class="chart-wrap compact-chart"><canvas id="feedingPaymentsChart"></canvas></div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <section class="panel">
            <h2>Monthly Collections</h2>
            <div class="chart-wrap compact-chart"><canvas id="monthlyCollectionsChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Income vs Expenditure</h2>
            <div class="chart-wrap compact-chart"><canvas id="incomeExpenseChart"></canvas></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="panel">
            <h2>Summary</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <tbody>
                        <tr><th>Feeding Payments</th><td><?= money($feedingPayments) ?></td></tr>
                        <?php if ($canViewDaycarePayments && $daycarePaymentsAvailable): ?><tr><th>Daycare Payments</th><td><?= money($daycareCollected) ?></td></tr><?php endif; ?>
                        <tr><th>School Expenses</th><td><?= money($schoolExpenses) ?></td></tr>
                        <tr><th>Total Income</th><td><?= money($totalIncome) ?></td></tr>
                        <tr><th>Total Expenditure</th><td><?= money($totalExpenditure) ?></td></tr>
                        <tr><th>Net Income</th><td><?= money($netIncome) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Term Fee Balances</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Class</th><th>Enrollment</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th></tr></thead>
                    <tbody>
                        <?php foreach ($feeTermReport as $row): ?>
                            <tr class="<?= (float) $row['balance'] > 0.005 ? 'balance-high' : '' ?>">
                                <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                                <td><?= h($row['class_level']) ?></td>
                                <td><?= h($row['enrollment_status']) ?></td>
                                <td><?= h($row['academic_year']) ?></td>
                                <td><?= h($row['term']) ?></td>
                                <td><?= money((float) $row['required_amount']) ?></td>
                                <td><?= money((float) $row['paid_amount']) ?></td>
                                <td><?= money((float) $row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$feeTermReport): ?><tr><td colspan="8">No term balances found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel">
            <h2>Outstanding Term Balances</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Class</th><th>Enrollment</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th></tr></thead>
                    <tbody>
                        <?php foreach ($outstandingTermBalances as $row): ?>
                            <tr class="balance-high">
                                <td><?= h($row['full_name']) ?><br><small><?= h($row['registration_no']) ?></small></td>
                                <td><?= h($row['class_level']) ?></td>
                                <td><?= h($row['enrollment_status']) ?></td>
                                <td><?= h($row['academic_year']) ?></td>
                                <td><?= h($row['term']) ?></td>
                                <td><?= money((float) $row['required_amount']) ?></td>
                                <td><?= money((float) $row['paid_amount']) ?></td>
                                <td><?= money((float) $row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$outstandingTermBalances): ?><tr><td colspan="8">No outstanding term balances found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Unpaid Balance Totals by Term</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Academic Year</th><th>Term</th><th>Total Unpaid</th></tr></thead>
                    <tbody>
                        <?php foreach ($unpaidByTerm as $row): ?>
                            <tr><td><?= h($row['academic_year']) ?></td><td><?= h($row['term']) ?></td><td><?= money((float) $row['total_balance']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$unpaidByTerm): ?><tr><td colspan="3">No unpaid term balances found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel">
            <h2>Fee Payments by Academic Term</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Academic Year</th><th>Term</th><th>Total Paid</th></tr></thead>
                    <tbody>
                        <?php foreach ($paymentsByTerm as $row): ?>
                            <tr><td><?= h($row['year']) ?></td><td><?= h($row['term']) ?></td><td><?= money((float) $row['total_paid']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentsByTerm): ?><tr><td colspan="3">No payments found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Daily Collections</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Date</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($dailyCollections as $row): ?>
                            <tr><td><?= h($row['collection_date']) ?></td><td><?= money((float) $row['total_paid']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$dailyCollections): ?><tr><td colspan="2">No daily collections found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel">
            <h2>Monthly Collections</h2>
            <div class="table-responsive report-table">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Month</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($monthlyCollections as $row): ?>
                            <tr><td><?= h($row['collection_month']) ?></td><td><?= money((float) $row['total_paid']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$monthlyCollections): ?><tr><td colspan="2">No monthly collections found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="panel mt-4">
    <h2>Expense Summaries</h2>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>Category</th><th>Total</th></tr></thead>
            <tbody>
                <tr><td>Kitchen Expenses</td><td><?= money($kitchenExpenses) ?></td></tr>
                <tr><td>Fuel Expenses</td><td><?= money($fuelExpenses) ?></td></tr>
                <?php foreach ($expenseSummaries as $row): ?>
                    <tr><td><?= h($row['category']) ?></td><td><?= money((float) $row['total_amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$expenseSummaries && $kitchenExpenses <= 0 && $fuelExpenses <= 0): ?><tr><td colspan="2">No expenses found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
window.reportCharts = {
    studentsPerClass: {
        labels: <?= json_encode(array_column($studentsPerClass, 'class_level')) ?>,
        values: <?= json_encode(array_map('intval', array_column($studentsPerClass, 'student_count'))) ?>
    },
    paymentsByTerm: {
        labels: <?= json_encode(array_column($paymentsByTerm, 'term_label')) ?>,
        values: <?= json_encode(array_map('floatval', array_column($paymentsByTerm, 'total_paid'))) ?>
    },
    feeCollectionSummary: {
        labels: ['Required', 'Paid', 'Balance'],
        values: <?= json_encode([$totalRequiredFees, $totalPaidForBalances, $totalFeeBalances]) ?>
    },
    paidUnpaid: {
        labels: ['Paid', 'Unpaid'],
        values: <?= json_encode([$totalPaidForBalances, $totalFeeBalances]) ?>
    },
    expensesSummary: {
        labels: <?= json_encode($expenseChartLabels) ?>,
        values: <?= json_encode($expenseChartValues) ?>
    },
    transportPayments: {
        labels: <?= json_encode(array_column($transportByTerm, 'term')) ?>,
        values: <?= json_encode(array_map('floatval', array_column($transportByTerm, 'total_paid'))) ?>
    },
    feedingPayments: {
        labels: <?= json_encode(array_column($feedingByTerm, 'term')) ?>,
        values: <?= json_encode(array_map('floatval', array_column($feedingByTerm, 'total_paid'))) ?>
    },
    monthlyCollections: {
        labels: <?= json_encode(array_reverse(array_column($monthlyCollections, 'collection_month'))) ?>,
        values: <?= json_encode(array_reverse(array_map('floatval', array_column($monthlyCollections, 'total_paid')))) ?>
    },
    incomeExpense: {
        labels: ['Income', 'Expenditure'],
        values: <?= json_encode([$totalIncome, $totalExpenditure]) ?>
    }
};
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
