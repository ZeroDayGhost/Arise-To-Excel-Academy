<?php
$pageTitle = 'Kitchen Reports';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen_expenditure.php');

// Module access
if (!current_admin_has_permission($pdo, 'kitchen.access')) {
    flash('error', 'You do not have permission to access Kitchen reports.');
    redirect('admin/dashboard.php');
}

ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);
$filterDate = valid_date_value($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '';
$year = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : $currentContext['academic_year'];

if ($month === '') {
    $defaultMonth = $currentContext['today'];
    if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
        if ($currentContext['today'] < $currentContext['start_date'] || $currentContext['today'] > $currentContext['end_date']) {
            $defaultMonth = $currentContext['start_date'];
        }
    }
    $month = date('Y-m', strtotime($defaultMonth));
}

$monthLabel = $month;
$monthDate = DateTime::createFromFormat('Y-m', $month);
if ($monthDate !== false) {
    $monthLabel = $monthDate->format('F Y');
}

// Daily spending (restrict to active term range when available)
$dailySql = "SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases') AND expense_date = :d";
$dailyParams = ['d' => $filterDate];
if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $dailySql .= " AND expense_date BETWEEN :term_start AND :term_end";
    $dailyParams['term_start'] = $currentContext['start_date'];
    $dailyParams['term_end'] = $currentContext['end_date'];
}
$dailyStmt = $pdo->prepare($dailySql);
$dailyStmt->execute($dailyParams);
$dailyTotal = (float) $dailyStmt->fetchColumn();

// Monthly spending
$monthlyStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases') AND DATE_FORMAT(expense_date, '%Y-%m') = :m");
$monthlyStmt->execute(['m' => $month]);
$monthlyTotal = (float) $monthlyStmt->fetchColumn();

// Yearly spending
$yearlyStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases') AND YEAR(expense_date) = :y");
$yearlyStmt->execute(['y' => $year]);
$yearlyTotal = (float) $yearlyStmt->fetchColumn();

// Most purchased items in the active term (exclude daily purchases if column exists)
if (function_exists('db_has_column') && db_has_column($pdo, 'kitchen_inventory', 'purchase_type') && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
    $mostStmt = $pdo->prepare("SELECT item_name, unit, COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(total_amount),0) AS spent FROM kitchen_inventory WHERE COALESCE(purchase_type,'weekly') != 'daily' AND academic_year = :academic_year AND term = :term GROUP BY item_name, unit ORDER BY qty DESC LIMIT 25");
    $mostStmt->execute(['academic_year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
} elseif (function_exists('db_has_column') && db_has_column($pdo, 'kitchen_inventory', 'purchase_type')) {
    $sql = "SELECT item_name, unit, COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(total_amount),0) AS spent FROM kitchen_inventory WHERE COALESCE(purchase_type,'weekly') != 'daily'";
    $params = [];
    if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
        $sql .= " AND item_date BETWEEN :term_start AND :term_end";
        $params['term_start'] = $currentContext['start_date'];
        $params['term_end'] = $currentContext['end_date'];
    }
    $sql .= " GROUP BY item_name, unit ORDER BY qty DESC LIMIT 25";
    $mostStmt = $pdo->prepare($sql);
    $mostStmt->execute($params);
} else {
    $mostStmt = $pdo->query("SELECT item_name, unit, COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(total_amount),0) AS spent FROM kitchen_inventory GROUP BY item_name, unit ORDER BY qty DESC LIMIT 25");
}
$most = $mostStmt->fetchAll();

// Low stock
$low = low_stock_kitchen_items($pdo);

// Recent daily purchases for current term
$dailyPurchases = get_daily_purchases($pdo, null, null, $currentContext['academic_year'], $currentContext['term']);

// Weekly shopping summary (recent 20) for current term
if (function_exists('db_has_column') && db_has_column($pdo, 'weekly_shopping', 'academic_year') && db_has_column($pdo, 'weekly_shopping', 'term')) {
    $wsStmt = $pdo->prepare("SELECT * FROM weekly_shopping WHERE academic_year = :academic_year AND term = :term ORDER BY shopping_date DESC LIMIT 20");
    $wsStmt->execute(['academic_year' => $currentContext['academic_year'], 'term' => $currentContext['term']]);
} else {
    $wsSql = "SELECT * FROM weekly_shopping";
    $wsParams = [];
    if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
        $wsSql .= " WHERE shopping_date BETWEEN :term_start AND :term_end";
        $wsParams['term_start'] = $currentContext['start_date'];
        $wsParams['term_end'] = $currentContext['end_date'];
    }
    $wsSql .= " ORDER BY shopping_date DESC LIMIT 20";
    $wsStmt = $pdo->prepare($wsSql);
    $wsStmt->execute($wsParams);
}
$weeklyShopping = $wsStmt->fetchAll();

