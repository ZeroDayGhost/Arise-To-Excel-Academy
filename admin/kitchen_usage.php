<?php
$pageTitle = 'Kitchen Daily Usage';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen_stock_taking.php#stock-records');

// Module access
if (!current_admin_has_permission($pdo, 'kitchen.access')) {
    flash('error', 'You do not have permission to access Kitchen.');
    redirect('admin/dashboard.php');
}

$canRecordUsage = current_admin_has_permission($pdo, 'kitchen.record_usage');

ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record_usage') {
        $itemName = trim($_POST['item_name'] ?? '');
        $usageDate = trim($_POST['usage_date'] ?? date('Y-m-d'));
        $qtyUsed = (float) ($_POST['quantity_used'] ?? 0);
        $unitPrice = (float) ($_POST['unit_price'] ?? 0);
        $unit = kitchen_validate_unit((string) ($_POST['unit'] ?? ''));
        $notes = trim($_POST['notes'] ?? '');

        if ($itemName === '' || $qtyUsed <= 0) {
            $errors[] = 'Item name and quantity are required.';
        } elseif (!array_key_exists($unit, kitchen_available_unit_options($pdo))) {
            $errors[] = 'Choose a valid quantity unit.';
        } elseif (!valid_date_value($usageDate)) {
            $errors[] = 'Date must be valid.';
        } else {
            if (!$canRecordUsage) {
                flash('error', 'You do not have permission to record kitchen usage.');
                redirect('admin/kitchen_usage.php');
            }
            $totalCost = $qtyUsed * $unitPrice;
            $kitem = kitchen_get_or_create_item($pdo, $itemName, 'Kitchen', 0.0, 0.0, $unit);
            $recordedBy = $_SESSION['admin_id'] ?? null;
            
            // Record the usage movement with unit price and total cost for the correct term
            kitchen_record_movement($pdo, (int)$kitem['id'], 'out', $qtyUsed, $unitPrice, $totalCost, null, 'Usage: ' . $notes, $recordedBy, $usageDate, $unit);
            
            // Also create an expense record for cost tracking
            $expStmt = $pdo->prepare("INSERT INTO school_expenses (item_name, category, amount, quantity, unit, total_amount, expense_date, description) VALUES (:item_name, :category, :amount, :quantity, :unit, :total_amount, :expense_date, :description)");
            $expStmt->execute([
                'item_name' => $itemName,
                'category' => 'Kitchen',
                'amount' => $unitPrice,
                'quantity' => $qtyUsed,
                'unit' => $unit,
                'total_amount' => $totalCost,
                'expense_date' => $usageDate,
                'description' => 'Daily usage: ' . ($notes ?: 'Regular consumption'),
            ]);
            
            flash('success', 'Usage recorded: ' . $itemName . ' (' . $qtyUsed . ' ' . $unit . ')');
            redirect('admin/kitchen_usage.php');
        }
    }
}

// Get all items for dropdown
$allItemsStmt = $pdo->query("SELECT DISTINCT item_name, unit FROM kitchen_items ORDER BY item_name ASC");
$allItems = $allItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Usage history with remaining stock calculation
$historySql = "SELECT ksm.id, ksm.created_at, ki.item_name, ksm.quantity, COALESCE(ksm.unit, ki.unit) AS unit, ksm.unit_price, ksm.total_cost, ksm.note, ksm.recorded_by, a.name AS recorded_by_name,
            (SELECT SUM(CASE WHEN movement_type IN ('in','purchase') THEN quantity ELSE -quantity END) 
             FROM kitchen_stock_movements 
             WHERE kitchen_item_id = ksm.kitchen_item_id";
$historyParams = [];
$termAware = db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term');
if ($termAware) {
    $historySql .= " AND academic_year = :academic_year AND term = :term AND transaction_date <= ksm.transaction_date";
    $historyParams['academic_year'] = $currentContext['academic_year'];
    $historyParams['term'] = $currentContext['term'];
} else {
    $historySql .= " AND created_at <= ksm.created_at";
}
$historySql .= ") AS remaining_at_time
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     LEFT JOIN admin a ON a.id = ksm.recorded_by
     WHERE ksm.movement_type = 'out'";
if ($termAware) {
    $historySql .= " AND ksm.academic_year = :academic_year AND ksm.term = :term";
}
$historySql .= " ORDER BY " . ($termAware ? 'ksm.transaction_date' : 'ksm.created_at') . " DESC LIMIT 100";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($historyParams);
$history = $historyStmt->fetchAll();

// Daily usage summary
$todaySql = "SELECT ki.item_name, COALESCE(ksm.unit, ki.unit) AS unit, SUM(ksm.quantity) AS qty_used, SUM(ksm.total_cost) AS cost_used
     FROM kitchen_stock_movements ksm
     JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
     WHERE ksm.movement_type = 'out'";
$todayParams = ['today' => date('Y-m-d')];
if ($termAware) {
    $todaySql .= " AND ksm.transaction_date = :today AND ksm.academic_year = :academic_year AND ksm.term = :term";
    $todayParams['academic_year'] = $currentContext['academic_year'];
    $todayParams['term'] = $currentContext['term'];
} else {
    $todaySql .= " AND DATE(ksm.created_at) = :today";
}
$todaySql .= " GROUP BY ksm.kitchen_item_id, COALESCE(ksm.unit, ki.unit) ORDER BY qty_used DESC";
$todayStmt = $pdo->prepare($todaySql);
$todayStmt->execute($todayParams);
$todayUsage = $todayStmt->fetchAll();

$todayQtyTotal = kitchen_quantity_summary($todayUsage);
$todayCostTotal = array_sum(array_column($todayUsage, 'cost_used'));

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen</p>
        <h1>Daily Kitchen Usage</h1>
        <p class="mb-0 text-muted">Record daily consumption and track food costs.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/kitchen_inventory.php') ?>">Inventory</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_consumption_report.php') ?>">Consumption Report</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_reports.php') ?>">Kitchen Reports</a>
    </div>
