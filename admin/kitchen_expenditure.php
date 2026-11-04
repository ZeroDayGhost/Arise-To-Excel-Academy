<?php
define('KITCHEN_EXPENDITURE_PAGE', true);
require __DIR__ . '/kitchen.php';
exit;
ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);
$period = in_array($_GET['period'] ?? '', ['daily', 'weekly', 'monthly', 'term', 'year'], true) ? $_GET['period'] : 'monthly';
$anchorDate = valid_date_value($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$academicYear = preg_match('/^\d{4}$/', (string) ($_GET['academic_year'] ?? '')) ? (string) $_GET['academic_year'] : (string) $currentContext['academic_year'];
$term = in_array($_GET['term'] ?? '', term_options(), true) ? (string) $_GET['term'] : (string) $currentContext['term'];
$periodError = '';

switch ($period) {
    case 'daily':
        $dateFrom = $anchorDate;
        $dateTo = $anchorDate;
        $periodLabel = date('j M Y', strtotime($dateFrom));
        break;
    case 'weekly':
        $weekStart = new DateTimeImmutable($anchorDate);
        $dateFrom = $weekStart->modify('monday this week')->format('Y-m-d');
        $dateTo = (new DateTimeImmutable($dateFrom))->modify('+6 days')->format('Y-m-d');
        $periodLabel = date('j M', strtotime($dateFrom)) . ' - ' . date('j M Y', strtotime($dateTo));
        break;
    case 'term':
        $calendarStmt = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
        $calendarStmt->execute(['year' => $academicYear, 'term' => $term]);
        $termDates = $calendarStmt->fetch(PDO::FETCH_ASSOC);
        if ($termDates) {
            $dateFrom = (string) $termDates['start_date'];
            $dateTo = (string) $termDates['end_date'];
            $periodLabel = $academicYear . ' - ' . $term;
        } else {
            $dateFrom = '1000-01-01';
            $dateTo = '1000-01-01';
            $periodLabel = $academicYear . ' - ' . $term;
            $periodError = 'That academic term has no dates configured in the school calendar.';
        }
        break;
    case 'year':
        $calendarStmt = $pdo->prepare('SELECT MIN(start_date), MAX(end_date) FROM academic_calendar WHERE academic_year = :year');
        $calendarStmt->execute(['year' => $academicYear]);
        $calendarBounds = $calendarStmt->fetch(PDO::FETCH_NUM);
        $dateFrom = !empty($calendarBounds[0]) ? (string) $calendarBounds[0] : $academicYear . '-01-01';
        $dateTo = !empty($calendarBounds[1]) ? (string) $calendarBounds[1] : $academicYear . '-12-31';
        $periodLabel = 'Academic year ' . $academicYear;
        break;
    default:
        $monthStart = new DateTimeImmutable(date('Y-m-01', strtotime($anchorDate)));
        $dateFrom = $monthStart->format('Y-m-d');
        $dateTo = $monthStart->modify('last day of this month')->format('Y-m-d');
        $periodLabel = $monthStart->format('F Y');
}

$total = kitchen_expense_total($pdo, $dateFrom, $dateTo);
$categoryStmt = $pdo->prepare(
    'SELECT category, COALESCE(SUM(total_amount), 0) AS total
     FROM kitchen_purchase_records
     WHERE purchase_date BETWEEN :date_from AND :date_to
     GROUP BY category'
);
$categoryStmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$newCategoryTotals = [];
foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $newCategoryTotals[(string) $row['category']] = (float) $row['total'];
}
$legacyGasStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :date_from AND :date_to");
$legacyGasStmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$foodTotal = $newCategoryTotals['Food'] ?? 0.0;
$gasTotal = ($newCategoryTotals['Gas'] ?? 0.0) + (float) $legacyGasStmt->fetchColumn();
$cookingTotal = $newCategoryTotals['Cooking Supplies'] ?? 0.0;
$otherTotal = max(0.0, $total - $foodTotal - $gasTotal - $cookingTotal);

