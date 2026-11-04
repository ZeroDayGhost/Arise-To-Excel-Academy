<?php
$pageTitle = 'Daycare Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/daycare_helpers.php';
daycare_require($pdo, 'daycare.payments.view');

$paymentId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*,c.full_name,c.admission_no,g.full_name AS guardian_name,gr.name AS group_name
    FROM daycare_payments p
    JOIN daycare_children c ON c.id=p.child_id
    LEFT JOIN daycare_guardians g ON g.id=p.guardian_id
    LEFT JOIN daycare_groups gr ON gr.id=c.group_id
    WHERE p.id=:id LIMIT 1");
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Daycare receipt not found.');
}

$balanceAfter = daycare_child_balance($pdo, (int) $payment['child_id']);
$receipt_title = 'DAYCARE PAYMENT RECEIPT';
$receipt_no = $payment['receipt_no'];
$receipt_date = date('M j, Y g:i A', strtotime($payment['payment_date']));
$receipt_status = $payment['payment_status'] === 'Recorded' ? 'RECORDED' : 'REVERSED';
$receipt_type = 'DAYCARE FEES';
$payer_name = $payment['full_name'];
$payer_meta = [
    'Admission Number' => $payment['admission_no'],
    'Parent / Guardian' => $payment['guardian_name'] ?: 'Not recorded',
    'Group' => $payment['group_name'] ?: 'Unassigned',
];
if ($payment['billing_period']) $payer_meta['Billing Period'] = $payment['billing_period'];
$items = [[
    'description' => $payment['category'] . ($payment['notes'] ? ' - ' . $payment['notes'] : ''),
    'quantity' => 1,
    'unit_price' => (float) $payment['amount'],
    'line_total' => (float) $payment['amount'],
]];
$totals = ['subtotal' => (float) $payment['amount'], 'grand_total' => (float) $payment['amount'], 'amount_paid' => (float) $payment['amount'], 'balance' => $balanceAfter];
$payment_method = $payment['payment_method'];
$mpesa_code = $payment['payment_reference'] ?? '';
$served_by = (string) ($_SESSION['admin_name'] ?? 'Daycare Office');
$back_href = url('admin/daycare.php?view=payments');
$pdf_href = '';

require __DIR__ . '/../includes/admin_header.php';
?>
<main class="daycare-page">
    <div class="daycare-heading"><div><span class="daycare-kicker">Daycare accounts</span><h1>Payment receipt</h1><p><?= h($payment['receipt_no']) ?> · <?= h($payment['payment_status']) ?></p></div><a class="btn btn-outline-primary" href="<?= h(url('admin/daycare.php?view=payments')) ?>">Back to payments</a></div>
    <?php require __DIR__ . '/../includes/receipt_template.php'; ?>
</main>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
