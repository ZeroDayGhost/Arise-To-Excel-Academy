<?php
$pageTitle = 'Kitchen Expenses';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen.php');
ensure_kitchen_tables($pdo);
$manageKitchen = kitchen_current_access_level($pdo) === 'manage';
$unitOptions = kitchen_available_unit_options($pdo);
$categories = ['Food', 'Gas', 'Cooking Supplies', 'Other'];
$errors = [];
$purchaseToken = $_SESSION['kitchen_purchase_request_token'] ?? '';
$formToken = $_SESSION['kitchen_stock_form_token'] ?? '';
if ($purchaseToken === '') {
    $purchaseToken = bin2hex(random_bytes(32));
    $_SESSION['kitchen_purchase_request_token'] = $purchaseToken;
}
if ($formToken === '') {
    $formToken = bin2hex(random_bytes(32));
    $_SESSION['kitchen_stock_form_token'] = $formToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kitchen_require_access($pdo, true);
    $postedFormToken = trim((string) ($_POST['form_token'] ?? ''));
    if ($postedFormToken === '' || !hash_equals($formToken, $postedFormToken)) {
        $errors[] = 'This form has expired. Refresh the page and try again.';
    } else {
        try {
            $actorId = (int) ($_SESSION['admin_id'] ?? 0);
            if (($_POST['action'] ?? '') === 'record_purchase') {
                if (!hash_equals($purchaseToken, trim((string) ($_POST['request_token'] ?? '')))) {
                    throw new InvalidArgumentException('This purchase form has already been used. Refresh before recording another purchase.');
                }
                $saved = kitchen_record_purchase($pdo, [
                    'request_token' => $purchaseToken,
                    'item_name' => trim((string) ($_POST['item_name'] ?? '')),
                    'category' => trim((string) ($_POST['category'] ?? '')),
                    'quantity' => (float) ($_POST['quantity'] ?? 0),
                    'unit' => trim((string) ($_POST['unit'] ?? '')),
                    'unit_price' => (float) ($_POST['unit_price'] ?? 0),
                    'purchase_date' => trim((string) ($_POST['purchase_date'] ?? '')),
                    'supplier' => trim((string) ($_POST['supplier'] ?? '')),
                    'track_stock' => ($_POST['track_stock'] ?? '0') === '1',
                ], $actorId);
                $_SESSION['kitchen_purchase_request_token'] = bin2hex(random_bytes(32));
                $_SESSION['kitchen_stock_form_token'] = bin2hex(random_bytes(32));
                flash('success', $saved['created'] ? 'Purchase recorded and linked to School Expenses.' : 'This purchase was already recorded; no duplicate was added.');
                redirect('admin/kitchen_stock_taking.php#stock-records');
            }

            if (($_POST['action'] ?? '') === 'adjust_stock') {
                $result = kitchen_adjust_stock(
                    $pdo,
                    (int) ($_POST['item_id'] ?? 0),
                    (float) ($_POST['counted_quantity'] ?? -1),
                    trim((string) ($_POST['count_date'] ?? '')),
                    trim((string) ($_POST['reason'] ?? '')),
                    $actorId
                );
                $_SESSION['kitchen_stock_form_token'] = bin2hex(random_bytes(32));
                flash('success', 'Stock count saved and added to the kitchen audit log.');
                redirect('admin/kitchen_stock_taking.php#stock-records');
            }

            throw new InvalidArgumentException('Choose a valid kitchen action.');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Kitchen stock-taking failure: ' . $exception->getMessage());
            $errors[] = 'The kitchen record could not be saved. No partial expense or stock entry was kept.';
        }
    }
}

