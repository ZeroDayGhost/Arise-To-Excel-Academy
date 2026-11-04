<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/dashboard_finance_helpers.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
activities_ensure_schema($pdo);

// Module access check
if (!current_admin_has_permission($pdo, 'dashboard.view')) {
    flash('error', 'You do not have permission to view the Dashboard.');
    redirect('admin/students.php');
}

$currentContext = current_academic_context($pdo);

// Current admin role and class-level (teachers should only see their class)
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$currentAdminRole = $adminId ? get_user_role_template_name($pdo, $adminId) : '';
$currentAdminClass = $adminId ? current_admin_class_level($pdo) : null;

// Block teacher access to dashboard; redirect to students page
if ($currentAdminRole === 'Teacher') {
    $studentPageUrl = 'admin/students.php';
    if ($currentAdminClass) {
        $studentPageUrl .= '?class_level=' . urlencode($currentAdminClass);
    }
    redirect($studentPageUrl);
}

function dashboard_balance_class(float $balance, float $requiredAmount, float $paidAmount): string
{
    if ($balance <= 0.005) {
        return '';
    }

    if ($paidAmount <= 0.005 || ($requiredAmount > 0 && $balance >= ($requiredAmount * 0.5))) {
        return 'balance-high';
    }

    return 'balance-partial';
}

function dashboard_percent(float $value, float $total): int
{
    if ($total <= 0.005) {
        return 0;
    }

    return (int) round(($value / $total) * 100);
}

