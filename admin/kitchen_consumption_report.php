<?php
$pageTitle = 'Kitchen Consumption Report';
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

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$termAware = db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term');

// Today's consumption
$todaySql = "SELECT ki.item_name, COALESCE(ksm.unit, ki.unit) AS unit, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     WHERE ksm.movement_type = 'out'";
$todayParams = ['date' => $today];
if ($termAware) {
    $todaySql .= " AND ksm.transaction_date = :date AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $todayParams['academic_year'] = $currentContext['academic_year'];
    $todayParams['term'] = $currentContext['term'];
} else {
    $todaySql .= " AND DATE(ksm.created_at) = :date";
}
$todaySql .= " GROUP BY ksm.kitchen_item_id, COALESCE(ksm.unit, ki.unit) ORDER BY qty_used DESC";
$todayStmt = $pdo->prepare($todaySql);
$todayStmt->execute($todayParams);
$todayUsage = $todayStmt->fetchAll();
$todayQty = kitchen_quantity_summary($todayUsage);
$todayCost = array_sum(array_column($todayUsage, 'cost_used'));

// This week's consumption
$weekSql = "SELECT ki.item_name, COALESCE(ksm.unit, ki.unit) AS unit, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     WHERE ksm.movement_type = 'out'";
$weekParams = ['week_start' => $weekStart];
if ($termAware) {
    $weekSql .= " AND ksm.transaction_date >= :week_start AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $weekParams['academic_year'] = $currentContext['academic_year'];
    $weekParams['term'] = $currentContext['term'];
} else {
    $weekSql .= " AND DATE(ksm.created_at) >= :week_start";
}
$weekSql .= " GROUP BY ksm.kitchen_item_id, COALESCE(ksm.unit, ki.unit) ORDER BY qty_used DESC";
$weekStmt = $pdo->prepare($weekSql);
$weekStmt->execute($weekParams);
$weekUsage = $weekStmt->fetchAll();
$weekQty = kitchen_quantity_summary($weekUsage);
$weekCost = array_sum(array_column($weekUsage, 'cost_used'));

// This month's consumption
$monthSql = "SELECT ki.item_name, COALESCE(ksm.unit, ki.unit) AS unit, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     WHERE ksm.movement_type = 'out'";
$monthParams = ['month_start' => $monthStart, 'month_end' => $monthEnd];
if ($termAware) {
    $monthSql .= " AND ksm.transaction_date BETWEEN :month_start AND :month_end AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $monthParams['academic_year'] = $currentContext['academic_year'];
    $monthParams['term'] = $currentContext['term'];
} else {
    $monthSql .= " AND DATE(ksm.created_at) BETWEEN :month_start AND :month_end";
}
$monthSql .= " GROUP BY ksm.kitchen_item_id, COALESCE(ksm.unit, ki.unit) ORDER BY qty_used DESC";
$monthStmt = $pdo->prepare($monthSql);
$monthStmt->execute($monthParams);
$monthUsage = $monthStmt->fetchAll();
$monthQty = kitchen_quantity_summary($monthUsage);
$monthCost = array_sum(array_column($monthUsage, 'cost_used'));

// Most used items overall
$mostSql = "SELECT ki.item_name, COALESCE(ksm.unit, ki.unit) AS unit, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used, COUNT(*) AS times_used
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     WHERE ksm.movement_type = 'out'";
$mostParams = [];
if ($termAware) {
    $mostSql .= " AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $mostParams['academic_year'] = $currentContext['academic_year'];
    $mostParams['term'] = $currentContext['term'];
}
$mostSql .= " GROUP BY ksm.kitchen_item_id, COALESCE(ksm.unit, ki.unit) ORDER BY qty_used DESC LIMIT 50";
$mostStmt = $pdo->prepare($mostSql);
$mostStmt->execute($mostParams);
$mostUsed = $mostStmt->fetchAll();

// Daily trend (last 30 days)
$dailyTrendSql = "SELECT " . ($termAware ? 'ksm.transaction_date' : 'DATE(ksm.created_at)') . " AS usage_date, COALESCE(ksm.unit, 'kg') AS unit, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used
     FROM kitchen_stock_movements ksm
     WHERE ksm.movement_type = 'out'";