$summary = kitchen_aggregated_summary($pdo, null, null, true);
$monthlySpend = kitchen_expense_total($pdo, date('Y-m-01'), date('Y-m-d'));
$history = $pdo->query(
    "SELECT * FROM (
        SELECT kp.purchase_date AS event_date, kp.item_name, kp.category, kp.quantity, kp.unit,
               kp.unit_price, kp.total_amount, kp.supplier, kp.track_stock, 'Secretary purchase' AS source,
               a.name AS recorded_by
        FROM kitchen_purchase_records kp LEFT JOIN admin a ON a.id = kp.recorded_by
        UNION ALL
        SELECT dp.purchase_date AS event_date, dp.item_name, dp.category, dp.quantity, COALESCE(dp.unit, '') AS unit,
               CASE WHEN dp.quantity > 0 THEN dp.amount / dp.quantity ELSE dp.amount END AS unit_price,
               dp.amount AS total_amount, dp.supplier, 0 AS track_stock, 'Legacy daily purchase' AS source,
               NULL AS recorded_by
        FROM kitchen_daily_purchases dp
        UNION ALL
        SELECT w.shopping_date AS event_date, wi.item_name, 'Food' AS category, wi.quantity, COALESCE(wi.unit, '') AS unit,
               wi.unit_price, wi.total_amount, COALESCE(wi.supplier, w.supplier) AS supplier, 1 AS track_stock,
               'Legacy weekly purchase' AS source, NULL AS recorded_by
        FROM weekly_shopping_items wi JOIN weekly_shopping w ON w.id = wi.weekly_shopping_id
        UNION ALL
        SELECT inv.item_date AS event_date, inv.item_name, COALESCE(inv.category, 'Other') AS category,
               inv.quantity, COALESCE(inv.unit, '') AS unit, inv.unit_price, inv.total_amount, inv.supplier,
               1 AS track_stock, 'Legacy stock record' AS source, NULL AS recorded_by
        FROM kitchen_inventory inv
        WHERE NOT EXISTS (
            SELECT 1 FROM weekly_shopping_items wi
            JOIN weekly_shopping w ON w.id = wi.weekly_shopping_id
            WHERE LOWER(TRIM(wi.item_name)) = LOWER(TRIM(inv.item_name))
              AND w.shopping_date = inv.item_date
              AND wi.quantity = inv.quantity
              AND wi.total_amount = inv.total_amount
        )
    ) AS kitchen_history
    ORDER BY event_date DESC, item_name ASC
    LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