function dashboard_date_label(?string $date): string
{
    if (!$date) {
        return 'Recently';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : 'Recently';
}

$summaryParams = [
        'academic_year' => $currentContext['academic_year'],
        'term' => $currentContext['term'],
        'fee_year' => $currentContext['academic_year'],
        'fee_term' => $currentContext['term'],
        'balance_year' => $currentContext['academic_year'],
        'balance_term' => $currentContext['term'],
        'academic_year_transport' => $currentContext['academic_year'],
        'term_transport' => $currentContext['term'],
        'expense_year' => $currentContext['academic_year'],
        'fuel_year' => $currentContext['academic_year'],
        'uniform_year' => $currentContext['academic_year'],
];

// If current admin is a teacher with a class assigned, scope student-related counts to that class
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
        $dashboardStudentClassSql = student_class_sql_for_year('students', (string) $currentContext['academic_year']);
        $dashboardAliasClassSql = student_class_sql_for_year('s', (string) $currentContext['academic_year']);
        $dashboardClassValue = $pdo->quote($currentAdminClass);
        $summarySql = "SELECT
                (SELECT COUNT(*) FROM students WHERE {$dashboardStudentClassSql} = {$dashboardClassValue} AND enrollment_status = 'Active') AS total_students,
                (SELECT COUNT(*) FROM students WHERE gender = 'Male' AND {$dashboardStudentClassSql} = {$dashboardClassValue} AND enrollment_status = 'Active') AS total_boys,
                (SELECT COUNT(*) FROM students WHERE gender = 'Female' AND {$dashboardStudentClassSql} = {$dashboardClassValue} AND enrollment_status = 'Active') AS total_girls,
                (SELECT COALESCE(SUM(amount_paid), 0)
                 FROM fees
                 WHERE year = :fee_year
                     AND term = :fee_term
                     AND payment_status = 'Recorded'
                     AND student_id IN (SELECT id FROM students WHERE {$dashboardStudentClassSql} = {$dashboardClassValue})) AS total_fee_collected,
                (SELECT COALESCE(SUM(balance), 0)
                 FROM fee_balances
                 WHERE academic_year = :balance_year
                     AND term = :balance_term
                     AND student_id IN (SELECT id FROM students WHERE {$dashboardStudentClassSql} = {$dashboardClassValue})) AS total_fee_balance,
                (SELECT COUNT(DISTINCT student_id)
                 FROM feeding_subscriptions
                 WHERE academic_year = :academic_year
                     AND term = :term
                     AND status = 'Active'
                     AND student_id IN (SELECT id FROM students WHERE {$dashboardStudentClassSql} = {$dashboardClassValue} AND enrollment_status = 'Active')) AS total_feeding_students,
                (SELECT COUNT(DISTINCT ta.id)
                 FROM transport_accounts ta
                 JOIN transport_students ts ON ts.id = ta.transport_student_id
                 LEFT JOIN students s ON s.id = ts.student_id
                 WHERE ta.academic_year = :academic_year_transport
                     AND ta.term = :term_transport
                     AND {$dashboardAliasClassSql} = {$dashboardClassValue}) AS total_transport_students,
                (SELECT COALESCE(SUM(amount_paid), 0)
                 FROM uniform_sales
                 WHERE YEAR(payment_date) = :uniform_year
                     AND (student_id IS NULL OR student_id IN (SELECT id FROM students WHERE {$dashboardStudentClassSql} = {$dashboardClassValue}))) AS total_uniform_collected,
                ((SELECT COALESCE(SUM(total_amount), 0)
                    FROM school_expenses
                    WHERE YEAR(expense_date) = :expense_year)
                 + (SELECT COALESCE(SUM(total_amount), 0)
                        FROM fuel_transactions
                        WHERE YEAR(fuel_date) = :fuel_year)) AS total_expenses";

} else {
        $summarySql = "SELECT
                (SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active') AS total_students,
                (SELECT COUNT(*) FROM students WHERE gender = 'Male' AND enrollment_status = 'Active') AS total_boys,
                (SELECT COUNT(*) FROM students WHERE gender = 'Female' AND enrollment_status = 'Active') AS total_girls,
                (SELECT COALESCE(SUM(amount_paid), 0)
                 FROM fees
                 WHERE year = :fee_year
                     AND term = :fee_term
                     AND payment_status = 'Recorded') AS total_fee_collected,
                (SELECT COALESCE(SUM(balance), 0)
                 FROM fee_balances
                 WHERE academic_year = :balance_year
                     AND term = :balance_term) AS total_fee_balance,
                (SELECT COUNT(DISTINCT student_id)
                 FROM feeding_subscriptions
                 WHERE academic_year = :academic_year
                     AND term = :term
                     AND status = 'Active'
                     AND student_id IN (SELECT id FROM students WHERE enrollment_status = 'Active')) AS total_feeding_students,
                (SELECT COUNT(*)
                 FROM transport_accounts
                 WHERE academic_year = :academic_year_transport
                     AND term = :term_transport) AS total_transport_students,
                (SELECT COALESCE(SUM(amount_paid), 0)
                 FROM uniform_sales
                 WHERE YEAR(payment_date) = :uniform_year) AS total_uniform_collected,
                ((SELECT COALESCE(SUM(total_amount), 0)
                    FROM school_expenses
                    WHERE YEAR(expense_date) = :expense_year)
                 + (SELECT COALESCE(SUM(total_amount), 0)
                        FROM fuel_transactions
                        WHERE YEAR(fuel_date) = :fuel_year)) AS total_expenses";
}

$summaryStatement = $pdo->prepare($summarySql);
$summaryStatement->execute($summaryParams);
$summary = $summaryStatement->fetch();

$studentTotal = (int) $summary['total_students'];
$totalBoys = (int) $summary['total_boys'];
$totalGirls = (int) $summary['total_girls'];
$totalFeeCollected = (float) $summary['total_fee_collected'];
$totalUniformCollected = (float) $summary['total_uniform_collected'];
$totalBalance = (float) $summary['total_fee_balance'];
$totalFeedingStudents = (int) $summary['total_feeding_students'];
$totalTransportStudents = (int) $summary['total_transport_students'];
$totalExpenses = (float) $summary['total_expenses'];

$feeTermReport = fetch_fee_term_report($pdo, [
    'year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
]);
$totalRequiredFees = array_sum(array_map(fn($row) => (float) $row['required_amount'], $feeTermReport));
$totalPaidForBalances = array_sum(array_map(fn($row) => (float) $row['paid_amount'], $feeTermReport));

$expectedCollections = [
    'School fees' => $totalRequiredFees,
    'Transport' => 0.0,
    'Feeding' => 0.0,
    'Daycare' => 0.0,
    'Activities' => 0.0,
    'School uniform' => 0.0,
];

$transportExpected = $pdo->prepare("SELECT COALESCE(SUM(amount_due), 0)
    FROM transport_accounts
    WHERE academic_year = :year AND term = :term AND status = 'Active'");
$transportExpected->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
$expectedCollections['Transport'] = (float) $transportExpected->fetchColumn();

$feedingExpected = $pdo->prepare("SELECT COALESCE(SUM(feeding_amount), 0)
    FROM feeding_subscriptions
    WHERE academic_year = :year AND term = :term AND status = 'Active'");
$feedingExpected->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
$expectedCollections['Feeding'] = (float) $feedingExpected->fetchColumn();

$activityExpected = $pdo->prepare("SELECT COALESCE(SUM(ap.expected_amount), 0)
    FROM activities a
    JOIN activity_participants ap ON ap.activity_id = a.id
    WHERE a.academic_year = :year AND a.term = :term
      AND a.status = 'Active' AND ap.participation_status = 'Active'");
$activityExpected->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
$expectedCollections['Activities'] = (float) $activityExpected->fetchColumn();

$daycareChargesReady = (bool) $pdo->query("SHOW TABLES LIKE 'daycare_fee_charges'")->fetchColumn();
if ($daycareChargesReady) {
    if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
        $daycareExpected = $pdo->prepare("SELECT COALESCE(SUM(amount - discount), 0)
            FROM daycare_fee_charges
            WHERE status = 'Posted' AND charge_date BETWEEN :start_date AND :end_date");
        $daycareExpected->execute([
            'start_date' => $currentContext['start_date'],
            'end_date' => $currentContext['end_date'],
        ]);
    } else {
        $daycareExpected = $pdo->prepare("SELECT COALESCE(SUM(amount - discount), 0)
            FROM daycare_fee_charges
            WHERE status = 'Posted' AND YEAR(charge_date) = :year");
        $daycareExpected->execute(['year' => $currentContext['academic_year']]);
    }
    $expectedCollections['Daycare'] = (float) $daycareExpected->fetchColumn();
}

if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $uniformExpected = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0)
        FROM uniform_sales
        WHERE payment_date >= :start_date AND payment_date < DATE_ADD(:end_date, INTERVAL 1 DAY)");
    $uniformExpected->execute([
        'start_date' => $currentContext['start_date'],
        'end_date' => $currentContext['end_date'],
    ]);
} else {
    $uniformExpected = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0)
        FROM uniform_sales
        WHERE YEAR(payment_date) = :year");
    $uniformExpected->execute(['year' => $currentContext['academic_year']]);
}
$expectedCollections['School uniform'] = (float) $uniformExpected->fetchColumn();
$totalExpectedCollections = array_sum($expectedCollections);

$balanceRows = array_values(array_filter($feeTermReport, fn($row) => (float) $row['balance'] > 0.005));
usort($balanceRows, fn($left, $right) => (float) $right['balance'] <=> (float) $left['balance']);
$balances = array_slice($balanceRows, 0, 12);

$monthlyRowsStatement = $pdo->prepare(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS payment_month, SUM(amount_paid) AS monthly_total
     FROM fees
     WHERE year = :academic_year
       AND term = :term
       AND payment_status = 'Recorded'
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ORDER BY payment_month ASC"
);
$monthlyRowsStatement->execute([
    'academic_year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
]);
$monthlyRows = $monthlyRowsStatement->fetchAll();
$chartLabels = array_column($monthlyRows, 'payment_month');
$chartTotals = array_map('floatval', array_column($monthlyRows, 'monthly_total'));

// Uniform monthly revenue (current academic year)
$sql = "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS ym, SUM(amount_paid) AS total FROM uniform_sales";
if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $sql .= " WHERE payment_date BETWEEN :start_date AND :end_date";
    $sql .= " GROUP BY ym ORDER BY ym ASC LIMIT 12";
    $uniformMonthlyRowsStatement = $pdo->prepare($sql);
    $uniformMonthlyRowsStatement->execute(['start_date' => $currentContext['start_date'], 'end_date' => $currentContext['end_date']]);
} else {
    $sql .= " WHERE YEAR(payment_date) = :uniform_year GROUP BY ym ORDER BY ym ASC LIMIT 12";
    $uniformMonthlyRowsStatement = $pdo->prepare($sql);
    $uniformMonthlyRowsStatement->execute(['uniform_year' => $currentContext['academic_year']]);
}
$uniformMonthlyRows = $uniformMonthlyRowsStatement->fetchAll();
$uniformChartLabels = array_column($uniformMonthlyRows, 'ym');
$uniformChartTotals = array_map('floatval', array_column($uniformMonthlyRows, 'total'));

// Fuel monthly spend (current academic year)
$sqlf = "SELECT DATE_FORMAT(fuel_date,'%Y-%m') AS ym, SUM(total_amount) AS total FROM fuel_transactions";
if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $sqlf .= " WHERE fuel_date BETWEEN :start AND :end GROUP BY ym ORDER BY ym ASC LIMIT 12";
    $fuelMonthlyRowsStatement = $pdo->prepare($sqlf);
    $fuelMonthlyRowsStatement->execute(['start' => $currentContext['start_date'], 'end' => $currentContext['end_date']]);
} else {
    $sqlf .= " WHERE YEAR(fuel_date) = :fuel_year GROUP BY ym ORDER BY ym ASC LIMIT 12";
    $fuelMonthlyRowsStatement = $pdo->prepare($sqlf);
    $fuelMonthlyRowsStatement->execute(['fuel_year' => $currentContext['academic_year']]);
}
$fuelMonthlyRows = $fuelMonthlyRowsStatement->fetchAll();
$fuelChartLabels = array_column($fuelMonthlyRows, 'ym');
$fuelChartTotals = array_map('floatval', array_column($fuelMonthlyRows, 'total'));