</div>

<!-- Flash messages are shown in header -->
<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <div class="panel-heading"><h2>Record Usage</h2></div>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="record_usage">
                
                <div class="col-12">
                    <label class="form-label" for="item_name">Item Name</label>
                    <input class="form-control" id="item_name" name="item_name" placeholder="Rice, Bread, etc." list="item_list" required>
                    <datalist id="item_list">
                        <?php foreach ($allItems as $item): ?>
                            <option value="<?= h($item['item_name']) ?>" data-unit="<?= h($item['unit']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="usage_date">Date</label>
                    <input class="form-control" type="date" id="usage_date" name="usage_date" value="<?= h(date('Y-m-d')) ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="quantity_used">Quantity Used</label>
                    <div class="input-group">
                        <input class="form-control" type="number" step="0.01" id="quantity_used" name="quantity_used" placeholder="Quantity" min="0" required>
                        <select class="form-select" id="usage_unit" name="unit" aria-label="Quantity unit" style="max-width: 170px">
                            <?php foreach (kitchen_available_unit_options($pdo) as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= $value === 'kg' ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="unit_price">Unit Price (per selected unit)</label>
                    <input class="form-control" type="number" step="0.01" id="unit_price" name="unit_price" placeholder="KSh" min="0" required>
                </div>

                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" placeholder="e.g., Breakfast, Lunch">
                </div>

                <div class="col-12">
                    <?php if ($canRecordUsage): ?>
                        <button class="btn btn-primary w-100" type="submit">Record Usage</button>
                    <?php else: ?>
                        <button class="btn btn-primary w-100" type="button" disabled>Record Usage</button>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading"><h2>Today's Usage</h2></div>
            <table class="table table-sm table-borderless">
                <tbody>
                    <tr>
                        <td><strong>Items Used:</strong></td>
                        <td class="text-end"><?= count($todayUsage) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Quantity:</strong></td>
                        <td class="text-end"><?= h($todayQtyTotal) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Cost:</strong></td>
                        <td class="text-end"><strong><?= money($todayCostTotal) ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($todayUsage): ?>
                <div style="font-size:0.85rem;border-top:1px solid #ddd;padding-top:12px;">
                    <?php foreach ($todayUsage as $u): ?>
                        <div style="margin-bottom:8px;display:flex;justify-content:space-between;">
                            <span><?= h($u['item_name']) ?> <small class="text-muted"><?= h((string)$u['qty_used']) ?> <?= h($u['unit'] ?? '') ?></small></span>
                            <span><strong><?= money((float)$u['cost_used']) ?></strong></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading"><h2>Usage History (Last 100 entries)</h2></div>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Cost</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?= h(date('Y-m-d', strtotime($h['created_at']))) ?></td>
                                <td><?= h($h['item_name']) ?></td>
                                <td><?= h((string)$h['quantity']) ?> <?= h($h['unit'] ?? '') ?></td>
                                <td><?= money((float)$h['unit_price']) ?></td>
                                <td><?= money((float)$h['total_cost']) ?></td>
                                <td><small><?= h($h['recorded_by_name'] ?? 'System') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?><tr><td colspan="6">No usage history yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
<script>
document.getElementById('item_name')?.addEventListener('change', function () {
    const option = [...document.querySelectorAll('#item_list option')].find(item => item.value === this.value);
    if (!option) return;
    const unitSelect = document.getElementById('usage_unit');
    const unit = option.dataset.unit;
    if (![...unitSelect.options].some(item => item.value === unit)) {
        unitSelect.add(new Option(unit, unit));
    }
    unitSelect.value = unit;
});
</script>