$expensesStmt = $pdo->prepare(
    "SELECT e.id, e.item_name, e.category AS expense_category, e.quantity, e.unit, e.amount, e.total_amount, e.expense_date,
            e.description, kp.category AS kitchen_category, a.name AS recorded_by
     FROM school_expenses e
     LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id
     LEFT JOIN admin a ON a.id = kp.recorded_by
     WHERE e.expense_date BETWEEN :date_from AND :date_to
       AND (e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill') OR e.category LIKE 'Kitchen %')
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 200"
);
$expensesStmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);
$yearsStmt = $pdo->query(
    "SELECT CAST(academic_year AS CHAR) AS year_value FROM academic_calendar WHERE academic_year REGEXP '^[0-9]{4}$'
     UNION SELECT CAST(YEAR(expense_date) AS CHAR) FROM school_expenses
     ORDER BY year_value DESC"
);
$years = array_values(array_unique(array_merge([(string) $currentContext['academic_year']], array_map('strval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN)))));
$terms = term_options();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Kitchen Accountability</p><h1>Kitchen Expenditure</h1><p class="text-muted mb-0">All kitchen purchases are linked to School Expenses and summarized here once.</p></div>
</div>

<nav class="kitchen-module-nav panel mb-4" aria-label="Kitchen pages">
    <a href="<?= url('admin/kitchen_stock_taking.php') ?>"><i class="fa-solid fa-clipboard-check"></i> Stock Taking</a>
    <a href="<?= url('admin/kitchen_stock_taking.php#stock-records') ?>"><i class="fa-solid fa-boxes-stacked"></i> Kitchen Stock Records</a>
    <a class="active" href="<?= url('admin/kitchen_expenditure.php') ?>"><i class="fa-solid fa-money-bill-wave"></i> Kitchen Expenditure</a>
</nav>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($periodError !== ''): ?><div class="alert alert-warning"><?= h($periodError) ?></div><?php endif; ?>

<section class="panel mb-4">
    <form method="get" class="row g-3 align-items-end kitchen-expenditure-filters">
        <div class="col-sm-6 col-lg-3"><label class="form-label" for="period">Period</label><select class="form-select" id="period" name="period">
            <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'term' => 'Term', 'year' => 'Academic year'] as $key => $label): ?><option value="<?= h($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-sm-6 col-lg-3" data-filter="date"><label class="form-label" for="date">Date to summarize</label><input class="form-control" id="date" name="date" type="date" value="<?= h($anchorDate) ?>"></div>
        <div class="col-sm-6 col-lg-2" data-filter="academic"><label class="form-label" for="academic_year">Academic year</label><select class="form-select" id="academic_year" name="academic_year"><?php foreach ($years as $yearOption): ?><option value="<?= h($yearOption) ?>" <?= $academicYear === $yearOption ? 'selected' : '' ?>><?= h($yearOption) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2" data-filter="term"><label class="form-label" for="term">Term</label><select class="form-select" id="term" name="term"><?php foreach ($terms as $termOption): ?><option value="<?= h($termOption) ?>" <?= $term === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply</button></div>
    </form>
</section>

<section class="kitchen-expenditure-total mb-4">
    <span>Total kitchen expenditure</span><strong><?= money($total) ?></strong><small><?= h($periodLabel) ?></small>
</section>

<div class="row g-3 mb-4">
    <?php foreach ([['Food', $foodTotal, 'stat-feeding'], ['Gas', $gasTotal, 'stat-fuel'], ['Cooking supplies', $cookingTotal, 'stat-food'], ['Other', $otherTotal, 'stat-expense']] as [$label, $amount, $theme]): ?>
        <div class="col-6 col-xl-3"><section class="dashboard-stat-card metric-card <?= h($theme) ?>"><div class="stat-label"><?= h($label) ?></div><div class="stat-value"><?= money((float) $amount) ?></div></section></div>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-heading"><h2>Kitchen Expense Records</h2><p class="text-muted mb-0">Legacy kitchen and gas entries are retained. Older entries without a recorded subtype are included under Other.</p></div>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Date</th><th>Item</th><th>Category</th><th>Quantity</th><th>Unit price</th><th>Total spent</th><th>Entry</th></tr></thead>
        <tbody><?php foreach ($expenses as $expense):
            $categoryLabel = $expense['kitchen_category'] ?: ((string) $expense['expense_category'] === 'Gas Refill' ? 'Gas' : 'Other (legacy)');
            $entryLabel = str_contains((string) $expense['description'], 'Purchase #') ? 'Kitchen stock taking' : 'Historical expense';
        ?>
            <tr><td><?= h($expense['expense_date']) ?></td><td><?= h($expense['item_name']) ?></td><td><?= h($categoryLabel) ?></td>
                <td><?= number_format((float) $expense['quantity'], 2) ?> <?= h($expense['unit'] ?? '') ?></td>
                <td><?= money((float) $expense['amount']) ?></td><td><strong><?= money((float) $expense['total_amount']) ?></strong></td><td><?= h($entryLabel) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$expenses): ?><tr><td colspan="7" class="text-muted">No kitchen expenses were found for this period.</td></tr><?php endif; ?></tbody>
    </table></div>
</section>

<script>
(() => {
    const period = document.getElementById('period');
    const dateField = document.querySelector('[data-filter="date"]');
    const yearField = document.querySelector('[data-filter="academic"]');
    const termField = document.querySelector('[data-filter="term"]');
    const update = () => {
        const value = period.value;
        dateField.hidden = value === 'term' || value === 'year';
        yearField.hidden = value !== 'term' && value !== 'year';
        termField.hidden = value !== 'term';
    };
    period.addEventListener('change', update);
    update();
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