// These period totals are populated from the selected calendar range below.
$fuelExpenses = 0.0;
$feedingPayments = 0.0;
$transportPayments = 0.0;
$activityIncome = 0.0;

// Recalculate key totals scoped to the active term where possible
$termStart = $currentContext['start_date'] ?? null;
$termEnd = $currentContext['end_date'] ?? null;
if (!empty($termStart) && !empty($termEnd)) {
    // Uniform total should be scoped to the current term's date range
    $ut = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM uniform_sales WHERE payment_date BETWEEN :start AND :end');
    $ut->execute(['start' => $termStart, 'end' => $termEnd]);
    $totalUniformCollected = (float) $ut->fetchColumn();

    // Expenses term total
    $stmtExp = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE expense_date BETWEEN :start AND :end');
    $stmtExp->execute(['start' => $termStart, 'end' => $termEnd]);
    $totalSchoolExpenses = (float) $stmtExp->fetchColumn();

    $stmtFuelExp = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE fuel_date BETWEEN :start AND :end');
    $stmtFuelExp->execute(['start' => $termStart, 'end' => $termEnd]);
    $fuelExpenses = (float) $stmtFuelExp->fetchColumn();

    // Feeding payments for term — join via subscriptions
    $stmtFeed = $pdo->prepare('SELECT COALESCE(SUM(fp.amount_paid),0) FROM feeding_payments fp JOIN feeding_subscriptions fs ON fs.id = fp.feeding_subscription_id WHERE fs.academic_year = :year AND fs.term = :term');
    $stmtFeed->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
    $feedingPayments = (float) $stmtFeed->fetchColumn();

    // Transport payments for term
    $stmtTrans = $pdo->prepare('SELECT COALESCE(SUM(tp.amount_paid),0) FROM transport_payments tp JOIN transport_accounts ta ON ta.id = tp.transport_account_id WHERE ta.academic_year = :year AND ta.term = :term');
    $stmtTrans->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
    $transportPayments = (float) $stmtTrans->fetchColumn();

    $stmtActivity = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM activity_payments WHERE academic_year = :year AND term = :term AND status = 'Recorded'");
    $stmtActivity->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
    $activityIncome = (float) $stmtActivity->fetchColumn();

    $financialTotals = dashboard_financial_totals(
        $totalFeeCollected,
        $totalUniformCollected,
        $feedingPayments,
        $transportPayments,
        $totalSchoolExpenses,
        $fuelExpenses,
        $activityIncome
    );
} else {
    // fallback: use academic year where appropriate
    $stmtUni = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM uniform_sales WHERE YEAR(payment_date) = :year');
    $stmtUni->execute(['year' => $currentContext['academic_year']]);
    $totalUniformCollected = (float) $stmtUni->fetchColumn();

    $stmtExp = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE YEAR(expense_date) = :year');
    $stmtExp->execute(['year' => $currentContext['academic_year']]);
    $totalSchoolExpenses = (float) $stmtExp->fetchColumn();

    $stmtFuelExp = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE YEAR(fuel_date) = :year');
    $stmtFuelExp->execute(['year' => $currentContext['academic_year']]);
    $fuelExpenses = (float) $stmtFuelExp->fetchColumn();

    $stmtFeed = $pdo->prepare('SELECT COALESCE(SUM(fp.amount_paid),0) FROM feeding_payments fp JOIN feeding_subscriptions fs ON fs.id = fp.feeding_subscription_id WHERE fs.academic_year = :year');
    $stmtFeed->execute(['year' => $currentContext['academic_year']]);
    $feedingPayments = (float) $stmtFeed->fetchColumn();

    $stmtTrans = $pdo->prepare('SELECT COALESCE(SUM(tp.amount_paid),0) FROM transport_payments tp JOIN transport_accounts ta ON ta.id = tp.transport_account_id WHERE ta.academic_year = :year');
    $stmtTrans->execute(['year' => $currentContext['academic_year']]);
    $transportPayments = (float) $stmtTrans->fetchColumn();

    $stmtActivity = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM activity_payments WHERE academic_year = :year AND status = 'Recorded'");
    $stmtActivity->execute(['year' => $currentContext['academic_year']]);
    $activityIncome = (float) $stmtActivity->fetchColumn();

    $financialTotals = dashboard_financial_totals(
        $totalFeeCollected,
        $totalUniformCollected,
        $feedingPayments,
        $transportPayments,
        $totalSchoolExpenses,
        $fuelExpenses,
        $activityIncome
    );
}
$totalIncome = $financialTotals['income'];
$totalExpenses = $financialTotals['expenses'];
$netPosition = $financialTotals['net_position'];

