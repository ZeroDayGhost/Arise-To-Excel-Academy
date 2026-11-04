<?php
$pageTitle = 'Sell Uniform';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

// Module access
if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access Uniform sales.');
    redirect('admin/dashboard.php');
}

$canSellUniform = current_admin_has_permission($pdo, 'school_uniform.sell');
$canPayUniformBalance = current_admin_has_permission($pdo, 'school_uniform.pay_balance');
$canViewUniform = current_admin_has_permission($pdo, 'school_uniform.view');
$canExportUniformReports = current_admin_has_permission($pdo, 'school_uniform.export_reports');

$currentContext = current_academic_context($pdo);

$students = $pdo->query("SELECT id, registration_no, full_name, gender, class_level FROM students WHERE enrollment_status = 'Active' ORDER BY full_name ASC")->fetchAll();
$uniformRows = $pdo->query(
    "SELECT u.id AS product_id, u.uniform_name, u.category, u.gender,
            uv.id AS variant_id, uv.size, uv.selling_price, uv.opening_stock, uv.reorder_level,
            uv.opening_stock + COALESCE(SUM(sm.quantity),0) AS available_stock
     FROM uniforms u
     INNER JOIN uniform_variants uv ON uv.uniform_id = u.id AND LOWER(TRIM(uv.status)) = 'active'
     LEFT JOIN uniform_stock_movements sm ON sm.uniform_variant_id = uv.id
     WHERE LOWER(TRIM(u.status)) = 'active'
     GROUP BY u.id, uv.id
     ORDER BY u.uniform_name, uv.size"
)->fetchAll();
$uniforms = [];
foreach ($uniformRows as $uniformRow) {
    $productId = (int) $uniformRow['product_id'];
    if (!isset($uniforms[$productId])) {
        $uniforms[$productId] = [
            'id' => $productId, 'uniform_name' => $uniformRow['uniform_name'],
            'category' => $uniformRow['category'], 'gender' => $uniformRow['gender'], 'variants' => [],
        ];
    }
    $uniforms[$productId]['variants'][] = [
        'id' => (int) $uniformRow['variant_id'], 'size' => $uniformRow['size'],
        'selling_price' => (float) $uniformRow['selling_price'], 'available_stock' => (int) $uniformRow['available_stock'],
    ];
}
$uniforms = array_values($uniforms);
// Keep the summary scoped to the active period, while the records table remains
// useful for retrieving receipts from earlier academic periods.
$ctx = $currentContext;
$salesQuery = "SELECT us.*, s.full_name, s.registration_no FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id";
if (!empty($ctx['start_date']) && !empty($ctx['end_date'])) {
    $periodSalesStmt = $pdo->prepare($salesQuery . " WHERE us.payment_date >= :start_date AND us.payment_date < DATE_ADD(:end_date, INTERVAL 1 DAY) ORDER BY us.payment_date DESC");
    $periodSalesStmt->execute(['start_date' => $ctx['start_date'], 'end_date' => $ctx['end_date']]);
    $periodSales = $periodSalesStmt->fetchAll();
} else {
    $periodSalesStmt = $pdo->prepare($salesQuery . " WHERE YEAR(us.payment_date) = :year ORDER BY us.payment_date DESC");
    $periodSalesStmt->execute(['year' => $ctx['academic_year']]);
    $periodSales = $periodSalesStmt->fetchAll();
}
$salesSummary = ['grand_total' => 0.0, 'amount_paid' => 0.0, 'balance' => 0.0, 'count' => count($periodSales)];
foreach ($periodSales as $sale) {
    $salesSummary['grand_total'] += (float) ($sale['grand_total'] ?? 0);
    $salesSummary['amount_paid'] += (float) ($sale['amount_paid'] ?? 0);
    $salesSummary['balance'] += (float) ($sale['balance'] ?? 0);
}
$salesStmt = $pdo->query($salesQuery . " ORDER BY us.payment_date DESC LIMIT 100");
$sales = $salesStmt->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    // posted items come as a JSON string from the client; decode if necessary
    if (is_string($items)) {
        $decoded = json_decode($items, true);
        $items = is_array($decoded) ? $decoded : [];
    }
    // discount removed from UI; keep stored value 0.0
    $discount = 0.0;
    $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $mpesaCode = normalize_payment_code(trim($_POST['mpesa_code'] ?? ''));

    if ($studentId <= 0) {
        $errors[] = 'Select a student.';
    }
    if (!$items || !is_array($items)) {
        $errors[] = 'Add at least one uniform item.';
    }

    // verify student exists
    if ($studentId > 0) {
        $sStmt = $pdo->prepare('SELECT id, enrollment_status, gender FROM students WHERE id = :id LIMIT 1');
        $sStmt->execute(['id' => $studentId]);
        $selectedStudent = $sStmt->fetch();
        if (!$selectedStudent) {
            $errors[] = 'Selected student not found.';
        } elseif ($selectedStudent['enrollment_status'] !== 'Active') {
            $errors[] = 'Uniform sales are only available for active students.';
        }
    }

    $selectedStudent = $selectedStudent ?? null;
    $validatedItems = [];
    foreach ($items as $it) {
        $uid = (int) ($it['uniform_id'] ?? 0);
        $variantId = (int) ($it['uniform_variant_id'] ?? $it['variant_id'] ?? 0);
        $qty = filter_var($it['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($uid <= 0 || $variantId <= 0 || $qty === false || $qty <= 0) { $errors[] = 'Each item needs a uniform, size and positive whole quantity.'; break; }
        $variantStmt = $pdo->prepare(
            "SELECT u.id, u.uniform_name, u.gender, uv.id AS variant_id, uv.size, uv.selling_price, uv.status AS variant_status
             FROM uniforms u JOIN uniform_variants uv ON uv.uniform_id = u.id
             WHERE u.id = :uniform_id AND uv.id = :variant_id
               AND LOWER(TRIM(u.status)) = 'active' AND LOWER(TRIM(uv.status)) = 'active' LIMIT 1"
        );
        $variantStmt->execute(['uniform_id' => $uid, 'variant_id' => $variantId]);
        $variant = $variantStmt->fetch();
        if (!$variant) { $errors[] = 'The selected uniform size is no longer available.'; break; }
        $studentGender = strtolower(trim((string) ($selectedStudent['gender'] ?? '')));
        $productGender = strtolower(trim((string) $variant['gender']));
        $studentGender = ['female' => 'girls', 'girl' => 'girls', 'male' => 'boys', 'boy' => 'boys'][$studentGender] ?? $studentGender;
        $productGender = ['female' => 'girls', 'girl' => 'girls', 'male' => 'boys', 'boy' => 'boys'][$productGender] ?? $productGender;
        if (in_array($studentGender, ['boys', 'girls'], true) && !in_array($productGender, [$studentGender, 'unisex'], true)) {
            $errors[] = 'The selected uniform is not configured for this student\'s gender.';
            break;
        }
        $validatedItems[] = [
            'uniform_id' => $uid, 'variant_id' => $variantId, 'uniform_name' => $variant['uniform_name'],
            'size' => $variant['size'], 'quantity' => $qty, 'unit_price' => (float) $variant['selling_price'],
        ];
    }

    if (empty($validatedItems)) {
        $errors[] = 'No valid items to sell.';
    }
    if ($validatedItems) {
        $mergedItems = [];
        foreach ($validatedItems as $item) {
            $key = (string) $item['variant_id'];
            if (isset($mergedItems[$key])) {
                $mergedItems[$key]['quantity'] += $item['quantity'];
            } else {
                $mergedItems[$key] = $item;
            }
        }
        $validatedItems = array_values($mergedItems);
    }

    if (!is_numeric($_POST['amount_paid'] ?? '0') || $amountPaid < 0) $errors[] = 'Enter a valid non-negative amount paid.';
    if (!in_array($paymentMethod, ['Cash', 'Mpesa', 'Bank'], true)) $errors[] = 'Choose a valid payment method.';
    if ($paymentMethod === 'Mpesa' && $mpesaCode !== '' && payment_code_duplicate_exists($pdo, $mpesaCode, 'uniform_mpesa_code')) {
        $errors[] = 'This M-PESA code has already been used. Please use a unique code.';
    }
    if (!$errors) {
        if (!$canSellUniform) {
            flash('error', 'You do not have permission to sell uniforms.');
            redirect('admin/uniform_sales.php');
        }
        try {
            $pdo->beginTransaction();
            $studentLock = $pdo->prepare('SELECT enrollment_status FROM students WHERE id = :id FOR UPDATE');
            $studentLock->execute(['id' => $studentId]);
            if ($studentLock->fetchColumn() !== 'Active') {
                throw new InvalidArgumentException('Uniform sales are only available for active students.');
            }
            usort($validatedItems, static fn(array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);
            $variantLock = $pdo->prepare(
                "SELECT uv.id, uv.opening_stock, uv.selling_price, uv.size
                 FROM uniform_variants uv JOIN uniforms u ON u.id = uv.uniform_id
                 WHERE uv.id = :variant_id AND uv.uniform_id = :uniform_id
                   AND LOWER(TRIM(uv.status)) = 'active' AND LOWER(TRIM(u.status)) = 'active'
                 FOR UPDATE"
            );
            $stockCheck = $pdo->prepare('SELECT :opening_stock + COALESCE(SUM(quantity),0) FROM uniform_stock_movements WHERE uniform_variant_id = :variant_id');
            $subtotal = 0.0;
            foreach ($validatedItems as &$validatedItem) {
                $variantLock->execute(['variant_id' => $validatedItem['variant_id'], 'uniform_id' => $validatedItem['uniform_id']]);
                $lockedVariant = $variantLock->fetch();
                if (!$lockedVariant) throw new InvalidArgumentException('A selected uniform size is no longer active.');
                $stockCheck->execute(['opening_stock' => (int) $lockedVariant['opening_stock'], 'variant_id' => $lockedVariant['id']]);
                $available = (int) $stockCheck->fetchColumn();
                if ($available < $validatedItem['quantity']) {
                    throw new InvalidArgumentException($validatedItem['uniform_name'] . ' size ' . $lockedVariant['size'] . ' has only ' . $available . ' available.');
                }
                $validatedItem['size'] = $lockedVariant['size'];
                $validatedItem['unit_price'] = (float) $lockedVariant['selling_price'];
                $validatedItem['line_total'] = $validatedItem['quantity'] * $validatedItem['unit_price'];
                $subtotal += $validatedItem['line_total'];
            }
            unset($validatedItem);
            $grandTotal = max($subtotal - $discount, 0);
            if ($amountPaid > $grandTotal + 0.005) throw new InvalidArgumentException('Amount paid cannot exceed grand total.');
            $balance = max($grandTotal - $amountPaid, 0);
            $receiptNo = 'US-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
            $insert = $pdo->prepare('INSERT INTO uniform_sales (student_id, receipt_no, subtotal, discount, grand_total, amount_paid, balance, payment_method, mpesa_code, served_by, payment_date) VALUES (:student_id, :receipt_no, :subtotal, :discount, :grand_total, :amount_paid, :balance, :payment_method, :mpesa_code, :served_by, :payment_date)');
            $insert->execute([
                'student_id' => $studentId,
                'receipt_no' => $receiptNo,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'grand_total' => $grandTotal,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'payment_method' => $paymentMethod,
                'mpesa_code' => $mpesaCode,
                'served_by' => $_SESSION['admin_id'] ?? null,
                'payment_date' => date('Y-m-d H:i:s'),
            ]);
            $saleId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare('INSERT INTO uniform_sale_items (sale_id, uniform_id, uniform_variant_id, size, quantity, unit_price, line_total) VALUES (:sale_id, :uniform_id, :variant_id, :size, :quantity, :unit_price, :line_total)');
            $stockInsert = $pdo->prepare('INSERT INTO uniform_stock_movements (uniform_id, uniform_variant_id, movement_type, quantity, reference_id, note) VALUES (:uniform_id, :variant_id, :movement_type, :quantity, :reference_id, :note)');

            foreach ($validatedItems as $vi) {
                $itemInsert->execute([
                    'sale_id' => $saleId,
                    'uniform_id' => $vi['uniform_id'],
                    'variant_id' => $vi['variant_id'],
                    'size' => $vi['size'],
                    'quantity' => $vi['quantity'],
                    'unit_price' => $vi['unit_price'],
                    'line_total' => $vi['line_total'],
                ]);

                // record negative quantity for sale
                $stockInsert->execute([
                    'uniform_id' => $vi['uniform_id'],
                    'variant_id' => $vi['variant_id'],
                    'movement_type' => 'Sale',
                    'quantity' => -1 * $vi['quantity'],
                    'reference_id' => $saleId,
                    'note' => 'Sold via uniform sales',
                ]);
            }

            $pdo->commit();
            redirect('admin/uniform_receipt.php?id=' . $saleId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to record sale. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<main class="uniform-sales-page">
    <div class="page-title uniform-sales-heading">
        <div class="uniform-sales-title">
            <span class="uniform-sales-title-icon" aria-hidden="true"><i class="fa-solid fa-shirt"></i></span>
            <div>
                <p class="eyebrow">UNIFORM SALES</p>
                <h1>Sell Uniform</h1>
                <p class="mb-0 text-muted">Create a uniform sale and record payment.</p>
            </div>
        </div>
        <div class="action-row uniform-sales-page-actions">
            <?php if ($canPayUniformBalance): ?><a class="btn btn-primary" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right" aria-hidden="true"></i><span>Combined Payment</span></a><?php endif; ?>
            <a class="btn btn-outline-primary" href="<?= url('admin/uniforms.php') ?>"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i><span>Uniform Catalog</span></a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4 uniform-sales-layout">
        <div class="col-xl-7">
            <section class="panel uniform-sale-panel">
                <div class="uniform-sale-section-heading">
                    <span class="uniform-sale-section-icon" aria-hidden="true"><i class="fa-solid fa-cart-shopping"></i></span>
                    <div>
                        <h2>Uniform Sale Details</h2>
                        <p>Select a student, add uniform items, and record the payment.</p>
                    </div>
                </div>

                <form id="sale-form" method="post" class="uniform-sale-form">
                    <div class="mb-3">
                        <label class="form-label" for="student-select">Student <span aria-hidden="true">*</span></label>
                        <select class="form-select" name="student_id" id="student-select" required>
                            <option value="">Choose student...</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" data-gender="<?= h($s['gender']) ?>" data-class="<?= h($s['class_level']) ?>"><?= h($s['registration_no'] . ' - ' . $s['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="student-info" class="uniform-student-info" hidden>
                        <i class="fa-solid fa-user-graduate" aria-hidden="true"></i>
                        <div><strong id="si-name"></strong><span id="si-class"></span></div>
                    </div>

                    <div class="uniform-items-heading">
                        <div>
                            <h3>Items</h3>
                            <p>Add one or more items to this sale.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="sale-gender">Gender <span aria-hidden="true">*</span></label>
                            <select id="sale-gender" class="form-select" required><option value="">Choose gender...</option><option value="Girls">Girls</option><option value="Boys">Boys</option><option value="Unisex">Unisex</option></select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="uniform-select">Uniform name <span aria-hidden="true">*</span></label>
                            <select id="uniform-select" class="form-select" required disabled><option value="">Choose gender first...</option></select>
                        </div>
                    </div>
                    <div class="row g-3 align-items-end uniform-item-entry">
                        <div class="col-sm-4">
                            <label class="form-label" for="item-size">Size</label>
                            <select id="item-size" class="form-select" required disabled><option value="">Choose item first...</option></select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="item-qty">Quantity</label>
                            <input id="item-qty" type="number" min="1" step="1" class="form-control" value="1" inputmode="numeric">
                        </div>
                        <div class="col-sm-4">
                            <button type="button" id="add-item" class="btn btn-primary w-100"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add Item</span></button>
                        </div>
                        <div class="col-sm-6"><label class="form-label" for="item-price">Unit price (KES)</label><input id="item-price" class="form-control" value="" placeholder="Set by selected size" readonly></div>
                        <div class="col-sm-6"><label class="form-label" for="item-stock">Available stock</label><input id="item-stock" class="form-control" value="" placeholder="Select a size" readonly></div>
                    </div>

                    <div class="uniform-cart-wrap">
                        <table id="items-table" class="table uniform-cart-table">
                            <thead><tr><th scope="col">Item</th><th scope="col">Size</th><th scope="col">Qty</th><th scope="col">Unit Price</th><th scope="col">Total</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <div class="uniform-payment-heading">
                        <i class="fa-solid fa-wallet" aria-hidden="true"></i>
                        <h3>Payment</h3>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="amount_paid">Amount Paid (KES)</label>
                            <input class="form-control" type="number" min="0" step="0.01" name="amount_paid" id="amount_paid" value="0" inputmode="decimal">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="payment-method">Payment Method</label>
                            <select class="form-select" name="payment_method" id="payment-method">
                                <option>Cash</option>
                                <option>Mpesa</option>
                                <option>Bank</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="mpesa-code">M-PESA Code</label>
                            <input class="form-control" name="mpesa_code" id="mpesa-code" autocomplete="off" placeholder="Enter transaction code">
                        </div>
                    </div>

                    <input type="hidden" name="items" id="items-input">
                    <div class="uniform-sale-actions">
                        <?php if ($canSellUniform): ?>
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-receipt" aria-hidden="true"></i><span>Save Sale</span></button>
                        <?php else: ?>
                            <button class="btn btn-primary" type="button" disabled><i class="fa-solid fa-receipt" aria-hidden="true"></i><span>Save Sale</span></button>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" type="reset" id="reset-sale"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i><span>Reset</span></button>
                    </div>
                </form>
            </section>
        </div>

        <div class="col-xl-5">
            <aside class="panel uniform-sale-summary" aria-labelledby="uniform-summary-title">
                <div class="uniform-sale-section-heading">
                    <span class="uniform-sale-section-icon" aria-hidden="true"><i class="fa-solid fa-chart-column"></i></span>
                    <div>
                        <h2 id="uniform-summary-title">Sales Summary</h2>
                        <p>Recorded totals for the active academic period.</p>
                    </div>
                </div>
                <div class="uniform-summary-total">
                    <span>Recorded sales</span>
                    <strong><?= money($salesSummary['grand_total']) ?></strong>
                </div>
                <dl class="uniform-summary-lines">
                    <div><dt>Amount Collected</dt><dd><?= money($salesSummary['amount_paid']) ?></dd></div>
                    <div class="uniform-summary-balance"><dt>Balance Due</dt><dd><?= money($salesSummary['balance']) ?></dd></div>
                    <div><dt>Completed Sales</dt><dd><?= number_format($salesSummary['count']) ?></dd></div>
                </dl>
                <div class="uniform-current-sale">
                    <h3>Current sale</h3>
                    <div class="uniform-summary-total">
                        <span>Grand Total</span>
                        <strong id="summary-grand">KES 0.00</strong>
                    </div>
                    <dl class="uniform-summary-lines">
                        <div><dt>Subtotal</dt><dd id="summary-subtotal">KES 0.00</dd></div>
                        <div><dt>Amount Paid</dt><dd id="summary-paid">KES 0.00</dd></div>
                        <div class="uniform-summary-balance"><dt>Balance</dt><dd id="summary-balance">KES 0.00</dd></div>
                    </dl>
                    <div class="uniform-summary-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Current sale totals use catalog prices and update as items are added.</span></div>
                </div>
            </aside>
        </div>
    </div>

    <section class="panel uniform-sales-records">
        <div class="uniform-records-heading">
            <div>
                <h2><i class="fa-solid fa-list-check" aria-hidden="true"></i> Sales Records</h2>
                <p>Latest 100 uniform sales across all academic periods.</p>
            </div>
            <?php if ($canExportUniformReports): ?>
                <div class="uniform-record-actions">
                    <a class="btn btn-outline-primary" href="<?= url('admin/uniform_reports_export.php?report=sales&format=print') ?>"><i class="fa-solid fa-print" aria-hidden="true"></i><span>Print Report</span></a>
                    <a class="btn btn-primary" href="<?= url('admin/uniform_reports_export.php?report=sales&format=xlsx') ?>"><i class="fa-solid fa-file-excel" aria-hidden="true"></i><span>Export to Excel</span></a>
                </div>
            <?php endif; ?>
        </div>
        <div class="table-responsive uniform-records-table-wrap">
            <table class="table table-striped align-middle uniform-records-table">
                <thead><tr><th scope="col">Receipt</th><th scope="col">Student</th><th scope="col">Date</th><th scope="col">Grand Total</th><th scope="col">Paid</th><th scope="col">Balance</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    <?php foreach ($sales as $s): ?>
                        <tr>
                            <td><strong><?= h($s['receipt_no']) ?></strong></td>
                            <td><?= h($s['full_name']) ?><br><small><?= h($s['registration_no']) ?></small></td>
                            <td><?= h($s['payment_date']) ?></td>
                            <td><?= money((float)$s['grand_total']) ?></td>
                            <td><?= money((float)$s['amount_paid']) ?></td>
                            <td class="<?= (float)$s['balance'] > 0.005 ? 'uniform-record-balance' : 'uniform-record-paid' ?>"><?= money((float)$s['balance']) ?></td>
                            <td>
                                <div class="uniform-row-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/uniform_receipt.php?id=' . (int)$s['id']) ?>"><i class="fa-regular fa-eye" aria-hidden="true"></i><span>View Receipt</span></a>
                                    <?php if ((float)$s['balance'] > 0.005): ?>
                                        <a class="btn btn-sm btn-outline-success" href="<?= url('admin/uniform_receipt.php?id=' . (int)$s['id']) ?>#pay-balance"><i class="fa-solid fa-wallet" aria-hidden="true"></i><span>Pay Balance</span></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td class="uniform-empty-records" colspan="7"><i class="fa-regular fa-folder-open" aria-hidden="true"></i><span>No uniform sales have been recorded yet.</span></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
const uniformProducts = <?= json_encode($uniforms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const studentSelect = document.getElementById('student-select');
const si = document.getElementById('student-info');
const siName = document.getElementById('si-name');
const siClass = document.getElementById('si-class');
const uniformSelect = document.getElementById('uniform-select');
const genderSelect = document.getElementById('sale-gender');
const sizeSelect = document.getElementById('item-size');
const priceDisplay = document.getElementById('item-price');
const stockDisplay = document.getElementById('item-stock');
const amountPaidInput = document.getElementById('amount_paid');
const formatKes = value => `KES ${Number(value || 0).toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

const normalizeGender = value => ({female: 'Girls', girl: 'Girls', male: 'Boys', boy: 'Boys', girls: 'Girls', boys: 'Boys', unisex: 'Unisex'})[String(value || '').trim().toLowerCase()] || '';
function resetSizeOptions(label = 'Choose item first...') {
    sizeSelect.replaceChildren(new Option(label, ''));
    sizeSelect.disabled = true;
    priceDisplay.value = '';
    stockDisplay.value = '';
}
function refreshProducts() {
    const selectedGender = genderSelect.value;
    uniformSelect.replaceChildren(new Option(selectedGender ? 'Choose uniform...' : 'Choose gender first...', ''));
    uniformSelect.disabled = !selectedGender;
    uniformProducts.filter(product => {
        const productGender = normalizeGender(product.gender);
        return selectedGender === 'Unisex' ? productGender === 'Unisex' : [selectedGender, 'Unisex'].includes(productGender);
    }).forEach(product => uniformSelect.add(new Option(`${product.uniform_name} · ${product.category}`, String(product.id))));
    uniformSelect.value = '';
    resetSizeOptions();
}
function refreshSizes() {
    const product = uniformProducts.find(item => String(item.id) === uniformSelect.value);
    sizeSelect.replaceChildren(new Option(product ? 'Choose size...' : 'Choose item first...', ''));
    if (!product) { resetSizeOptions(); return; }
    product.variants.forEach(variant => sizeSelect.add(new Option(`${variant.size} · ${formatKes(variant.selling_price)} · ${variant.available_stock} available`, String(variant.id))));
    sizeSelect.disabled = product.variants.length === 0;
    if (!product.variants.length) sizeSelect.replaceChildren(new Option('No active sizes available', ''));
    priceDisplay.value = '';
    stockDisplay.value = '';
}
function refreshPriceAndStock() {
    const product = uniformProducts.find(item => String(item.id) === uniformSelect.value);
    const variant = product?.variants.find(item => String(item.id) === sizeSelect.value);
    priceDisplay.value = variant ? formatKes(variant.selling_price) : '';
    stockDisplay.value = variant ? String(variant.available_stock) : '';
}
studentSelect.addEventListener('change', () => {
    const opt = studentSelect.selectedOptions[0];
    if (!opt || !opt.value) {
        si.hidden = true;
        genderSelect.value = '';
        refreshProducts();
        return;
    }
    items.length = 0;
    renderItems();
    si.hidden = false;
    siName.textContent = opt.textContent;
    siClass.textContent = [opt.dataset.class, opt.dataset.gender].filter(Boolean).join(' · ');
    genderSelect.value = normalizeGender(opt.dataset.gender);
    refreshProducts();
});
genderSelect.addEventListener('change', refreshProducts);
uniformSelect.addEventListener('change', refreshSizes);
sizeSelect.addEventListener('change', refreshPriceAndStock);

const items = [];
const itemsTable = document.querySelector('#items-table tbody');
function renderItems(){
    itemsTable.replaceChildren();
    let subtotal=0;
    if (!items.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 6;
        cell.className = 'uniform-empty-cart';
        cell.textContent = 'No items added yet';
        row.appendChild(cell);
        itemsTable.appendChild(row);
    }
    items.forEach((it,idx)=>{
        const tr=document.createElement('tr');
        [it.text, it.size, it.qty, formatKes(it.price), formatKes(it.qty * it.price)].forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = String(value);
            tr.appendChild(cell);
        });
        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.dataset.idx = String(idx);
        removeButton.className = 'btn btn-sm btn-outline-danger remove';
        removeButton.setAttribute('aria-label', `Remove ${it.text}`);
        removeButton.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden">Remove</span>';
        actionCell.appendChild(removeButton);
        tr.appendChild(actionCell);
        itemsTable.appendChild(tr);
        subtotal += it.qty*it.price;
    });
    document.getElementById('summary-subtotal').textContent = formatKes(subtotal);
    const grand = Math.max(subtotal,0);
    document.getElementById('summary-grand').textContent = formatKes(grand);
    const paid = parseFloat(amountPaidInput.value||0);
    document.getElementById('summary-paid').textContent = formatKes(paid);
    document.getElementById('summary-balance').textContent = formatKes(Math.max(grand - paid,0));
    document.getElementById('items-input').value = JSON.stringify(items.map(i=>({uniform_id:i.id,uniform_variant_id:i.variantId,quantity:i.qty})));
}

document.getElementById('add-item').addEventListener('click', ()=>{
    if (!genderSelect.value) return alert('Choose a gender.');
    if (!uniformSelect.value) return alert('Choose a uniform.');
    if (!sizeSelect.value) return alert('Choose a size.');
    const product = uniformProducts.find(item => String(item.id) === uniformSelect.value);
    const variant = product?.variants.find(item => String(item.id) === sizeSelect.value);
    if (!product || !variant) return alert('Choose an available uniform size.');
    const qty = parseInt(document.getElementById('item-qty').value||1, 10);
    if (!Number.isInteger(qty) || qty < 1) return alert('Enter a quantity of at least 1.');
    const existingQty = items.filter(item => item.variantId === variant.id).reduce((sum, item) => sum + item.qty, 0);
    if (qty + existingQty > variant.available_stock) return alert(`Only ${variant.available_stock - existingQty} of this size are available.`);
    items.push({id: product.id, variantId: variant.id, text: `${product.uniform_name} · ${product.category}`, qty, size: variant.size, price: variant.selling_price, stock: variant.available_stock});
    renderItems();
});

document.getElementById('items-table').addEventListener('click', (e)=>{ const button = e.target.closest('.remove'); if (button){ items.splice(parseInt(button.dataset.idx,10),1); renderItems(); } });
amountPaidInput.addEventListener('input', renderItems);

document.getElementById('sale-form').addEventListener('submit', function(e){ if (items.length===0){ e.preventDefault(); alert('Add items before saving'); } else { document.getElementById('items-input').value = JSON.stringify(items.map(i=>({uniform_id:i.id,uniform_variant_id:i.variantId,quantity:i.qty}))); }});
document.getElementById('sale-form').addEventListener('reset', ()=>{
    window.setTimeout(()=>{
        items.length = 0;
        renderItems();
        genderSelect.value = '';
        refreshProducts();
        studentSelect.dispatchEvent(new Event('change'));
    }, 0);
});

refreshProducts();
renderItems();
</script>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