$wsItems = [];
if (isset($_GET['view_weekly'])) {
    $wsId = (int) $_GET['view_weekly'];
    $itStmt = $pdo->prepare("SELECT * FROM weekly_shopping_items WHERE weekly_shopping_id = :id");
    $itStmt->execute(['id' => $wsId]);
    $wsItems = $itStmt->fetchAll();
}

// Gas expenses summary for current month/year
// Use the active academic year so year records persist until a new academic year is activated.
$currentYear = isset($currentContext['academic_year']) ? (int)$currentContext['academic_year'] : (int)date('Y');
// Determine the month to use: prefer the page's selected month (YYYY-MM), fallback to current month.
if (preg_match('/^\d{4}-(\d{2})$/', $month, $m)) {
    $currentMonth = (int)$m[1];
} else {
    $currentMonth = (int) date('m');
}

// Gas expenses: restrict to the active term when a term date range exists by computing
// the intersection between the term range and the requested month/year. Fall back
// to the existing helpers when no term bounds are available.
if (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    // Monthly intersection
    $monthStart = sprintf('%04d-%02d-01', (int)$currentYear, $currentMonth);
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $mStart = ($monthStart < $currentContext['start_date']) ? $currentContext['start_date'] : $monthStart;
    $mEnd = ($monthEnd > $currentContext['end_date']) ? $currentContext['end_date'] : $monthEnd;
    if ($mStart > $mEnd) {
        $monthlyGas = 0.0;
    } else {
        $gStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :start AND :end");
        $gStmt->execute(['start' => $mStart, 'end' => $mEnd]);
        $monthlyGas = (float) $gStmt->fetchColumn();
    }

    // Yearly: use the active academic year's overall bounds (min start_date, max end_date)
    $ayStmt = $pdo->prepare("SELECT MIN(start_date) AS start_date, MAX(end_date) AS end_date FROM academic_calendar WHERE academic_year = :academic_year");
    $ayStmt->execute(['academic_year' => (string)$currentYear]);
    $ayRow = $ayStmt->fetch();
    if ($ayRow && !empty($ayRow['start_date']) && !empty($ayRow['end_date'])) {
        $yStart = $ayRow['start_date'];
        $yEnd = $ayRow['end_date'];
    } else {
        $yStart = sprintf('%04d-01-01', (int)$currentYear);
        $yEnd = sprintf('%04d-12-31', (int)$currentYear);
    }
    if ($yStart > $yEnd) {
        $yearlyGas = 0.0;
    } else {
        $gStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :start AND :end");
        $gStmt->execute(['start' => $yStart, 'end' => $yEnd]);
        $yearlyGas = (float) $gStmt->fetchColumn();
    }

} else {
    $monthlyGas = monthly_gas_expenses($pdo, (int)$currentYear, $currentMonth);
    $yearlyGas = yearly_gas_expenses($pdo, (int)$currentYear);
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen</p>
        <h1>Kitchen Reports</h1>
        <p class="mb-0 text-muted">Daily, monthly and yearly kitchen spending, most purchased items, and low stock.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/kitchen_inventory.php') ?>">Back to Inventory</a>
    </div>
</div>

<section class="panel mb-4">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Daily</label>
            <form id="dailyForm" method="get" class="input-group">
                <input class="form-control" type="date" name="date" value="<?= h($filterDate) ?>" onchange="this.form.submit()">
                <input type="hidden" name="month" value="<?= h($month) ?>">
                <input type="hidden" name="year" value="<?= h($year) ?>">
            </form>
            <p class="mt-2"><strong><?= money($dailyTotal) ?></strong> spent on <?= h($filterDate) ?></p>
        </div>
        <div class="col-md-4">
            <label class="form-label">Monthly</label>
            <form id="monthForm" method="get">
                <input class="form-control" type="month" name="month" value="<?= h($month) ?>" onchange="this.form.submit()">
                <input type="hidden" name="date" value="<?= h($filterDate) ?>">
                <input type="hidden" name="year" value="<?= h($year) ?>">
            </form>
            <p class="mt-2"><strong><?= money($monthlyTotal) ?></strong> spent in <?= h($monthLabel) ?></p>
        </div>
        <div class="col-md-4">
            <label class="form-label">Yearly</label>
            <input class="form-control" type="text" value="<?= h($year) ?>" disabled>
            <p class="mt-2"><strong><?= money($yearlyTotal) ?></strong> spent in <?= h($year) ?></p>
        </div>
    </div>
</section>

<section class="panel mb-4">
    <h2>Most Purchased Items</h2>
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle" style="table-layout: fixed;">
            <thead class="table-light">
                <tr>
                    <th style="width: 40%; padding: 12px; font-weight: 600; text-align: left;">Item</th>
                    <th style="width: 30%; padding: 12px; font-weight: 600; text-align: center;">Quantity</th>
                    <th style="width: 30%; padding: 12px; font-weight: 600; text-align: right;">Total Spent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($most as $m): ?>
                    <tr>
                        <td style="width: 40%; padding: 12px; text-align: left;"><?= h($m['item_name']) ?></td>
                        <td style="width: 30%; padding: 12px; text-align: center;"><?= h((string)$m['qty']) ?> <?= h($m['unit'] ?? '') ?></td>
                        <td style="width: 30%; padding: 12px; text-align: right;"><?= money((float)$m['spent']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$most): ?><tr><td colspan="3" class="text-muted text-center" style="padding: 12px;">No purchase records yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-4">
    <h2>Low Stock Items</h2>
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle" style="table-layout: fixed;">
            <thead class="table-light">
                <tr>
                    <th style="width: 35%; padding: 12px; font-weight: 600; text-align: left;">Item</th>
                    <th style="width: 20%; padding: 12px; font-weight: 600; text-align: center;">Remaining</th>
                    <th style="width: 15%; padding: 12px; font-weight: 600; text-align: center;">Unit</th>
                    <th style="width: 15%; padding: 12px; font-weight: 600; text-align: center;">Category</th>
                    <th style="width: 15%; padding: 12px; font-weight: 600; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low as $l): ?>
                    <tr>
                        <td style="width: 35%; padding: 12px; text-align: left;"><?= h($l['item_name']) ?></td>
                        <td style="width: 20%; padding: 12px; text-align: center;"><?= h((string)$l['remaining_stock']) ?></td>
                        <td style="width: 15%; padding: 12px; text-align: center;"><?= h($l['unit'] ?? 'kg') ?></td>
                        <td style="width: 15%; padding: 12px; text-align: center;"><?= h($l['category']) ?></td>
                        <td style="width: 15%; padding: 12px; text-align: center;">
                            <?php if ($l['remaining_stock'] <= 0): ?>
                                <span class="badge bg-danger">Out of Stock</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Low Stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$low): ?><tr><td colspan="5" class="text-muted text-center" style="padding: 12px;">No low stock items.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-4">
    <h2>Recent Daily Purchases</h2>
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle" style="table-layout: fixed;">
            <thead class="table-light">
                <tr>
                    <th style="width: 10%; padding: 12px; font-weight: 600; text-align: left;">Date</th>
                    <th style="width: 18%; padding: 12px; font-weight: 600; text-align: left;">Item</th>
                    <th style="width: 12%; padding: 12px; font-weight: 600; text-align: center;">Quantity</th>
                    <th style="width: 14%; padding: 12px; font-weight: 600; text-align: center;">Category</th>
                    <th style="width: 12%; padding: 12px; font-weight: 600; text-align: right;">Amount</th>
                    <th style="width: 16%; padding: 12px; font-weight: 600; text-align: left;">Supplier</th>
                    <th style="width: 18%; padding: 12px; font-weight: 600; text-align: left;">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyPurchases as $d): ?>
                <tr>
                    <td style="width: 10%; padding: 12px; text-align: left;"><?= h($d['purchase_date']) ?></td>
                    <td style="width: 18%; padding: 12px; text-align: left;"><?= h($d['item_name']) ?></td>
                    <td style="width: 12%; padding: 12px; text-align: center;"><?= h((string)($d['quantity'] ?? '')) ?> <?= h($d['unit'] ?? '') ?></td>
                    <td style="width: 14%; padding: 12px; text-align: center;"><?= h($d['category']) ?></td>
                    <td style="width: 12%; padding: 12px; text-align: right;"><?= money((float)$d['amount']) ?></td>
                    <td style="width: 16%; padding: 12px; text-align: left;"><?= h($d['supplier']) ?></td>
                    <td style="width: 18%; padding: 12px; text-align: left;"><?= h($d['notes']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$dailyPurchases): ?><tr><td colspan="7" class="text-muted text-center" style="padding: 12px;">No daily purchases recorded.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-4">
    <h2>Weekly Shopping (Recent)</h2>
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle" style="table-layout: fixed;">
            <thead class="table-light">
                <tr>
                    <th style="width: 20%; padding: 12px; font-weight: 600; text-align: left;">Date</th>
                    <th style="width: 35%; padding: 12px; font-weight: 600; text-align: left;">Supplier</th>
                    <th style="width: 20%; padding: 12px; font-weight: 600; text-align: right;">Total</th>
                    <th style="width: 25%; padding: 12px; font-weight: 600; text-align: center;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weeklyShopping as $w): ?>
                <tr>
                    <td style="width: 20%; padding: 12px; text-align: left;"><?= h($w['shopping_date']) ?></td>
                    <td style="width: 35%; padding: 12px; text-align: left;"><?= h($w['supplier']) ?></td>
                    <td style="width: 20%; padding: 12px; text-align: right;"><?= money((float)$w['total_amount']) ?></td>
                    <td style="width: 25%; padding: 12px; text-align: center;"><a href="<?= url('admin/kitchen_reports.php') ?>?view_weekly=<?= h($w['id']) ?>">View items</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$weeklyShopping): ?><tr><td colspan="4" class="text-muted text-center" style="padding: 12px;">No weekly shopping records.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($wsItems): ?>