$recentFeePaymentsParams = ['year' => $currentContext['academic_year'], 'term' => $currentContext['term']];
$recentFeeSql = "SELECT fees.id, fees.receipt_no, fees.amount_paid, fees.payment_date, fees.term, fees.year, students.full_name
     FROM fees
     JOIN students ON students.id = fees.student_id
     WHERE fees.year = :year AND fees.term = :term AND fees.payment_status = 'Recorded'";
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $recentFeeSql .= ' AND ' . student_class_sql('students', 'fees.year') . ' = :class_level';
    $recentFeePaymentsParams['class_level'] = $currentAdminClass;
}
$recentFeeSql .= ' ORDER BY fees.payment_date DESC, fees.id DESC LIMIT 5';
$recentFeePaymentsStmt = $pdo->prepare($recentFeeSql);
$recentFeePaymentsStmt->execute($recentFeePaymentsParams);
$recentFeePayments = $recentFeePaymentsStmt->fetchAll();

// recent expenses (term-aware)
$recentExpenses = [];
// school_expenses
if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
    $stmt = $pdo->prepare("SELECT id, item_name AS title, category AS detail, total_amount AS amount, expense_date AS activity_date FROM school_expenses WHERE academic_year = :year AND term = :term ORDER BY expense_date DESC, id DESC LIMIT 5");
    $stmt->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
    $rows = $stmt->fetchAll();
} elseif (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $stmt = $pdo->prepare("SELECT id, item_name AS title, category AS detail, total_amount AS amount, expense_date AS activity_date FROM school_expenses WHERE expense_date BETWEEN :start AND :end ORDER BY expense_date DESC, id DESC LIMIT 5");
    $stmt->execute(['start' => $currentContext['start_date'], 'end' => $currentContext['end_date']]);
    $rows = $stmt->fetchAll();
} else {
    $rows = $pdo->query("SELECT id, item_name AS title, category AS detail, total_amount AS amount, expense_date AS activity_date FROM school_expenses ORDER BY expense_date DESC, id DESC LIMIT 5")->fetchAll();
}
foreach ($rows as $r) {
    $recentExpenses[] = array_merge($r, ['activity_type' => 'School Expense']);
}

// kitchen_inventory rows
if (db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
    $kstmt = $pdo->prepare("SELECT id, item_name AS title, COALESCE(supplier, '') AS detail, total_amount AS amount, item_date AS activity_date FROM kitchen_inventory WHERE academic_year = :year AND term = :term ORDER BY item_date DESC, id DESC LIMIT 5");
    $kstmt->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
    $krows = $kstmt->fetchAll();
} elseif (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $kstmt = $pdo->prepare("SELECT id, item_name AS title, COALESCE(supplier, '') AS detail, total_amount AS amount, item_date AS activity_date FROM kitchen_inventory WHERE item_date BETWEEN :start AND :end ORDER BY item_date DESC, id DESC LIMIT 5");
    $kstmt->execute(['start' => $currentContext['start_date'], 'end' => $currentContext['end_date']]);
    $krows = $kstmt->fetchAll();
} else {
    $krows = $pdo->query("SELECT id, item_name AS title, COALESCE(supplier, '') AS detail, total_amount AS amount, item_date AS activity_date FROM kitchen_inventory ORDER BY item_date DESC, id DESC LIMIT 5")->fetchAll();
}
foreach ($krows as $r) {
    $recentExpenses[] = array_merge($r, ['activity_type' => 'Kitchen Inventory']);
}