$dailyTrendParams = [];
if ($termAware) {
    $dailyTrendSql .= " AND ksm.transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $dailyTrendParams['academic_year'] = $currentContext['academic_year'];
    $dailyTrendParams['term'] = $currentContext['term'];
} else {
    $dailyTrendSql .= " AND ksm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}
$dailyTrendSql .= " GROUP BY usage_date, COALESCE(ksm.unit, 'kg') ORDER BY usage_date DESC";
$dailyTrendStmt = $pdo->prepare($dailyTrendSql);
$dailyTrendStmt->execute($dailyTrendParams);
$dailyTrend = $dailyTrendStmt->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen</p>
        <h1>Kitchen Consumption Report</h1>
        <p class="mb-0 text-muted">Daily, weekly and monthly food consumption and costs.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/kitchen_usage.php') ?>">Daily Usage</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_inventory.php') ?>">Inventory</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted mb-2">TODAY'S CONSUMPTION</h6>
                <h3 class="mb-1"><?= h($todayQty) ?></h3>
                <p class="mb-0 text-success"><strong><?= money($todayCost) ?></strong></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted mb-2">THIS WEEK'S CONSUMPTION</h6>
                <h3 class="mb-1"><?= h($weekQty) ?></h3>
                <p class="mb-0 text-info"><strong><?= money($weekCost) ?></strong></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted mb-2">THIS MONTH'S CONSUMPTION</h6>
                <h3 class="mb-1"><?= h($monthQty) ?></h3>
                <p class="mb-0 text-warning"><strong><?= money($monthCost) ?></strong></p>
            </div>
        </div>
    </div>
</div>

<section class="panel mb-4">
    <h2>Most Used Items</h2>
    <p class="text-muted">Top items by quantity consumed (all time).</p>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Quantity Used</th>
                    <th>Total Cost</th>
                    <th>Times Used</th>
                    <th>Avg Cost/Use</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mostUsed as $m): ?>
                    <tr>
                        <td><?= h($m['item_name']) ?></td>
                        <td><?= h((string)$m['qty_used']) ?> <?= h($m['unit'] ?? '') ?></td>
                        <td><?= money((float)$m['cost_used']) ?></td>
                        <td><?= (int)$m['times_used'] ?></td>
                        <td><?= money((float)$m['cost_used'] / (int)$m['times_used']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$mostUsed): ?><tr><td colspan="5">No usage history yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-4">
    <div class="col-lg-6">
        <section class="panel">
            <h2>Today's Items</h2>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Item</th><th>Qty</th><th>Cost</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayUsage as $u): ?>
                            <tr>
                                <td><?= h($u['item_name']) ?></td>
                                <td><?= h((string)$u['qty_used']) ?> <?= h($u['unit'] ?? '') ?></td>
                                <td><?= money((float)$u['cost_used']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$todayUsage): ?><tr><td colspan="3">No usage today.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-lg-6">
        <section class="panel">
            <h2>This Week's Items</h2>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Item</th><th>Qty</th><th>Cost</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weekUsage as $u): ?>
                            <tr>
                                <td><?= h($u['item_name']) ?></td>
                                <td><?= h((string)$u['qty_used']) ?> <?= h($u['unit'] ?? '') ?></td>
                                <td><?= money((float)$u['cost_used']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$weekUsage): ?><tr><td colspan="3">No usage this week.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="panel">
    <h2>Daily Trend (Last 30 Days)</h2>
    <div class="table-responsive">
        <table class="table table-striped table-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Quantity Used</th>
                    <th>Daily Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyTrend as $d): ?>
                    <tr>
                        <td><?= h($d['usage_date']) ?></td>
                        <td><?= h((string)$d['qty_used']) ?> <?= h($d['unit'] ?? '') ?></td>
                        <td><?= money((float)$d['cost_used']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dailyTrend): ?><tr><td colspan="3">No usage history.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