$audit = $pdo->query(
    "SELECT l.*, a.name AS recorded_by_name, ki.item_name AS stock_item_name
     FROM kitchen_audit_log l
     LEFT JOIN admin a ON a.id = l.recorded_by
     LEFT JOIN kitchen_items ki ON l.record_type = 'stock' AND ki.id = l.record_id
     ORDER BY l.created_at DESC, l.id DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen Accountability</p>
        <h1>Stock Taking</h1>
        <p class="text-muted mb-0">Record each kitchen purchase once, then reconcile the physical stock when needed.</p>
    </div>
    <?php if ($manageKitchen): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="#stock-taking"><i class="fa-solid fa-cart-plus me-2"></i>Record Purchase</a>
            <a class="btn btn-outline-primary" href="#stock-records"><i class="fa-solid fa-clipboard-check me-2"></i>Update Stock</a>
        </div>
    <?php else: ?>
        <p class="small text-muted mb-0">Purchase entry and stock updates are available to the Secretary.</p>
    <?php endif; ?>
</div>

<nav class="kitchen-module-nav panel mb-4" aria-label="Kitchen pages">
    <a class="active" href="<?= url('admin/kitchen_stock_taking.php') ?>"><i class="fa-solid fa-clipboard-check"></i> Stock Taking</a>
    <a href="<?= url('admin/kitchen_stock_taking.php#stock-records') ?>"><i class="fa-solid fa-boxes-stacked"></i> Kitchen Stock Records</a>
    <a href="<?= url('admin/kitchen_expenditure.php') ?>"><i class="fa-solid fa-money-bill-wave"></i> Kitchen Expenditure</a>
</nav>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<section class="kitchen-total-banner mb-4">
    <div><span>Kitchen expenditure this month</span><strong><?= money($monthlySpend) ?></strong></div>
    <a class="btn btn-outline-primary" href="<?= url('admin/kitchen_expenditure.php') ?>">View expenditure</a>
</section>

<?php if ($manageKitchen): ?>
<div class="row g-4 mb-4" id="stock-taking">
    <div class="col-12 col-xl-8">
        <section class="panel h-100">
            <div class="panel-heading"><h2>Record a Purchase</h2></div>
            <form method="post" class="row g-3" id="kitchen-purchase-form">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="record_purchase">
                <input type="hidden" name="form_token" value="<?= h($formToken) ?>">
                <input type="hidden" name="request_token" value="<?= h($purchaseToken) ?>">
                <div class="col-md-6">
                    <label class="form-label" for="item_name">Item name</label>
                    <input class="form-control" id="item_name" name="item_name" maxlength="120" required value="<?= h($_POST['item_name'] ?? '') ?>" placeholder="e.g. Maize flour">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <option value="">Choose category</option>
                        <?php foreach ($categories as $category): ?><option value="<?= h($category) ?>" <?= ($_POST['category'] ?? '') === $category ? 'selected' : '' ?>><?= h($category) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="quantity">Quantity</label>
                    <input class="form-control" id="quantity" name="quantity" type="number" min="0.01" step="0.01" required value="<?= h($_POST['quantity'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="unit">Unit</label>
                    <select class="form-select" id="unit" name="unit" required>
                        <?php foreach ($unitOptions as $code => $label): ?><option value="<?= h($code) ?>" <?= ($_POST['unit'] ?? 'kg') === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="unit_price">Unit price (KES)</label>
                    <input class="form-control" id="unit_price" name="unit_price" type="number" min="0.01" step="0.01" required value="<?= h($_POST['unit_price'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="total_cost">Total cost (KES)</label>
                    <input class="form-control" id="total_cost" type="text" readonly value="KES 0.00" aria-live="polite">
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="purchase_date">Purchase date</label>
                    <input class="form-control" id="purchase_date" name="purchase_date" type="date" required value="<?= h($_POST['purchase_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="supplier">Supplier (optional)</label>
                    <input class="form-control" id="supplier" name="supplier" maxlength="150" value="<?= h($_POST['supplier'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="kitchen-stock-toggle" for="track_stock">
                        <input class="form-check-input" id="track_stock" name="track_stock" type="checkbox" value="1" <?= !isset($_POST['track_stock']) || ($_POST['track_stock'] ?? '') === '1' ? 'checked' : '' ?>>
                        <span><strong>Add quantity to kitchen stock</strong><small>Leave this off for gas or other costs that should be recorded as an expense only.</small></span>
                    </label>
                </div>
                <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save purchase</button></div>
            </form>
        </section>
    </div>
    <div class="col-12 col-xl-4">
        <section class="panel h-100">
            <div class="panel-heading"><h2>Stock At A Glance</h2></div>
            <div class="kitchen-stock-metrics">
                <div><span>Items tracked</span><strong><?= count($summary) ?></strong></div>
                <div><span>Low or out of stock</span><strong><?= count(array_filter($summary, static fn($row) => (float) $row['remaining_stock'] <= (float) $row['min_stock_level'])) ?></strong></div>
            </div>
            <p class="text-muted small mb-0">Stock is based on purchases marked for stock, opening balances, and the Secretary’s count adjustments.</p>
        </section>
    </div>
</div>
<?php endif; ?>

<section class="panel mb-4" id="stock-records">
    <div class="panel-heading d-flex justify-content-between align-items-start gap-3">
        <div><h2>Kitchen Stock Records</h2><p class="text-muted mb-0">Current balances include all saved periods.</p></div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Item</th><th>Category</th><th>On hand</th><th>Unit</th><th>Unit value</th><th>Status</th><?php if ($manageKitchen): ?><th>Stock count</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($summary as $row): $low = (float) $row['remaining_stock'] <= (float) $row['min_stock_level']; ?>
                <tr>
                    <td><?= h($row['item_name']) ?></td>
                    <td><?= h($row['category']) ?></td>
                    <td><?= number_format((float) $row['remaining_stock'], 2) ?></td>
                    <td><?= h($row['unit']) ?></td>
                    <td><?= money((float) $row['unit_price']) ?></td>
                    <td><span class="badge <?= $low ? 'bg-warning text-dark' : 'bg-success' ?>"><?= (float) $row['remaining_stock'] <= 0 ? 'Out of stock' : ($low ? 'Low stock' : 'In stock') ?></span></td>
                    <?php if ($manageKitchen): ?><td>
                        <details class="kitchen-adjust-details"><summary>Update count</summary>
                            <form method="post" class="kitchen-adjust-form">
                                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="adjust_stock">
                                <input type="hidden" name="form_token" value="<?= h($formToken) ?>">
                                <input type="hidden" name="item_id" value="<?= (int) $row['id'] ?>">
                                <label class="form-label">Physical count</label>
                                <input class="form-control form-control-sm mb-2" name="counted_quantity" type="number" min="0" step="0.01" required value="<?= h(number_format((float) $row['remaining_stock'], 2, '.', '')) ?>">
                                <label class="form-label">Count date</label>
                                <input class="form-control form-control-sm mb-2" name="count_date" type="date" required value="<?= h(date('Y-m-d')) ?>">
                                <label class="form-label">Reason</label>
                                <input class="form-control form-control-sm mb-2" name="reason" maxlength="500" required placeholder="e.g. Monthly physical count">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save count</button>
                            </form>
                        </details>
                    </td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$summary): ?><tr><td colspan="<?= $manageKitchen ? 7 : 6 ?>" class="text-muted">No tracked kitchen stock yet. Record a purchase and choose “Add quantity to kitchen stock.”</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-4">
    <div class="panel-heading"><h2>Purchase History</h2><p class="text-muted mb-0">New records and preserved daily, weekly, and stock-entry history.</p></div>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Date</th><th>Item</th><th>Category</th><th>Quantity</th><th>Total</th><th>Stock</th><th>Source</th><th>Supplier</th></tr></thead>
        <tbody><?php foreach ($history as $entry): ?>
            <tr><td><?= h($entry['event_date']) ?></td><td><?= h($entry['item_name']) ?></td><td><?= h($entry['category']) ?></td>
                <td><?= number_format((float) $entry['quantity'], 2) ?> <?= h($entry['unit']) ?></td><td><?= money((float) $entry['total_amount']) ?></td>
                <td><?= (int) $entry['track_stock'] === 1 ? 'Tracked' : 'Expense only' ?></td><td><?= h($entry['source']) ?></td><td><?= h($entry['supplier'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$history): ?><tr><td colspan="8" class="text-muted">No kitchen purchases have been recorded.</td></tr><?php endif; ?></tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-heading"><h2>Stock Audit Log</h2><p class="text-muted mb-0">Secretary purchase entries and stock count changes are retained here.</p></div>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Date and time</th><th>Record</th><th>Action</th><th>Before</th><th>After</th><th>Details</th><th>Recorded by</th></tr></thead>
        <tbody><?php foreach ($audit as $entry): $details = json_decode((string) $entry['details'], true) ?: []; ?>
            <tr><td><?= h($entry['created_at']) ?></td><td><?= h($entry['stock_item_name'] ?: (($details['item_name'] ?? '') ?: ucfirst($entry['record_type']))) ?></td>
                <td><?= h(str_replace('_', ' ', ucfirst($entry['action']))) ?></td>
                <td><?= $entry['before_value'] === null ? '—' : number_format((float) $entry['before_value'], 2) ?></td>
                <td><?= $entry['after_value'] === null ? '—' : number_format((float) $entry['after_value'], 2) ?></td>
                <td><?= h($details['reason'] ?? ($details['category'] ?? '')) ?></td><td><?= h($entry['recorded_by_name'] ?? 'Former user') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$audit): ?><tr><td colspan="7" class="text-muted">No new stock-taking changes have been logged yet.</td></tr><?php endif; ?></tbody>
    </table></div>
</section>

<script>
(() => {
    const quantity = document.getElementById('quantity');
    const unitPrice = document.getElementById('unit_price');
    const total = document.getElementById('total_cost');
    const category = document.getElementById('category');
    const stock = document.getElementById('track_stock');
    const format = () => {
        const amount = (Number(quantity?.value) || 0) * (Number(unitPrice?.value) || 0);
        if (total) total.value = new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' }).format(amount);
    };
    quantity?.addEventListener('input', format);
    unitPrice?.addEventListener('input', format);
    category?.addEventListener('change', () => {
        if (category.value === 'Gas') stock.checked = false;
    });
    format();
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