// Build recent daily purchases using term-aware helper
$recentDaily = get_daily_purchases($pdo, null, null, $currentContext['academic_year'], $currentContext['term']);
$recentFeedingParams = ['year' => $currentContext['academic_year'], 'term' => $currentContext['term']];
$recentFeedingSql = "SELECT feeding_payments.amount_paid, feeding_payments.payment_date, feeding_subscriptions.term, feeding_subscriptions.academic_year, students.full_name
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id
     WHERE feeding_subscriptions.academic_year = :year AND feeding_subscriptions.term = :term";
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $recentFeedingSql .= ' AND ' . student_class_sql('students', 'feeding_subscriptions.academic_year') . ' = :class_level';
    $recentFeedingParams['class_level'] = $currentAdminClass;
}
$recentFeedingSql .= ' ORDER BY feeding_payments.payment_date DESC, feeding_payments.id DESC LIMIT 5';
$recentFeedingPaymentsStmt = $pdo->prepare($recentFeedingSql);
$recentFeedingPaymentsStmt->execute($recentFeedingParams);
$recentFeedingPayments = $recentFeedingPaymentsStmt->fetchAll();
$recentTransportParams = ['year' => $currentContext['academic_year'], 'term' => $currentContext['term']];
$recentTransportSql = "SELECT transport_payments.amount_paid, transport_payments.payment_date, transport_accounts.term, transport_accounts.academic_year, transport_students.student_name
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     LEFT JOIN students ON students.id = transport_students.student_id
     WHERE transport_accounts.academic_year = :year AND transport_accounts.term = :term";
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $recentTransportSql .= ' AND ' . student_class_sql('students', 'transport_accounts.academic_year') . ' = :class_level';
    $recentTransportParams['class_level'] = $currentAdminClass;
}
$recentTransportSql .= ' ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC LIMIT 5';
$recentTransportPaymentsStmt = $pdo->prepare($recentTransportSql);
$recentTransportPaymentsStmt->execute($recentTransportParams);
$recentTransportPayments = $recentTransportPaymentsStmt->fetchAll();

$recentFuelTransactions = [];
if (current_admin_has_permission($pdo, 'school_van_fuel.view_records')) {
    $recentFuelSql = 'SELECT ft.id, ft.fuel_date, ft.fuel_type, ft.total_amount, ft.receipt_no, v.vehicle_name
                      FROM fuel_transactions ft
                      LEFT JOIN vehicles v ON v.id = ft.vehicle_id';
    $recentFuelParams = [];
    if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
        $recentFuelSql .= ' WHERE ft.fuel_date BETWEEN :fuel_start_date AND :fuel_end_date';
        $recentFuelParams['fuel_start_date'] = $currentContext['start_date'];
        $recentFuelParams['fuel_end_date'] = $currentContext['end_date'];
    } else {
        $recentFuelSql .= ' WHERE YEAR(ft.fuel_date) = :fuel_activity_year';
        $recentFuelParams['fuel_activity_year'] = $currentContext['academic_year'];
    }
    $recentFuelSql .= ' ORDER BY ft.fuel_date DESC, ft.id DESC LIMIT 5';
    $recentFuelStatement = $pdo->prepare($recentFuelSql);
    $recentFuelStatement->execute($recentFuelParams);
    $recentFuelTransactions = $recentFuelStatement->fetchAll();
}

$recentActivities = [];
foreach ($recentFeePayments as $payment) {
    $recentActivities[] = [
        'title' => 'Fee payment from ' . $payment['full_name'],
        'detail' => $payment['receipt_no'] . ' - ' . $payment['term'] . ' ' . $payment['year'],
        'date' => $payment['payment_date'],
        'sort' => strtotime($payment['payment_date']) ?: 0,
        'amount' => money((float) $payment['amount_paid']),
        'icon' => 'fa-wallet',
        'tone' => 'activity-success',
        'url' => url('admin/receipt.php?id=' . $payment['id']),
    ];
}
foreach ($recentExpenses as $expense) {
    $recentActivities[] = [
        'title' => $expense['title'],
        'detail' => $expense['activity_type'] . ($expense['detail'] ? ' - ' . $expense['detail'] : ''),
        'date' => $expense['activity_date'],
        'sort' => strtotime($expense['activity_date']) ?: 0,
        'amount' => money((float) $expense['amount']),
        'icon' => 'fa-receipt',
        'tone' => 'activity-warning',
        'url' => '',
    ];
}
foreach ($recentDaily as $d) {
    $recentActivities[] = [
        'title' => 'Daily purchase: ' . $d['item_name'],
        'detail' => 'Daily',
        'date' => $d['purchase_date'],
        'sort' => strtotime($d['purchase_date']) ?: 0,
        'amount' => money((float) $d['amount']),
        'icon' => 'fa-utensils',
        'tone' => 'activity-info',
        'url' => '',
    ];
}
foreach ($recentFeedingPayments as $payment) {
    $recentActivities[] = [
        'title' => 'Feeding payment from ' . $payment['full_name'],
        'detail' => $payment['term'] . ' ' . $payment['academic_year'],
        'date' => $payment['payment_date'],
        'sort' => strtotime($payment['payment_date']) ?: 0,
        'amount' => money((float) $payment['amount_paid']),
        'icon' => 'fa-utensils',
        'tone' => 'activity-info',
        'url' => '',
    ];
}
foreach ($recentTransportPayments as $payment) {
    $recentActivities[] = [
        'title' => 'Transport payment from ' . $payment['student_name'],
        'detail' => $payment['term'] . ' ' . $payment['academic_year'],
        'date' => $payment['payment_date'],
        'sort' => strtotime($payment['payment_date']) ?: 0,
        'amount' => money((float) $payment['amount_paid']),
        'icon' => 'fa-bus',
        'tone' => 'activity-purple',
        'url' => '',
    ];
}
foreach ($recentFuelTransactions as $fuel) {
    $recentActivities[] = [
        'title' => 'Fuel purchase - ' . ($fuel['vehicle_name'] ?: 'Vehicle'),
        'detail' => trim(($fuel['fuel_type'] ?: 'Fuel') . ($fuel['receipt_no'] ? ' - ' . $fuel['receipt_no'] : '')),
        'date' => $fuel['fuel_date'],
        'sort' => strtotime($fuel['fuel_date']) ?: 0,
        'amount' => money((float) $fuel['total_amount']),
        'icon' => 'fa-gas-pump',
        'tone' => 'activity-warning',
        'url' => url('admin/fuel.php?search=' . urlencode((string) ($fuel['receipt_no'] ?: ($fuel['vehicle_name'] ?? '')))),
    ];
}
usort($recentActivities, fn($left, $right) => $right['sort'] <=> $left['sort']);
$recentActivities = array_slice($recentActivities, 0, 8);

