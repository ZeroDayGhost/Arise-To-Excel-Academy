<?php
$pageTitle = 'Kitchen Inventory';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen_stock_taking.php');

// Check permission
if (!current_admin_has_permission($pdo, 'kitchen.access')) {
    flash('error', 'You do not have permission to access Kitchen.');
    redirect('admin/dashboard.php');
}

$canRecordUsage = current_admin_has_permission($pdo, 'kitchen.record_usage');

$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['item_id'] ?? 0));
$editingWeeklyPurchase = false;
$form = [
    'id' => '',
    'item_name' => '',
    'quantity' => '',
    'unit' => 'kg',
    'unit_price' => '',
    'item_date' => date('Y-m-d'),
    'supplier' => '',
    'category' => 'Kitchen',
    'opening_stock' => 0,
    'min_stock_level' => 0,
    'purchase_type' => 'daily',
    'daily_quantity' => '',
    'daily_amount' => '',
    'daily_notes' => '',
];

// ensure kitchen support tables exist
ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);
$unitOptions = kitchen_available_unit_options($pdo);

$formToken = $_SESSION['kitchen_form_token'] ?? '';

$postTokenValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim($_POST['form_token'] ?? '');
    if (!$postedToken || !$formToken || !hash_equals($formToken, $postedToken)) {
        $errors[] = 'Duplicate or invalid form submission detected. Please refresh the page and try again.';
        $postTokenValid = false;
        unset($_SESSION['kitchen_form_token']);
        $formToken = '';
    }
}

if (!$formToken) {
    $formToken = bin2hex(random_bytes(16));
    $_SESSION['kitchen_form_token'] = $formToken;
}

function kitchen_item_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM kitchen_inventory WHERE id = :id");
    $statement->execute(['id' => $id]);
    $item = $statement->fetch();

    return $item ?: null;
}