<section class="panel mb-4">
    <h2>Weekly Shopping Items</h2>
    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle" style="table-layout: fixed;">
            <thead class="table-light">
                <tr>
                    <th style="width: 35%; padding: 12px; font-weight: 600; text-align: left;">Item</th>
                    <th style="width: 15%; padding: 12px; font-weight: 600; text-align: center;">Qty</th>
                    <th style="width: 12%; padding: 12px; font-weight: 600; text-align: center;">Unit</th>
                    <th style="width: 19%; padding: 12px; font-weight: 600; text-align: right;">Unit Price</th>
                    <th style="width: 19%; padding: 12px; font-weight: 600; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wsItems as $it): ?>
                <tr>
                    <td style="width: 35%; padding: 12px; text-align: left;"><?= h($it['item_name']) ?></td>
                    <td style="width: 15%; padding: 12px; text-align: center;"><?= h((string)$it['quantity']) ?></td>
                    <td style="width: 12%; padding: 12px; text-align: center;"><?= h($it['unit']) ?></td>
                    <td style="width: 19%; padding: 12px; text-align: right;"><?= money((float)$it['unit_price']) ?></td>
                    <td style="width: 19%; padding: 12px; text-align: right;"><?= money((float)$it['total_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel mb-4">
    <h2>Gas Expenses</h2>
    <p>This month: <strong><?= money($monthlyGas) ?></strong></p>
    <p>This year: <strong><?= money($yearlyGas) ?></strong></p>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
