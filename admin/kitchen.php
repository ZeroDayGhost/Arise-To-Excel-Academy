<?php
$isExpenditurePage = defined('KITCHEN_EXPENDITURE_PAGE') && KITCHEN_EXPENDITURE_PAGE === true;
$pageTitle = $isExpenditurePage ? 'Kitchen Expenditure' : 'Kitchen Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
$canSeeKitchenRecorder = rbac_user_is_administrator($pdo, (int) ($_SESSION['admin_id'] ?? 0));
$canManageKitchen = kitchen_current_access_level($pdo) === 'manage';
$canExportKitchen = rbac_current_admin_has_permission($pdo, 'kitchen.export');
$canViewKitchenExpenditure = rbac_current_admin_has_permission($pdo, 'kitchen.expenditure.view');
ensure_kitchen_tables($pdo);
$customKitchenCategories = $pdo->query("SELECT category_name FROM kitchen_expense_categories WHERE category_name NOT IN ('Food', 'Gas', 'Other') ORDER BY category_name")->fetchAll(PDO::FETCH_COLUMN);
$currentContext = current_academic_context($pdo);
$entryToken = $_SESSION['kitchen_expense_request_token'] ?? '';
if ($entryToken === '') {
    $entryToken = bin2hex(random_bytes(32));
    $_SESSION['kitchen_expense_request_token'] = $entryToken;
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kitchen_require_access($pdo, true);
    try {
        if (!hash_equals($entryToken, trim((string) ($_POST['request_token'] ?? '')))) {
            throw new InvalidArgumentException('This entry form has already been submitted. Refresh before recording another expense.');
        }
        if (($_POST['unit'] ?? '') === '__other__' && trim((string) ($_POST['unit_custom'] ?? '')) === '') {
            throw new InvalidArgumentException('Enter the custom unit you selected.');
        }
        $saved = kitchen_record_expense($pdo, [
            'request_token' => $entryToken,
            'item_name' => (string) ($_POST['item_name'] ?? ''),
            'category' => (string) ($_POST['category'] ?? ''),
            'quantity' => (string) ($_POST['quantity'] ?? ''),
            'unit' => (string) (($_POST['unit'] ?? '') === '__other__' ? ($_POST['unit_custom'] ?? '') : ($_POST['unit'] ?? '')),
            'unit_price' => (string) ($_POST['unit_price'] ?? ''),
            'total_amount' => (string) ($_POST['total_amount'] ?? ''),
            'purchase_date' => (string) ($_POST['purchase_date'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
        ], (int) ($_SESSION['admin_id'] ?? 0));
        $_SESSION['kitchen_expense_request_token'] = bin2hex(random_bytes(32));
        flash('success', $saved['created'] ? 'Kitchen expense recorded.' : 'This entry was already saved; no duplicate was added.');
        redirect('admin/kitchen_expenditure.php');
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Kitchen expense save failed: ' . $exception->getMessage());
        $errors[] = 'The kitchen expense could not be saved. No partial record was kept.';
    }
}

$year = preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? '')) ? (string) $_GET['year'] : (string) $currentContext['academic_year'];
$termOptions = term_options();
$term = array_key_exists('term', $_GET) && in_array((string) $_GET['term'], $termOptions, true)
    ? (string) $_GET['term']
    : (array_key_exists('term', $_GET) ? '' : (string) $currentContext['term']);
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : '';
$categoryFilter = in_array((string) ($_GET['category'] ?? ''), ['Food', 'Gas', 'Other'], true) ? (string) $_GET['category'] : '';
$legacyStart = $year . '-01-01';
$legacyEnd = $year . '-12-31';
$periodWarning = '';
if ($term !== '') {
    $calendarStmt = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
    $calendarStmt->execute(['year' => $year, 'term' => $term]);
    $termDates = $calendarStmt->fetch(PDO::FETCH_ASSOC);
    if ($termDates) {
        $legacyStart = (string) $termDates['start_date'];
        $legacyEnd = (string) $termDates['end_date'];
    } else {
        $legacyStart = $legacyEnd = '1000-01-01';
        $periodWarning = 'The selected term has no calendar dates. New entries still use their saved academic period; older entries without period details cannot be matched to this term.';
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
$baseSql = "(e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill') OR e.category LIKE 'Kitchen %')";
$makeFilters = static function (string $filterYear, string $filterTerm, string $filterMonth, string $filterCategory, string $legacyFrom, string $legacyTo) use ($categorySql, $baseSql): array {
    $where = [$baseSql];
    $params = [];
    if ($filterYear !== '' && $filterTerm !== '') {
        $where[] = '((kp.id IS NOT NULL AND kp.academic_year = :period_year AND kp.term = :period_term) OR (kp.id IS NULL AND e.expense_date BETWEEN :legacy_start AND :legacy_end))';
        $params['period_year'] = $filterYear;
        $params['period_term'] = $filterTerm;
        $params['legacy_start'] = $legacyFrom;
        $params['legacy_end'] = $legacyTo;
    } elseif ($filterYear !== '') {
        $where[] = '((kp.id IS NOT NULL AND kp.academic_year = :period_year) OR (kp.id IS NULL AND YEAR(e.expense_date) = :legacy_year))';
        $params['period_year'] = $filterYear;
        $params['legacy_year'] = $filterYear;
    }
    if ($filterMonth !== '') {
        $monthStart = $filterMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $where[] = 'e.expense_date BETWEEN :month_start AND :month_end';
        $params['month_start'] = $monthStart;
        $params['month_end'] = $monthEnd;
    }
    if ($filterCategory !== '') {
        $where[] = "({$categorySql}) = :category_filter";
        $params['category_filter'] = $filterCategory;
    }
    return [' WHERE ' . implode(' AND ', $where), $params];
};
$getTotals = static function (string $filterYear, string $filterTerm, string $filterMonth, string $filterCategory, string $legacyFrom, string $legacyTo) use ($pdo, $makeFilters, $categorySql): array {
    [$where, $params] = $makeFilters($filterYear, $filterTerm, $filterMonth, $filterCategory, $legacyFrom, $legacyTo);
    $stmt = $pdo->prepare("SELECT {$categorySql} AS report_category, COALESCE(SUM(e.total_amount), 0) AS total FROM school_expenses e LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id{$where} GROUP BY report_category");
    $stmt->execute($params);
    $totals = ['Food' => 0.0, 'Gas' => 0.0, 'Other' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = in_array($row['report_category'], ['Food', 'Gas', 'Other'], true) ? $row['report_category'] : 'Other';
        $totals[$key] += (float) $row['total'];
    }
    return $totals;
};

$activeLegacyStart = (string) ($currentContext['start_date'] ?? '');
$activeLegacyEnd = (string) ($currentContext['end_date'] ?? '');
if ($activeLegacyStart === '' || $activeLegacyEnd === '') {
    $activeCalendarStmt = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
    $activeCalendarStmt->execute(['year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
    $activeCalendar = $activeCalendarStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $activeLegacyStart = (string) ($activeCalendar['start_date'] ?? '1000-01-01');
    $activeLegacyEnd = (string) ($activeCalendar['end_date'] ?? '1000-01-01');
}
$monthNow = date('Y-m');
$currentMonthTotals = $getTotals('', '', $monthNow, '', '', '');
$currentTermTotals = $getTotals((string) $currentContext['academic_year'], (string) $currentContext['term'], '', '', $activeLegacyStart, $activeLegacyEnd);
$currentYearTotals = $getTotals((string) $currentContext['academic_year'], '', '', '', '', '');
$selectedTotals = $getTotals($year, $term, $month, $categoryFilter, $legacyStart, $legacyEnd);
$selectedTotal = array_sum($selectedTotals);
$selectedPeriodLabel = $month !== ''
    ? date('F Y', strtotime($month . '-01'))
    : ($term !== '' ? $year . ' - ' . $term : 'Academic year ' . $year);
[$recordWhere, $recordParams] = $makeFilters($year, $term, $month, $categoryFilter, $legacyStart, $legacyEnd);
$recordedBySelect = $canSeeKitchenRecorder ? 'a.name AS recorded_by' : 'NULL AS recorded_by';
$recordsStmt = $pdo->prepare(
    "SELECT e.expense_date, e.item_name,
            CASE WHEN kp.id IS NOT NULL THEN kp.quantity ELSE e.quantity END AS quantity,
            CASE WHEN kp.id IS NOT NULL THEN kp.unit ELSE e.unit END AS unit,
            CASE WHEN kp.id IS NOT NULL THEN kp.unit_price ELSE e.amount END AS amount,
            e.total_amount, e.description,
            e.category AS expense_category, kp.category AS kitchen_category, kp.academic_year, kp.term,
            {$recordedBySelect}
     FROM school_expenses e
     LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id
     LEFT JOIN admin a ON a.id = kp.recorded_by
     {$recordWhere}
     ORDER BY e.expense_date DESC, e.id DESC LIMIT 500"
);
$recordsStmt->execute($recordParams);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM school_expenses e LEFT JOIN kitchen_purchase_records kp ON kp.school_expense_id = e.id{$recordWhere}");
$countStmt->execute($recordParams);
$recordCount = (int) $countStmt->fetchColumn();

$yearsStmt = $pdo->query(
    "SELECT academic_year AS year_value FROM kitchen_purchase_records WHERE academic_year REGEXP '^[0-9]{4}$'
     UNION SELECT CAST(YEAR(expense_date) AS CHAR) FROM school_expenses
     WHERE category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill') OR category LIKE 'Kitchen %'
     ORDER BY year_value DESC"
);
$years = array_values(array_unique(array_merge([(string) $currentContext['academic_year']], array_map('strval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN)))));
$selectedUnit = (string) ($_POST['unit'] ?? '');
$kitchenExportFilters = ['year' => $year, 'term' => $term, 'month' => $month, 'category' => $categoryFilter];
$kitchenExcelExportUrl = url('admin/kitchen_export.php?' . http_build_query(['format' => 'excel'] + $kitchenExportFilters));
$kitchenPdfExportUrl = url('admin/kitchen_export.php?' . http_build_query(['format' => 'pdf'] + $kitchenExportFilters));
$kitchenUnitGroups = [
    'Weight' => ['kg' => 'Kilograms (kg)', 'g' => 'Grams (g)', 'mg' => 'Milligrams (mg)', 'tonne' => 'Tonnes', 'lb' => 'Pounds (lb)', 'oz' => 'Ounces (oz)'],
    'Volume' => ['litre' => 'Litres (L)', 'ml' => 'Millilitres (ml)', 'gallon' => 'Gallons', 'quart' => 'Quarts', 'pint' => 'Pints', 'cup' => 'Cups'],
    'Count and packaging' => ['piece' => 'Pieces', 'dozen' => 'Dozen', 'pair' => 'Pairs', 'bunch' => 'Bunches', 'head' => 'Heads', 'bag' => 'Bags', 'sack' => 'Sacks', 'packet' => 'Packets', 'carton' => 'Cartons', 'crate' => 'Crates', 'bottle' => 'Bottles', 'tin' => 'Tins', 'can' => 'Cans', 'box' => 'Boxes', 'tray' => 'Trays', 'roll' => 'Rolls', 'bundle' => 'Bundles', 'loaf' => 'Loaves'],
];

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div class="kitchen-page-title-main"><span class="kitchen-page-title-icon" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span><div><p class="eyebrow">Kitchen Spending</p><h1><?= $isExpenditurePage ? 'Kitchen Expenditure' : 'Kitchen Dashboard' ?></h1><p class="text-muted mb-0"><?= $isExpenditurePage ? 'Review kitchen spending by academic year, term, month, and category.' : 'Record food, gas, and other kitchen expenses for the active school period.' ?></p></div></div>
    <?php if ($isExpenditurePage): ?>
        <div class="kitchen-expenditure-actions d-flex align-items-center justify-content-end flex-wrap">
            <?php if (rbac_current_admin_has_permission($pdo, 'kitchen.view')): ?><a class="btn btn-outline-secondary" href="<?= url('admin/kitchen.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Kitchen Dashboard</a><?php endif; ?>
            <?php if ($canExportKitchen): ?><button class="btn btn-outline-primary" type="button" id="openKitchenExport" aria-haspopup="dialog" aria-controls="kitchenExportDialog"><i class="fa-solid fa-download me-2"></i>Export</button><?php endif; ?>
            <?php if ($canManageKitchen): ?><a class="btn btn-primary" href="<?= url('admin/kitchen.php#kitchen-expense-form') ?>"><i class="fa-solid fa-plus me-2"></i>Add Kitchen Entry</a><?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($canViewKitchenExpenditure): ?><a class="btn btn-outline-primary" href="<?= url('admin/kitchen_expenditure.php') ?>"><i class="fa-solid fa-chart-column me-2"></i>Kitchen Expenditure</a><?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($isExpenditurePage && $canExportKitchen): ?>
<dialog class="fee-export-dialog kitchen-export-dialog" id="kitchenExportDialog" aria-labelledby="kitchenExportDialogTitle">
    <header class="fee-export-header">
        <h2 id="kitchenExportDialogTitle">Export kitchen expenditure</h2>
        <button type="button" class="fee-export-close" data-close-kitchen-export aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </header>
    <div class="fee-export-body">
        <p>Choose a format for the currently filtered expenditure report.</p>
        <div class="kitchen-export-options">
            <a class="kitchen-export-option" href="<?= h($kitchenExcelExportUrl) ?>">
                <span class="kitchen-export-option-icon kitchen-export-option-icon--excel"><i class="fa-solid fa-file-excel" aria-hidden="true"></i></span>
                <span><strong>Excel workbook</strong><small>.xls spreadsheet</small></span>
            </a>
            <a class="kitchen-export-option" href="<?= h($kitchenPdfExportUrl) ?>">
                <span class="kitchen-export-option-icon kitchen-export-option-icon--pdf"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></span>
                <span><strong>PDF report</strong><small>Print-ready summary and records</small></span>
            </a>
        </div>
    </div>
    <footer class="fee-export-actions"><button type="button" class="btn btn-outline-secondary" data-close-kitchen-export>Cancel</button></footer>
</dialog>
<script>
    (() => {
        const dialog = document.getElementById('kitchenExportDialog');
        const openButton = document.getElementById('openKitchenExport');
        if (!dialog || !openButton || typeof dialog.showModal !== 'function') return;
        openButton.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-close-kitchen-export]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    })();
</script>
<?php endif; ?>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($periodWarning !== ''): ?><div class="alert alert-warning"><?= h($periodWarning) ?></div><?php endif; ?>

<?php if (!$isExpenditurePage): ?>
<div class="row g-3 mb-4">
    <?php foreach ([['This month', array_sum($currentMonthTotals), $monthNow, 'fa-calendar-day'], ['Current term', array_sum($currentTermTotals), $currentContext['academic_year'] . ' - ' . $currentContext['term'], 'fa-calendar-check'], ['This academic year', array_sum($currentYearTotals), $currentContext['academic_year'], 'fa-calendar']] as [$label, $total, $hint, $icon]): ?>
        <div class="col-md-4"><section class="dashboard-stat-card kitchen-overview-metric"><div class="kitchen-overview-metric-icon"><i class="fa-solid <?= h($icon) ?>"></i></div><div class="stat-label"><?= h($label) ?></div><div class="stat-value"><?= 'KSh ' . number_format((float) $total, 2) ?></div><div class="small text-muted"><?= h((string) $hint) ?></div></section></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$isExpenditurePage): ?>
<div class="row g-4 mb-4">
    <?php if ($canManageKitchen): ?>
    <div class="col-12">
        <section class="panel kitchen-entry-panel">
            <div class="panel-heading kitchen-entry-heading"><span class="kitchen-entry-heading-icon"><i class="fa-solid fa-basket-shopping"></i></span><div><h2>Add kitchen expense</h2><p class="text-muted mb-0">New entries will be recorded under <?= h($currentContext['academic_year'] . ' - ' . $currentContext['term']) ?>.</p></div></div>
            <form method="post" class="row g-3 kitchen-entry-form" id="kitchen-expense-form">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="request_token" value="<?= h($entryToken) ?>">
                <div class="col-md-7"><label class="form-label" for="item_name">Item name <span class="text-danger">*</span></label><input class="form-control" id="item_name" name="item_name" maxlength="120" required value="<?= h($_POST['item_name'] ?? '') ?>" placeholder="e.g. Maize flour, rice, cooking gas"></div>
                <div class="col-md-5"><label class="form-label" for="category">Category <span class="text-danger">*</span></label><select class="form-select" id="category" name="category" required><option value="">Choose category</option><?php foreach (['Food', 'Gas'] as $option): ?><option value="<?= h($option) ?>" <?= ($_POST['category'] ?? '') === $option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?><?php foreach ($customKitchenCategories as $option): ?><option value="<?= h((string) $option) ?>" <?= ($_POST['category'] ?? '') === $option ? 'selected' : '' ?>><?= h((string) $option) ?></option><?php endforeach; ?><option value="__add_category__" <?= ($_POST['category'] ?? '') === '__add_category__' ? 'selected' : '' ?>>Other (add a category)</option></select></div>
                <div class="col-md-5" id="kitchen-custom-category-wrap" <?= ($_POST['category'] ?? '') === '__add_category__' ? '' : 'hidden' ?>><label class="form-label" for="custom_category">New category name <span class="text-danger">*</span></label><input class="form-control" id="custom_category" name="custom_category" maxlength="40" value="<?= h($_POST['custom_category'] ?? '') ?>" placeholder="e.g. Cooking supplies"><div class="form-text">Saved for future kitchen entries.</div></div>
                <div class="col-sm-4"><label class="form-label" for="quantity">Quantity <span class="text-muted">(optional)</span></label><input class="form-control" id="quantity" name="quantity" type="number" min="0.01" step="0.01" value="<?= h($_POST['quantity'] ?? '') ?>" placeholder="e.g. 10"></div>
                <div class="col-sm-4">
                    <label class="form-label" for="unit">Unit <span class="text-muted">(optional)</span></label>
                    <select class="form-select" id="unit" name="unit">
                        <option value="">Choose unit</option>
                        <?php foreach ($kitchenUnitGroups as $groupLabel => $groupUnits): ?><optgroup label="<?= h($groupLabel) ?>"><?php foreach ($groupUnits as $unitValue => $unitLabel): ?><option value="<?= h($unitValue) ?>" <?= $selectedUnit === $unitValue ? 'selected' : '' ?>><?= h($unitLabel) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                        <option value="__other__" <?= $selectedUnit === '__other__' ? 'selected' : '' ?>>Other unit...</option>
                    </select>
                    <div class="kitchen-custom-unit-wrap mt-2" id="kitchen-custom-unit-wrap" <?= $selectedUnit === '__other__' ? '' : 'hidden' ?>><label class="form-label" for="unit_custom">Custom unit</label><input class="form-control" id="unit_custom" name="unit_custom" maxlength="30" value="<?= h($_POST['unit_custom'] ?? '') ?>" placeholder="Enter a unit"></div>
                </div>
                <div class="col-sm-4"><label class="form-label" for="unit_price">Unit price (KSh) <span class="text-muted">(optional)</span></label><input class="form-control" id="unit_price" name="unit_price" type="number" min="0.01" step="0.01" value="<?= h($_POST['unit_price'] ?? '') ?>" placeholder="0.00"></div>
                <div class="col-lg-6"><label class="form-label" for="total_amount">Total amount (KSh) <span class="text-danger">*</span></label><input class="form-control" id="total_amount" name="total_amount" type="number" min="0.01" step="0.01" required value="<?= h($_POST['total_amount'] ?? '') ?>" placeholder="Auto-calculated or enter amount"><div class="form-text">Calculated from quantity × unit price when both are entered. You can also enter the total directly.</div></div>
                <div class="col-lg-6"><label class="form-label" for="purchase_date">Purchase date <span class="text-danger">*</span></label><input class="form-control" id="purchase_date" name="purchase_date" type="date" required value="<?= h($_POST['purchase_date'] ?? date('Y-m-d')) ?>" data-clear-value="<?= h(date('Y-m-d')) ?>"></div>
                <div class="col-12"><label class="form-label" for="description">Notes <span class="text-muted">(optional)</span></label><textarea class="form-control" id="description" name="description" maxlength="2000" rows="2" placeholder="Add any useful details about this expense"><?= h($_POST['description'] ?? '') ?></textarea></div>
                <div class="col-12 d-flex flex-wrap gap-2 kitchen-entry-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save expense</button><button class="btn btn-outline-secondary" type="button" id="kitchen-clear-form"><i class="fa-solid fa-rotate-left me-2"></i>Clear form</button></div>
            </form>
        </section>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isExpenditurePage): ?>
<div class="kitchen-expenditure-page">
    <section class="panel kitchen-expenditure-filter-panel mb-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-sm-6 col-xl-3"><label class="form-label" for="year">Academic year</label><select class="form-select" id="year" name="year"><?php foreach ($years as $yearOption): ?><option value="<?= h($yearOption) ?>" <?= $year === $yearOption ? 'selected' : '' ?>><?= h($yearOption) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6 col-xl-3"><label class="form-label" for="term">Term</label><select class="form-select" id="term" name="term"><option value="">All terms</option><?php foreach ($termOptions as $termOption): ?><option value="<?= h($termOption) ?>" <?= $term === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6 col-xl-3"><label class="form-label" for="month">Month</label><input class="form-control" id="month" name="month" type="month" value="<?= h($month) ?>"></div>
            <div class="col-sm-6 col-xl-2"><label class="form-label" for="filter_category">Category</label><select class="form-select" id="filter_category" name="category"><option value="">All categories</option><?php foreach (['Food', 'Gas', 'Other'] as $option): ?><option value="<?= h($option) ?>" <?= $categoryFilter === $option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></div>
            <div class="col-xl-1"><button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button></div>
            <div class="col-12 d-flex justify-content-between align-items-center"><small class="text-muted">Leave month blank to include the full selected period.</small><a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/kitchen_expenditure.php') ?>">Clear filters</a></div>
        </form>
    </section>

    <?php
    $expenditureCards = [
        ['Food', $selectedTotals['Food'], 'fa-utensils', 'food'],
        ['Gas', $selectedTotals['Gas'], 'fa-fire', 'gas'],
        ['Other', $selectedTotals['Other'], 'fa-box-open', 'other'],
        ['Total kitchen expenditure', $selectedTotal, 'fa-coins', 'total'],
    ];
    ?>
    <div class="row g-3 mb-4 kitchen-expenditure-metrics">
        <?php foreach ($expenditureCards as [$label, $amount, $icon, $tone]): ?>
            <div class="col-6 col-xl-3"><section class="dashboard-stat-card kitchen-expenditure-stat kitchen-expenditure-stat--<?= h($tone) ?>">
                <div class="kitchen-expenditure-stat-heading"><span class="stat-label"><?= h($label) ?></span><span class="kitchen-expenditure-stat-icon" aria-hidden="true"><i class="fa-solid <?= h($icon) ?>"></i></span></div>
                <strong class="stat-value"><?= 'KSh ' . number_format((float) $amount, 2) ?></strong>
                <small class="text-muted"><?= h($selectedPeriodLabel) ?></small>
            </section></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-8"><section class="panel h-100">
            <div class="panel-heading"><h2>Expenditure by category</h2><p class="text-muted mb-0"><?= h($selectedPeriodLabel) ?></p></div>
            <div class="kitchen-expenditure-chart-wrap"><canvas id="kitchenExpenseCategoryChart" aria-label="Kitchen expenditure by category" role="img"></canvas></div>
        </section></div>
        <div class="col-12 col-xl-4"><section class="panel h-100">
            <div class="panel-heading"><h2>Quick summary</h2><p class="text-muted mb-0">Share of filtered spending</p></div>
            <?php foreach (['Food' => 'food', 'Gas' => 'gas', 'Other' => 'other'] as $label => $tone): $value = (float) $selectedTotals[$label]; $share = $selectedTotal > 0 ? ($value / $selectedTotal) * 100 : 0; ?>
                <div class="kitchen-expenditure-summary-row"><span class="kitchen-expenditure-summary-dot kitchen-expenditure-summary-dot--<?= h($tone) ?>"></span><span><?= h($label) ?></span><strong><?= 'KSh ' . number_format($value, 2) ?></strong><small><?= number_format($share, 1) ?>%</small></div>
            <?php endforeach; ?>
            <div class="kitchen-expenditure-summary-row kitchen-expenditure-summary-row--total"><span class="kitchen-expenditure-summary-dot kitchen-expenditure-summary-dot--total"></span><strong>Total</strong><strong><?= 'KSh ' . number_format($selectedTotal, 2) ?></strong><small><?= $selectedTotal > 0 ? '100%' : '0%' ?></small></div>
        </section></div>
    </div>

    <section class="panel kitchen-expenditure-records">
        <div class="panel-heading d-flex justify-content-between align-items-start gap-3"><div><h2>Recent kitchen entries</h2><p class="text-muted mb-0">Historical entries are retained; the filters apply to totals, chart, and records.</p></div><span class="small text-muted">Showing <?= count($records) ?> of <?= $recordCount ?></span></div>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Date</th><th>Item / description</th><th>Category</th><th>Quantity</th><th>Unit price</th><th>Total amount</th><th>Notes</th><?php if ($canSeeKitchenRecorder): ?><th>Recorded by</th><?php endif; ?></tr></thead>
            <tbody><?php foreach ($records as $record):
                $recordCategory = trim((string) ($record['kitchen_category'] ?? ''));
                if ($recordCategory === '') {
                    preg_match('/^Kitchen category:\\s*([^|]+)\\s*\\|/', (string) $record['description'], $legacyCategoryMatch);
                    $legacyCategory = trim((string) ($legacyCategoryMatch[1] ?? ''));
                    $recordCategory = $legacyCategory !== ''
                        ? $legacyCategory
                        : ((string) $record['expense_category'] === 'Gas Refill' ? 'Gas' : (in_array((string) $record['expense_category'], ['Kitchen Purchases', 'WHOLESALE'], true) ? 'Food' : 'Other'));
                }
                $notes = preg_replace('/^Kitchen category: [^|]+\s*\|\s*(?:Kitchen entry|Purchase) #\d+(?:\s*\|\s*Stock tracked: (?:Yes|No))?\s*\|?\s*/', '', (string) $record['description']);
                $notes = trim((string) $notes) ?: '—';
                $categoryTone = ['Food' => 'food', 'Gas' => 'gas', 'Other' => 'other'][in_array($recordCategory, ['Food', 'Gas'], true) ? $recordCategory : 'Other'];
            ?>
                <tr><td><?= h($record['expense_date']) ?></td><td><?= h($record['item_name']) ?></td><td><span class="kitchen-category-badge kitchen-category-badge--<?= h($categoryTone) ?>"><?= h($recordCategory) ?></span></td>
                    <td><?= (float) $record['quantity'] > 0 ? number_format((float) $record['quantity'], 2) . ' ' . h($record['unit'] ?? '') : '—' ?></td>
                    <td><?= (float) $record['amount'] > 0 ? 'KSh ' . number_format((float) $record['amount'], 2) : '—' ?></td>
                    <td><strong><?= 'KSh ' . number_format((float) $record['total_amount'], 2) ?></strong></td><td><?= h($notes) ?></td><?php if ($canSeeKitchenRecorder): ?><td><?= h($record['recorded_by'] ?: '—') ?></td><?php endif; ?></tr>
            <?php endforeach; ?>
            <?php if (!$records): ?><tr><td colspan="<?= $canSeeKitchenRecorder ? 8 : 7 ?>" class="text-muted">No kitchen expenses match these filters.</td></tr><?php endif; ?></tbody>
        </table></div>
        <?php if ($recordCount > count($records)): ?><p class="small text-muted mb-0">Showing the latest 500 entries. Narrow the filters to view a smaller period.</p><?php endif; ?>
    </section>
</div>
<script>
window.kitchenExpenseChartData = {
    labels: ['Food', 'Gas', 'Other'],
    values: <?= json_encode(array_values($selectedTotals), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('kitchenExpenseCategoryChart');
    if (!canvas || !window.Chart) return;
    const formatMoney = value => `KSh ${Number(value).toLocaleString('en-KE', { maximumFractionDigits: 0 })}`;
    new Chart(canvas, {
        type: 'bar',
        data: { labels: window.kitchenExpenseChartData.labels, datasets: [{ label: 'Expenditure', data: window.kitchenExpenseChartData.values, backgroundColor: ['#169b72', '#168fc4', '#d97706'], borderRadius: 5, maxBarThickness: 72 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => formatMoney(context.parsed.y) } } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { callback: formatMoney }, grid: { color: 'rgba(148, 163, 184, .2)' } }
            }
        }
    });
});
</script>
<?php endif; ?>

<script>
(() => {
    const form = document.getElementById('kitchen-expense-form');
    const quantity = document.getElementById('quantity');
    const unitPrice = document.getElementById('unit_price');
    const total = document.getElementById('total_amount');
    if (quantity && unitPrice && total) {
        const updateTotal = () => {
            const qty = Number(quantity.value);
            const price = Number(unitPrice.value);
            if (qty > 0 && price > 0) total.value = (qty * price).toFixed(2);
        };
        quantity.addEventListener('input', updateTotal);
        unitPrice.addEventListener('input', updateTotal);
    }
    const unit = document.getElementById('unit');
    const customUnitWrap = document.getElementById('kitchen-custom-unit-wrap');
    const customUnit = document.getElementById('unit_custom');
    const updateCustomUnit = () => {
        if (!unit || !customUnitWrap || !customUnit) return;
        const showCustomUnit = unit.value === '__other__';
        customUnitWrap.hidden = !showCustomUnit;
        customUnit.required = showCustomUnit;
        if (!showCustomUnit) customUnit.value = '';
    };
    unit?.addEventListener('change', updateCustomUnit);
    updateCustomUnit();

    const category = document.getElementById('category');
    const customCategoryWrap = document.getElementById('kitchen-custom-category-wrap');
    const customCategory = document.getElementById('custom_category');
    const updateCustomCategory = () => {
        if (!category || !customCategoryWrap || !customCategory) return;
        const showCustomCategory = category.value === '__add_category__';
        customCategoryWrap.hidden = !showCustomCategory;
        customCategory.required = showCustomCategory;
        if (!showCustomCategory) customCategory.value = '';
    };
    category?.addEventListener('change', updateCustomCategory);
    updateCustomCategory();

    document.getElementById('kitchen-clear-form')?.addEventListener('click', () => {
        if (!form) return;
        form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => {
            if (field.id === 'purchase_date') {
                field.value = field.dataset.clearValue || '';
            } else if (field instanceof HTMLSelectElement) {
                field.selectedIndex = 0;
            } else {
                field.value = '';
            }
        });
        updateCustomUnit();
        updateCustomCategory();
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
