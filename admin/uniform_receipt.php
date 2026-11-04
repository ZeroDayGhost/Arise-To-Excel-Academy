<?php
$pageTitle = 'Uniform Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access
if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access Uniform receipts.');
    redirect('admin/dashboard.php');
}

$canPayUniform = current_admin_has_permission($pdo, 'school_uniform.pay_balance');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { redirect('admin/uniform_sales.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_balance') {
    $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $mpesaCode = trim($_POST['mpesa_code'] ?? '');

    $stmt = $pdo->prepare('SELECT us.*, s.registration_no, s.full_name, s.class_level FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id WHERE us.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $sale = $stmt->fetch();

        if (!$sale) {
        $errors[] = 'Receipt not found.';
    } else {
        if ($paymentAmount <= 0) {
            $errors[] = 'Enter a valid payment amount.';
        }
        if ($paymentAmount > (float) $sale['balance'] + 0.005) {
            $errors[] = 'Amount cannot exceed outstanding balance.';
        }

        if (!$errors) {
            if (!$canPayUniform) {
                flash('error', 'You do not have permission to record uniform payments.');
                redirect('admin/uniform_receipt.php?id=' . $id);
            }
            $newAmountPaid = min((float)$sale['amount_paid'] + $paymentAmount, (float)$sale['grand_total']);
            $newBalance = max((float)$sale['grand_total'] - $newAmountPaid, 0.0);

            $update = $pdo->prepare(
                'UPDATE uniform_sales
                 SET amount_paid = :amount_paid,
                     balance = :balance,
                     payment_method = :payment_method,
                     mpesa_code = :mpesa_code
                 WHERE id = :id'
            );
            $update->execute([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'payment_method' => $paymentMethod,
                'mpesa_code' => $mpesaCode,
                'id' => $id,
            ]);

            flash('success', 'Payment recorded successfully.');
            redirect('admin/uniform_receipt.php?id=' . $id);
        }
    }
}

$stmt = $pdo->prepare('SELECT us.*, s.registration_no, s.full_name, s.class_level FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id WHERE us.id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$sale = $stmt->fetch();
if (!$sale) { flash('error','Receipt not found.'); redirect('admin/uniform_sales.php'); }

$items = $pdo->prepare('SELECT ui.*, u.uniform_name FROM uniform_sale_items ui JOIN uniforms u ON u.id = ui.uniform_id WHERE ui.sale_id = :sale_id');
$items->execute(['sale_id' => $id]);
$items = $items->fetchAll();

// prepare variables for shared receipt template
require_once __DIR__ . '/../includes/admin_header.php';

$receipt_title = 'Uniform Sale Receipt';
$receipt_no = $sale['receipt_no'];
$receipt_date = $sale['payment_date'];
$receipt_status = $sale['balance'] <= 0 ? 'PAID' : 'PARTIAL';
$payer_name = $sale['full_name'] . ' (' . $sale['registration_no'] . ')';
$payer_meta = ['Class' => $sale['class_level']];
$items_out = [];
foreach ($items as $it) {
    $items_out[] = [
        'description' => $it['uniform_name'],
        'size' => $it['size'] ?? '',
        'quantity' => (int) $it['quantity'],
        'unit_price' => (float) $it['unit_price'],
        'line_total' => (float) $it['line_total'],
    ];
}
$totals = [
    'subtotal' => (float) $sale['subtotal'],
    'discount' => (float) $sale['discount'],
    'grand_total' => (float) $sale['grand_total'],
    'amount_paid' => (float) $sale['amount_paid'],
    'balance' => (float) $sale['balance'],
];
$payment_method = $sale['payment_method'];
$mpesa_code = $sale['mpesa_code'];
$served_by = $sale['served_by'] ?? ($_SESSION['admin_name'] ?? '');
$receipt_type = 'UNIFORM SALES';
$back_href = url('admin/uniform_sales.php');
$pdf_href = '';
include __DIR__ . '/../includes/receipt_template.php';

if ((float)$sale['balance'] > 0.005): ?>
    <section id="pay-balance" class="panel mt-4">
        <h2>Record Additional Payment</h2>
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="pay_balance">
            <div class="col-md-4">
                <label class="form-label">Outstanding Balance</label>
                <input class="form-control" type="text" value="<?= money((float)$sale['balance']) ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="payment_amount">Payment Amount</label>
                <input class="form-control" type="number" step="0.01" min="0.01" name="payment_amount" id="payment_amount" value="<?= h($_POST['payment_amount'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="payment_method">Payment Method</label>
                <select class="form-select" name="payment_method" id="payment_method">
                    <option value="Cash" <?= (($_POST['payment_method'] ?? $sale['payment_method']) === 'Cash') ? 'selected' : '' ?>>Cash</option>
                    <option value="Mpesa" <?= (($_POST['payment_method'] ?? $sale['payment_method']) === 'Mpesa') ? 'selected' : '' ?>>Mpesa</option>
                    <option value="Bank" <?= (($_POST['payment_method'] ?? $sale['payment_method']) === 'Bank') ? 'selected' : '' ?>>Bank</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="mpesa_code">M-PESA Code</label>
                <input class="form-control" name="mpesa_code" id="mpesa_code" value="<?= h($_POST['mpesa_code'] ?? $sale['mpesa_code']) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Record Payment</button>
            </div>
        </form>
    </section>
<?php endif;

require_once __DIR__ . '/../includes/layout_end.php';