$collectionRate = dashboard_percent($totalPaidForBalances, $totalRequiredFees);
$balanceRate = dashboard_percent($totalBalance, $totalRequiredFees);
$boysRate = dashboard_percent($totalBoys, $studentTotal);
$girlsRate = dashboard_percent($totalGirls, $studentTotal);
$feedingRate = dashboard_percent($totalFeedingStudents, $studentTotal);
$displayDate = dashboard_date_label($currentContext['today'] ?? date('Y-m-d'));

$dashboardStats = [
    [
        'label' => 'Total Students',
        'value' => (string) $studentTotal,
        'indicator' => ($studentTotal > 0 ? '100%' : '0%') . ' all-time roster',
        'icon' => 'fa-users',
        'theme' => 'stat-students',
        'href' => url('admin/students.php'),
        'money' => false,
    ],
    [
        'label' => 'Total Boys',
        'value' => (string) $totalBoys,
        'indicator' => $boysRate . '% of students',
        'icon' => 'fa-person',
        'theme' => 'stat-boys',
        'href' => url('admin/students.php?gender=Male'),
        'money' => false,
    ],
    [
        'label' => 'Total Girls',
        'value' => (string) $totalGirls,
        'indicator' => $girlsRate . '% of students',
        'icon' => 'fa-person-dress',
        'theme' => 'stat-girls',
        'href' => url('admin/students.php?gender=Female'),
        'money' => false,
    ],
    [
        'label' => 'Fees Collected',
        'value' => money($totalFeeCollected),
        'indicator' => $collectionRate . '% collected',
        'icon' => 'fa-sack-dollar',
        'theme' => 'stat-fees',
        'href' => url('admin/fees.php'),
        'money' => true,
    ],
    [
        'label' => 'Uniform Revenue',
        'value' => money($totalUniformCollected),
        'indicator' => 'Current term sales',
        'icon' => 'fa-shirt',
        'theme' => 'stat-uniform',
        'href' => url('admin/uniform_sales.php'),
        'money' => true,
    ],
    [
        'label' => 'Fee Balance',
        'value' => money($totalBalance),
        'indicator' => $balanceRate . '% remaining',
        'icon' => 'fa-scale-balanced',
        'theme' => 'stat-balance',
        'href' => url('admin/reports.php'),
        'money' => true,
    ],
    [
        'label' => 'Transport accounts',
        'value' => (string) $totalTransportStudents,
        'indicator' => 'This term',
        'icon' => 'fa-bus',
        'theme' => 'stat-transport',
        'href' => url('admin/transport.php'),
        'money' => false,
    ],
    [
        'label' => 'Feeding',
        'value' => (string) $totalFeedingStudents,
        'indicator' => $feedingRate . '% active',
        'icon' => 'fa-bowl-food',
        'theme' => 'stat-feeding',
        'href' => url('admin/feeding.php'),
        'money' => false,
    ],
    [
        'label' => 'Fuel Spend (Term)',
        'value' => money((float) $fuelExpenses),
        'indicator' => 'Term fuel cost',
        'icon' => 'fa-gas-pump',
        'theme' => 'stat-fuel',
        'href' => url('admin/fuel_reports.php'),
        'money' => true,
    ],
    
];

$kitchenAccessLevel = kitchen_current_access_level($pdo);
if ($kitchenAccessLevel !== 'none') {
    $stats[] = [
        'label' => 'Kitchen Stock',
        'value' => (string) count(kitchen_aggregated_summary($pdo, null, null, true)),
        'indicator' => 'Items tracked',
        'icon' => 'fa-kitchen-set',
        'theme' => 'stat-kitchen',
        'href' => url('admin/kitchen_stock_taking.php#stock-records'),
        'money' => false,
    ];
    $stats[] = [
        'label' => 'Kitchen Spend (This Month)',
        'value' => money(kitchen_expense_total($pdo, date('Y-m-01'), date('Y-m-d'))),
        'indicator' => 'Linked to School Expenses',
        'icon' => 'fa-utensils',
        'theme' => 'stat-expense',
        'href' => url('admin/kitchen_expenditure.php'),
        'money' => true,
    ];
    $lowStockItemsCount = count(low_stock_kitchen_items_all($pdo));
    $stats[] = [
        'label' => 'Low Stock Alerts',
        'value' => (string) $lowStockItemsCount,
        'indicator' => 'Items below minimum',
        'icon' => 'fa-exclamation-triangle',
        'theme' => $lowStockItemsCount > 0 ? 'stat-warning' : 'stat-info',
        'href' => url('admin/kitchen_stock_taking.php#stock-records'),
        'money' => false,
    ];
}

$quickActions = [
    ['label' => 'Add Student', 'icon' => 'fa-user-plus', 'href' => url('admin/student_form.php'), 'theme' => 'action-green'],
    ['label' => 'Record Payment', 'icon' => 'fa-wallet', 'href' => url('admin/fees.php'), 'theme' => 'action-blue'],
    ['label' => 'Add Expense', 'icon' => 'fa-receipt', 'href' => url('admin/school_expenses.php'), 'theme' => 'action-pink'],
    ['label' => 'Reports', 'icon' => 'fa-chart-pie', 'href' => url('admin/reports.php'), 'theme' => 'action-purple'],
    ['label' => 'Feeding', 'icon' => 'fa-utensils', 'href' => url('admin/feeding.php'), 'theme' => 'action-orange'],
];
if ($kitchenAccessLevel === 'manage') {
    $quickActions[] = ['label' => 'Stock Taking', 'icon' => 'fa-clipboard-check', 'href' => url('admin/kitchen_stock_taking.php#stock-taking'), 'theme' => 'action-amber'];
} elseif ($kitchenAccessLevel === 'view') {
    $quickActions[] = ['label' => 'Kitchen Expenditure', 'icon' => 'fa-money-bill-wave', 'href' => url('admin/kitchen_expenditure.php'), 'theme' => 'action-amber'];
}