function kitchen_duplicate_exists(PDO $pdo, string $itemName, string $itemDate, string $supplier, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM kitchen_inventory
            WHERE item_name = :item_name
              AND item_date = :item_date
              AND COALESCE(supplier, '') = :supplier";
    $params = [
        'item_name' => $itemName,
        'item_date' => $itemDate,
        'supplier' => $supplier,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $item = kitchen_item_by_id($pdo, $editingId);
    if ($item) {
        $form = array_merge($form, $item);
        $editingWeeklyPurchase = ($form['purchase_type'] ?? 'daily') === 'weekly';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postTokenValid) {
    $action = $_POST['action'] ?? 'save';
    $itemId = (int) ($_POST['item_id'] ?? 0);

    if (in_array($action, ['save', 'update'], true) && $itemId > 0) {
        $existingItem = kitchen_item_by_id($pdo, $itemId);
        if ($existingItem && ($existingItem['purchase_type'] ?? '') === 'weekly') {
            flash('error', 'Weekly shopping purchases cannot be managed here. Use Weekly Shopping instead.');
            unset($_SESSION['kitchen_form_token']);
            redirect('admin/kitchen_inventory.php');
        }
    }

    // Handle daily usage batch entry
    if ($action === 'daily_usage') {
        $usageData = $_POST['daily_usage'] ?? [];
        $itemsRecorded = 0;
        if (is_array($usageData) && count($usageData) > 0) {
            $recordedBy = $_SESSION['admin_id'] ?? null;
            foreach ($usageData as $itemName => $qty) {
                $itemName = trim((string)$itemName);
                $qty = (float)$qty;
                if ($itemName !== '' && $qty > 0) {
                    $kitem = kitchen_get_or_create_item($pdo, $itemName);
                    kitchen_record_movement($pdo, (int)$kitem['id'], 'out', $qty, 0.0, 0.0, null, 'Daily usage on ' . date('Y-m-d'), $recordedBy);
                    $itemsRecorded++;
                }
            }
        }
        if ($itemsRecorded > 0) {
            flash('success', 'Daily usage recorded for ' . $itemsRecorded . ' item(s).');
        } else {
            flash('error', 'No items recorded.');
        }
        unset($_SESSION['kitchen_form_token']);
        redirect('admin/kitchen_inventory.php');
    }

    // Handle single-item daily usage (from compact usage table)
    if ($action === 'daily_usage_single') {
        $itemName = trim($_POST['daily_item'] ?? '');
        $qty = (float) ($_POST['daily_qty'] ?? 0);
        $unit = trim((string) ($_POST['daily_unit'] ?? ''));
        if ($itemName === '' || $qty <= 0 || !array_key_exists($unit, $unitOptions)) {
            flash('error', 'Provide an item, quantity, and valid unit to record usage.');
        } else {
            $kitem = kitchen_get_or_create_item($pdo, $itemName);
            $recordedBy = $_SESSION['admin_id'] ?? null;
            kitchen_record_movement($pdo, (int)$kitem['id'], 'out', $qty, 0.0, 0.0, null, 'Daily usage on ' . date('Y-m-d'), $recordedBy, null, $unit);
            flash('success', 'Usage recorded for ' . h($itemName) . ' (' . $qty . ' ' . h($unit) . ').');
        }
        unset($_SESSION['kitchen_form_token']);
        redirect('admin/kitchen_inventory.php');
    }

    // Handle stock in/out quick actions
    elseif (in_array($action, ['stock_in', 'stock_out'], true)) {
        $siName = trim($_POST['stock_item_name'] ?? '');
        $siQty = (float) ($_POST['stock_quantity'] ?? 0);
        $siNote = trim($_POST['stock_note'] ?? '');
        if ($siName === '' || $siQty <= 0) {
            flash('error', 'Provide item and quantity for stock movement.');
        } else {
            $kitem = kitchen_get_or_create_item($pdo, $siName);
            $type = $action === 'stock_in' ? 'in' : 'out';
            $recordedBy = $_SESSION['admin_id'] ?? null;
            kitchen_record_movement($pdo, (int)$kitem['id'], $type, $siQty, 0.0, 0.0, null, $siNote, $recordedBy);
            flash('success', 'Stock movement recorded.');
        }
        unset($_SESSION['kitchen_form_token']);
        redirect('admin/kitchen_inventory.php');
    }

    elseif ($action === 'delete') {
        $item = $itemId > 0 ? kitchen_item_by_id($pdo, $itemId) : null;

        if (!$item) {
            flash('error', 'Choose an item to delete.');
        } else {
            if (($item['purchase_type'] ?? 'daily') === 'weekly') {
                kitchen_delete_weekly_purchase_row($pdo, $item);
            } else {
                kitchen_delete_purchase_movement($pdo, $itemId);
            }

            $statement = $pdo->prepare("DELETE FROM kitchen_inventory WHERE id = :id");
            $statement->execute(['id' => $itemId]);
            flash('success', 'Kitchen item deleted successfully.');
        }
        unset($_SESSION['kitchen_form_token']);
        redirect('admin/kitchen_inventory.php');
    }

    // Handle weekly shopping bulk insert
    elseif ($action === 'weekly_shopping') {
        $wsDate = trim($_POST['ws_date'] ?? date('Y-m-d'));
        $names = $_POST['ws_item_name'] ?? [];
        $qtys = $_POST['ws_quantity'] ?? [];
        $units = $_POST['ws_unit'] ?? [];
        $prices = $_POST['ws_unit_price'] ?? [];
        $suppliers = $_POST['ws_supplier'] ?? [];
        $items = [];
        for ($i = 0; $i < count($names); $i++) {
            $n = trim((string)($names[$i] ?? ''));
            $q = (float)($qtys[$i] ?? 0);
            $u = trim((string)($units[$i] ?? ''));
            $p = (float)($prices[$i] ?? 0);
            $s = trim((string)($suppliers[$i] ?? ''));
            if ($n !== '' && $q > 0) {
                $items[] = ['item_name' => $n, 'quantity' => $q, 'unit' => $u, 'unit_price' => $p, 'supplier' => $s];
            }
        }
        if (count($items) === 0) {
            flash('error', 'Add at least one weekly shopping item.');
            unset($_SESSION['kitchen_form_token']);
            redirect('admin/kitchen_inventory.php');
        }
        $wsId = record_weekly_shopping($pdo, '', $wsDate, $items);
        flash('success', 'Weekly shopping saved (#' . $wsId . ').');
        unset($_SESSION['kitchen_form_token']);
        redirect('admin/kitchen_inventory.php');
    }

    else {
        $form = [
            'id' => $itemId,
            'item_name' => trim($_POST['item_name'] ?? ''),
            'quantity' => trim($_POST['quantity'] ?? ''),
            'unit' => trim($_POST['unit'] ?? 'kg'),
            'unit_price' => trim($_POST['unit_price'] ?? ''),
            'item_date' => trim($_POST['item_date'] ?? $form['item_date']),
            'supplier' => trim($_POST['supplier'] ?? ''),
            'category' => trim($_POST['category'] ?? 'Kitchen'),
            'opening_stock' => trim($_POST['opening_stock'] ?? $form['opening_stock']),
            'min_stock_level' => trim($_POST['min_stock_level'] ?? $form['min_stock_level']),
            'purchase_type' => trim($_POST['purchase_type'] ?? ($itemId > 0 ? $form['purchase_type'] : 'daily')),
            'daily_quantity' => trim($_POST['daily_quantity'] ?? ''),
            'daily_amount' => trim($_POST['daily_amount'] ?? ''),
            'daily_notes' => trim($_POST['daily_notes'] ?? ''),
        ];

        if ($form['item_name'] === '') {
            $errors[] = 'Item name is required.';
        }

        if (!array_key_exists($form['unit'], $unitOptions)) {
            $errors[] = 'Please select a valid unit for this item.';
        }

        if ($form['min_stock_level'] !== '' && !valid_quantity_value($form['min_stock_level'])) {
            $errors[] = 'Minimum stock level must be a valid number.';
        }

        if ($form['purchase_type'] === 'daily') {
            if (!valid_quantity_value($form['daily_quantity']) || (float)$form['daily_quantity'] <= 0) {
                $errors[] = 'Daily purchase quantity must be greater than zero.';
            }
            if (!valid_money_value($form['daily_amount']) || (float)$form['daily_amount'] <= 0) {
                $errors[] = 'Daily purchase amount must be greater than zero.';
            }
            if (!valid_date_value($form['item_date'])) {
                $errors[] = 'Date must be valid.';
            }
        } else {
            $errors[] = 'Weekly stock purchases must be added through Weekly Shopping.';
        }

        if (!$errors) {
            if ($form['purchase_type'] === 'daily') {
                // record daily purchase (does not affect stock)
                $amount = (float) $form['daily_amount'];
                $quantity = (float) $form['daily_quantity'];
                record_daily_purchase($pdo, $form['item_name'], $amount, $quantity, $form['category'] ?: 'Kitchen', $form['supplier'], $form['daily_notes'], $form['item_date']);
                flash('success', 'Daily purchase recorded.');
            } else {
                $totalAmount = (float) $form['quantity'] * (float) $form['unit_price'];
                $params = [
                    'item_name' => $form['item_name'],
                    'quantity' => (float) $form['quantity'],
                    'unit' => $form['unit'],
                    'unit_price' => (float) $form['unit_price'],
                    'total_amount' => $totalAmount,
                    'item_date' => $form['item_date'],
                    'supplier' => $form['supplier'],
                    'purchase_type' => 'daily',
                    'category' => $form['category'] ?: 'Kitchen',
                ];

                $hasPurchaseType = function_exists('db_has_column') && db_has_column($pdo, 'kitchen_inventory', 'purchase_type');
                $hasTermColumns = $hasPurchaseType && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term');

                if ($action === 'update' && $itemId > 0) {
                    $params['id'] = $itemId;
                    if ($hasPurchaseType) {
                        $statement = $pdo->prepare(
                            "UPDATE kitchen_inventory
                             SET item_name = :item_name,
                                 quantity = :quantity,
                                 unit = :unit,
                                 unit_price = :unit_price,
                                 total_amount = :total_amount,
                                 item_date = :item_date,
                                 supplier = :supplier,
                                 purchase_type = :purchase_type,
                                 category = :category" . ($hasTermColumns ? ",\n                                 academic_year = :academic_year,\n                                 term = :term" : "") . "\n                             WHERE id = :id"
                        );
                        if ($hasTermColumns) {
                            $params['academic_year'] = $currentContext['academic_year'];
                            $params['term'] = $currentContext['term'];
                        }
                    } else {
                        unset($params['purchase_type']);
                        $statement = $pdo->prepare(
                            "UPDATE kitchen_inventory
                             SET item_name = :item_name,
                                 quantity = :quantity,
                                 unit = :unit,
                                 unit_price = :unit_price,
                                 total_amount = :total_amount,
                                 item_date = :item_date,
                                 supplier = :supplier,
                                 category = :category
                             WHERE id = :id"
                        );
                    }
                    $statement->execute($params);

                    $kitem = kitchen_get_or_create_item($pdo, $form['item_name'], $form['category'], (float)$form['opening_stock'], (float)$form['min_stock_level'], $form['unit']);
                    kitchen_sync_purchase_movement(
                        $pdo,
                        $itemId,
                        $form['item_name'],
                        (float)$form['quantity'],
                        (float)$form['unit_price'],
                        $totalAmount,
                        'Purchase from ' . $form['supplier'],
                        $_SESSION['admin_id'] ?? null
                    );

                    flash('success', 'Kitchen item updated successfully.');
                } else {
                    if ($hasPurchaseType) {
                        if ($hasTermColumns) {
                            $params['academic_year'] = $currentContext['academic_year'];
                            $params['term'] = $currentContext['term'];
                            $statement = $pdo->prepare(
                                "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, purchase_type, category, academic_year, term)
                                 VALUES (:item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, :purchase_type, :category, :academic_year, :term)"
                            );
                        } else {
                            $statement = $pdo->prepare(
                                "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, purchase_type, category)
                                 VALUES (:item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, :purchase_type, :category)"
                            );
                        }
                        $statement->execute($params);
                    } else {
                        unset($params['purchase_type']);
                        $statement = $pdo->prepare(
                            "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, category)
                             VALUES (:item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, :category)"
                        );
                        $statement->execute($params);
                    }
                    $newId = (int) $pdo->lastInsertId();
                    // Ensure kitchen item master exists and record the purchase movement
                    $kitem = kitchen_get_or_create_item($pdo, $form['item_name'], $form['category'], (float)$form['opening_stock'], (float)$form['min_stock_level'], $form['unit']);
                    kitchen_record_movement($pdo, (int)$kitem['id'], 'purchase', (float)$form['quantity'], (float)$form['unit_price'], $totalAmount, $newId, 'Purchase from ' . $form['supplier'], $_SESSION['admin_id'] ?? null, $form['item_date']);
                    // Also create a school expense record for reporting
                    $expStmt = $pdo->prepare("INSERT INTO school_expenses (item_name, category, amount, quantity, unit, total_amount, expense_date, description) VALUES (:item_name, :category, :amount, :quantity, :unit, :total_amount, :expense_date, :description)");
                    $expStmt->execute([
                        'item_name' => $form['item_name'],
                        'category' => $form['category'] ?: 'Kitchen',
                        'amount' => (float)$form['unit_price'],
                        'quantity' => (float)$form['quantity'],
                        'unit' => $form['unit'],
                        'total_amount' => $totalAmount,
                        'expense_date' => $form['item_date'],
                        'description' => 'Supplier: ' . $form['supplier'],
                    ]);
                    flash('success', 'Kitchen item added successfully and expense recorded.');
                }
            }

            unset($_SESSION['kitchen_form_token']);
            redirect('admin/kitchen_inventory.php');
        }
    }
}

$search = trim($_GET['search'] ?? '');
$dateFrom = valid_date_value($_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$dateTo = valid_date_value($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(item_name LIKE :search_item OR supplier LIKE :search_supplier)';
    $params['search_item'] = '%' . $search . '%';
    $params['search_supplier'] = '%' . $search . '%';
}
if ($dateFrom !== '') {
    $where[] = 'item_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'item_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$sql = "SELECT * FROM kitchen_inventory";
if (db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
    $where[] = 'academic_year = :academic_year';
    $where[] = 'term = :term';
    $params['academic_year'] = $currentContext['academic_year'];
    $params['term'] = $currentContext['term'];
} elseif (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $where[] = 'item_date BETWEEN :term_start AND :term_end';
    $params['term_start'] = $currentContext['start_date'];
    $params['term_end'] = $currentContext['end_date'];
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY item_date DESC, id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$items = $statement->fetchAll();
$totalInventory = array_sum(array_map(fn($item) => (float) $item['total_amount'], $items));

$summary = kitchen_aggregated_summary($pdo);

$lowStockCount = 0;
$outOfStockCount = 0;
foreach ($summary as $row) {
    if ($row['remaining_stock'] <= 0) {
        $outOfStockCount++;
    } elseif ($row['remaining_stock'] <= $row['min_stock_level']) {
        $lowStockCount++;
    }
}
$inventorySummaryCount = count($summary);
$stockValue = kitchen_inventory_valuation($pdo);
$termExpense = 0;
if (db_has_column($pdo, 'school_expenses', 'total_amount')) {
    $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM school_expenses WHERE category IN ('Kitchen', 'WHOLESALE', 'Kitchen Purchases')";
    $params = [];

    if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
        $sql .= ' AND academic_year = :academic_year AND term = :term';
        $params['academic_year'] = $currentContext['academic_year'];
        $params['term'] = $currentContext['term'];
    } elseif (!empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
        $sql .= ' AND expense_date BETWEEN :term_start AND :term_end';
        $params['term_start'] = $currentContext['start_date'];
        $params['term_end'] = $currentContext['end_date'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $termExpense = (float) $stmt->fetchColumn();
}

$lowStockItems = low_stock_kitchen_items($pdo);
$recentTransactions = [];
$recentSql = "SELECT ksm.*, ki.item_name, ki.unit, ki.category FROM kitchen_stock_movements ksm JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id";
$recentParams = [];
if (db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
    $recentSql .= " WHERE ksm.academic_year = :academic_year AND ksm.term = :term";
    $recentParams['academic_year'] = $currentContext['academic_year'];
    $recentParams['term'] = $currentContext['term'];
}
$recentSql .= " ORDER BY ksm.transaction_date DESC, ksm.id DESC LIMIT 10";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($recentParams);
$recentTransactions = $recentStmt->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen</p>
        <h1>Kitchen Inventory</h1>
        <p class="text-muted mb-0">Manage kitchen stock, purchases and consumption.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/reports.php') ?>">Reports</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_reports.php') ?>">Kitchen Reports</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_weekly_shopping.php') ?>">Weekly Shopping</a>
        <a class="btn btn-success" href="<?= url('admin/kitchen_daily_purchase.php') ?>">Daily Purchase</a>
        <a class="btn btn-warning" target="_blank" href="<?= url('admin/kitchen_reports_export.php?report=low_stock&format=pdf') ?>">Export Low Stock (PDF)</a>
    </div>
</div>

<!-- Flash messages are shown in header -->
<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$summary): ?>
    <div class="alert alert-info">
        <strong>No kitchen inventory data available yet.</strong>
        Use <a href="<?= url('admin/kitchen_weekly_shopping.php') ?>">Weekly Shopping</a> or <a href="<?= url('admin/kitchen_daily_purchase.php') ?>">Daily Purchase</a> to add stock and start tracking inventory.
    </div>
<?php endif; ?>

<div class="inventory-summary-cards mb-4">
    <div class="dashboard-metrics">
        <div class="dashboard-stat-card metric-card">
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Items Tracked</div>
                    <div class="stat-value"><?= h((string) $inventorySummaryCount) ?></div>
                </div>
            </div>
            <p class="stat-note">All items</p>
        </div>
        <div class="dashboard-stat-card metric-card">
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Out of Stock Items</div>
                    <div class="stat-value"><?= h((string) $outOfStockCount) ?></div>
                </div>
            </div>
            <p class="stat-note">Needs attention</p>
        </div>
        <div class="dashboard-stat-card metric-card">
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Termly Usage Cost</div>
                    <div class="stat-value"><?= money($termExpense) ?></div>
                </div>
            </div>
            <p class="stat-note">Added to expenses</p>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <section class="panel" id="inventory-dashboard">
            <div class="panel-heading d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h2>Inventory Dashboard</h2>
                    <p class="text-muted mb-0">Monitor stock levels, value, availability, and usage from one dashboard. Use the dedicated procurement pages for purchases.</p>
                </div>
            </div>

            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered inventory-summary-table" style="table-layout: fixed;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:22%; padding:12px; font-weight:600; text-align:left;">Item</th>
                            <th style="width:10%; padding:12px; font-weight:600; text-align:center;">Unit</th>
                            <th style="width:10%; padding:12px; font-weight:600; text-align:right;">Unit Price</th>
                            <th style="width:12%; padding:12px; font-weight:600; text-align:right;">Stock Value</th>
                            <th style="width:10%; padding:12px; font-weight:600; text-align:center;">Remaining</th>
                            <th style="width:12%; padding:12px; font-weight:600; text-align:center;">Status</th>
                            <th style="width:16%; padding:12px; font-weight:600; text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary as $s): ?>
                            <tr>
                                <td><?= h($s['item_name']) ?></td>
                                <td class="text-center"><?= h($s['unit']) ?></td>
                                <td class="text-end"><?= money($s['unit_price']) ?></td>
                                <td class="text-end"><?= money($s['stock_value']) ?></td>
                                <td class="text-center"><?= h((string)$s['remaining_stock']) ?></td>
                                <td class="text-center">
                                    <?php if ($s['remaining_stock'] <= 0): ?>
                                        <span class="badge bg-danger">Out of Stock</span>
                                    <?php elseif ($s['remaining_stock'] <= $s['min_stock_level']): ?>
                                        <span class="badge bg-warning text-dark">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline-flex align-items-center gap-2">
                                        <input type="hidden" name="form_token" value="<?= h($formToken) ?>">
                                        <input type="hidden" name="action" value="daily_usage_single">
                                        <input type="hidden" name="daily_item" value="<?= h($s['item_name']) ?>">
                                        <div class="input-group input-group-sm" style="max-width:190px">
                                            <input class="form-control" name="daily_qty" type="number" step="0.01" min="0.01" placeholder="Qty">
                                            <select class="form-select" name="daily_unit" aria-label="Quantity unit for <?= h($s['item_name']) ?>">
                                                <?php foreach ($unitOptions as $unitCode => $unitLabel): ?>
                                                    <option value="<?= h($unitCode) ?>" <?= $unitCode === $s['unit'] ? 'selected' : '' ?>><?= h($unitLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php if ($canRecordUsage): ?>
                                            <button class="btn btn-sm btn-primary" type="submit">Record Usage</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-primary" type="button" disabled>Record Usage</button>
                                        <?php endif; ?>
                                    </form>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/kitchen_reports.php') ?>">History</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$summary): ?>
                            <tr><td colspan="7">No inventory data available. Add stock through the kitchen procurement pages to begin tracking.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <section class="panel">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <div>
                    <h2>Recent Transactions</h2>
                    <p class="text-muted mb-0">Latest stock movements for review and audit.</p>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/kitchen_reports.php') ?>">Full Kitchen Report</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle recent-transactions" style="table-layout: fixed;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:24%; padding:12px; font-weight:600; text-align:left;">Item</th>
                            <th style="width:12%; padding:12px; font-weight:600; text-align:center;">Type</th>
                            <th style="width:12%; padding:12px; font-weight:600; text-align:center;">Qty</th>
                            <th style="width:12%; padding:12px; font-weight:600; text-align:right;">Unit Price</th>
                            <th style="width:16%; padding:12px; font-weight:600; text-align:left;">Date</th>
                            <th style="width:24%; padding:12px; font-weight:600; text-align:left;">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $txn): ?>
                            <tr>
                                <td><?= h($txn['item_name']) ?></td>
                                <td class="text-center"><?= h(ucfirst($txn['movement_type'])) ?></td>
                                <td class="text-center"><?= h((string)$txn['quantity']) ?> <?= h($txn['unit'] ?? '') ?></td>
                                <td class="text-end"><?= money((float)$txn['unit_price']) ?></td>
                                <td><?= h($txn['transaction_date']) ?></td>
                                <td><?= h($txn['note'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recentTransactions): ?>
                            <tr><td colspan="6">No recent stock movements found. Record usage or purchases from the kitchen pages.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