$daycareSnapshot = null;
$daycareTablesReady = (bool) $pdo->query("SHOW TABLES LIKE 'daycare_children'")->fetchColumn();
if ($daycareTablesReady && current_admin_can_access_module($pdo, 'Daycare')) {
    $daycareSnapshot = [];
    if (current_admin_has_permission($pdo, 'daycare.children.view')) {
        $daycareSnapshot['children'] = (int) $pdo->query("SELECT COUNT(*) FROM daycare_children WHERE enrollment_status='Active'")->fetchColumn();
    }
    if (current_admin_has_permission($pdo, 'daycare.payments.view')) {
        $collected = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM daycare_payments WHERE payment_status='Recorded' AND DATE(payment_date) BETWEEN :start AND :end");
        $collected->execute(['start' => date('Y-m-01'), 'end' => date('Y-m-d')]);
        $daycareSnapshot['collected'] = (float) $collected->fetchColumn();
    }
    if (!$daycareSnapshot) $daycareSnapshot = null;
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title dashboard-title">
    <div class="welcome-copy">
        <p class="dashboard-greeting"><?= h(ucwords(time_of_day_greeting())) ?></p>
        <h1><?= h($_SESSION['admin_name'] ?? 'System Administrator') ?></h1>
        <p class="mb-0">Here's what's happening at Arise To Excel Academy today.</p>
    </div>
    <div class="title-actions">
        <span class="date-pill"><i class="fa-regular fa-calendar"></i>Today <?= h($displayDate) ?> | Active period <?= h($currentContext['academic_year'] . ' - ' . $currentContext['term']) ?></span>
        <a class="btn btn-primary" href="<?= url('admin/fees.php') ?>"><i class="fa-solid fa-plus"></i>Record Payment</a>
    </div>
</div>

<div class="dashboard-metrics">
    <?php foreach ($dashboardStats as $card): ?>
        <a class="metric-card dashboard-stat-card <?= h($card['theme']) ?>" href="<?= h($card['href']) ?>">
            <div class="stat-card-top">
                <span class="stat-icon"><i class="fa-solid <?= h($card['icon']) ?>"></i></span>
                <span class="stat-trend"><i class="fa-solid fa-arrow-trend-up"></i><?= h($card['indicator']) ?></span>
            </div>
            <span class="stat-content">
                <strong class="stat-value <?= $card['money'] ? 'stat-money' : '' ?>"><?= h($card['value']) ?></strong>
                <span class="stat-label"><?= h($card['label']) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>

<section class="panel dashboard-expected-panel" aria-labelledby="expected-collections-title">
    <div class="dashboard-expected-heading">
        <div>
            <h2 id="expected-collections-title"><i class="fa-solid fa-bullseye text-primary me-2"></i>Expected This Term</h2>
            <p class="panel-subtitle mb-0">All assessed amounts across school services for <?= h($currentContext['academic_year'] . ' - ' . $currentContext['term']) ?>.</p>
        </div>
        <div class="dashboard-expected-total">
            <span>Total expected</span>
            <strong><?= h(money($totalExpectedCollections)) ?></strong>
        </div>
    </div>
    <div class="dashboard-expected-breakdown">
        <?php foreach ($expectedCollections as $service => $amount): ?>
            <div class="dashboard-expected-item">
                <span><?= h($service) ?></span>
                <strong><?= h(money((float) $amount)) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2><i class="fa-solid fa-chart-line text-primary me-2"></i>Fee Overview</h2>
                <p class="panel-subtitle">Current term collections, balances, expenses, and net position.</p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/reports.php') ?>">Reports</a>
        </div>
        <div class="chart-summary-row">
            <div class="chart-summary"><span>Collected</span><strong><?= money($totalFeeCollected) ?></strong></div>
            <div class="chart-summary"><span>Balance</span><strong><?= money($totalBalance) ?></strong></div>
            <div class="chart-summary"><span>Expenses</span><strong><?= money($totalExpenses) ?></strong></div>
            <div class="chart-summary"><span>Net Position</span><strong><?= money($netPosition) ?></strong></div>
        </div>
        <div class="chart-wrap"><canvas id="feeOverviewChart" aria-label="Fee overview chart"></canvas></div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2><i class="fa-solid fa-bolt text-primary me-2"></i>Quick Actions</h2>
                <p class="panel-subtitle">Jump into the workflows used most often.</p>
            </div>
        </div>
        <div class="quick-actions-grid">
            <?php foreach ($quickActions as $action): ?>
                <a class="quick-action-card <?= h($action['theme']) ?>" href="<?= h($action['href']) ?>">
                    <span class="quick-action-icon"><i class="fa-solid <?= h($action['icon']) ?>"></i></span>
                    <span><?= h($action['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php if ($daycareSnapshot !== null): ?>
    <section class="panel mt-4" aria-labelledby="daycare-dashboard-title">
        <div class="panel-heading">
            <div>
                <h2 id="daycare-dashboard-title"><i class="fa-solid fa-baby text-primary me-2"></i>Daycare Overview</h2>
                <p class="panel-subtitle">Active child accounts and daycare collections this month.</p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/daycare.php') ?>">Open Daycare</a>
        </div>
        <div class="chart-summary-row">
            <?php if (isset($daycareSnapshot['children'])): ?>
                <div class="chart-summary"><span>Active Children</span><strong><?= number_format($daycareSnapshot['children']) ?></strong></div>
            <?php endif; ?>
            <?php if (isset($daycareSnapshot['collected'])): ?>
                <div class="chart-summary"><span>Collected This Month</span><strong><?= money($daycareSnapshot['collected']) ?></strong></div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<div class="row g-4 mt-1">
    <div class="col-xl-4 col-lg-6">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-users text-primary me-2"></i>Student Statistics</h2>
                    <p class="panel-subtitle">Gender distribution across the student roster.</p>
                </div>
            </div>
            <div class="chart-wrap compact-chart"><canvas id="studentStatsChart"></canvas></div>
        </section>
    </div>

    <div class="col-xl-4 col-lg-6">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-arrow-trend-up text-primary me-2"></i>Collection Trend</h2>
                    <p class="panel-subtitle">Monthly payment activity for the active term.</p>
                </div>
            </div>
            <div class="chart-wrap compact-chart"><canvas id="collectionTrendChart"></canvas></div>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="announcement-card">
            <div class="announcement-visual"><i class="fa-solid fa-bullhorn"></i></div>
            <h2>Term Operations Pulse</h2>
            <p>Keep fee follow-ups, feeding subscriptions, transport accounts, and expense approvals aligned with the active academic period.</p>
            <div class="announcement-meta">
                <span class="soft-chip"><?= h($currentContext['academic_year']) ?></span>
                <span class="soft-chip"><?= h($currentContext['term']) ?></span>
                <span class="soft-chip"><?= h($collectionRate) ?>% collected</span>
            </div>
        </section>
    </div>
</div>

<section class="panel mt-4">
    <div class="panel-heading">
        <div>
            <h2><i class="fa-solid fa-scale-balanced text-primary me-2"></i>Outstanding Fee Balances</h2>
            <p class="panel-subtitle">Highest current-term balances that may need follow-up.</p>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/reports.php') ?>">View Reports</a>
    </div>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Reg No</th>
                    <th>Student Name</th>
                    <th>Class Level</th>
                    <th>Enrollment</th>
                    <th>Academic Year</th>
                    <th>Term</th>
                    <th>Required Amount</th>
                    <th>Paid Amount</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($balances as $student): ?>
                    <tr class="<?= h(dashboard_balance_class((float) $student['balance'], (float) $student['required_amount'], (float) $student['paid_amount'])) ?>">
                        <td><?= h($student['registration_no']) ?></td>
                        <td><?= h($student['full_name']) ?></td>
                        <td><?= h($student['class_level']) ?></td>
                        <td><?= h($student['enrollment_status']) ?></td>
                        <td><?= h($student['academic_year']) ?></td>
                        <td><?= h($student['term']) ?></td>
                        <td><?= money((float) $student['required_amount']) ?></td>
                        <td><?= money((float) $student['paid_amount']) ?></td>
                        <td><strong><?= money((float) $student['balance']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$balances): ?>
                    <tr><td colspan="9">No outstanding fee balances found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="panel activity-panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-wave-square text-primary me-2"></i>Recent Activity</h2>
                    <p class="panel-subtitle">Latest fee, feeding, transport, and expense events.</p>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/reports.php') ?>">View All</a>
            </div>

            <?php if ($recentActivities): ?>
                <div class="activity-feed">
                    <?php foreach ($recentActivities as $activity): ?>
                        <div class="activity-item">
                            <span class="activity-icon <?= h($activity['tone']) ?>"><i class="fa-solid <?= h($activity['icon']) ?>"></i></span>
                            <div>
                                <strong><?= h($activity['title']) ?></strong>
                                <span><?= h($activity['detail']) ?> - <?= h(dashboard_date_label($activity['date'])) ?></span>
                            </div>
                            <?php if ($activity['url']): ?>
                                <a class="activity-amount" href="<?= h($activity['url']) ?>"><?= h($activity['amount']) ?></a>
                            <?php else: ?>
                                <strong class="activity-amount"><?= h($activity['amount']) ?></strong>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div>
                        <span class="empty-state-illustration"><i class="fa-regular fa-clipboard"></i></span>
                        <strong>No recent activity</strong>
                        <span>Activities will appear here once records are added.</span>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="panel">
            <div class="panel-heading">
                <h2><i class="fa-solid fa-chart-simple text-primary me-2"></i>Operations Snapshot</h2>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Required Fees</span><strong><?= money($totalRequiredFees) ?></strong></div>
                </div>
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Paid Against Balances</span><strong><?= money($totalPaidForBalances) ?></strong></div>
                </div>
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Feeding Payments</span><strong><?= money($feedingPayments) ?></strong></div>
                </div>
                <div class="col-sm-6 col-xl-12">
                    <div class="mini-stat"><span>Transport Payments</span><strong><?= money($transportPayments) ?></strong></div>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
window.dashboardCharts = {
    feeOverview: {
        labels: ['Collected', 'Balance', 'Expenses', 'Net'],
        values: <?= json_encode([$totalFeeCollected, $totalBalance, $totalExpenses, $netPosition]) ?>
    },
    studentStats: {
        labels: ['Boys', 'Girls'],
        values: <?= json_encode([$totalBoys, $totalGirls]) ?>
    },
    collectionTrend: {
        labels: <?= json_encode($chartLabels) ?>,
        values: <?= json_encode($chartTotals) ?>
    },
    uniformTrend: {
        labels: <?= json_encode($uniformChartLabels) ?>,
        values: <?= json_encode($uniformChartTotals) ?>
    },
    fuelTrend: {
        labels: <?= json_encode($fuelChartLabels) ?>,
        values: <?= json_encode($fuelChartTotals) ?>
    }
};
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
