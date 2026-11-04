<?php
$pageTitle = 'Feeding Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access check
if (!current_admin_has_permission($pdo, 'feeding.access')) {
    flash('error', 'You do not have permission to access Feeding.');
    redirect('admin/dashboard.php');
}

$paymentId = (int) ($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    redirect('admin/feeding.php');
}

// Get the feeding payment with subscription and student details
$statement = $pdo->prepare(
    "SELECT
        fp.id,
        fp.amount_paid,
        fp.payment_date,
        fp.reference_no,
        fs.id AS subscription_id,
        fs.student_id,
        fs.feeding_amount,
        fs.academic_year,
        fs.term,
        s.full_name,
        s.registration_no,
        s.class_level
     FROM feeding_payments fp
     JOIN feeding_subscriptions fs ON fs.id = fp.feeding_subscription_id
     JOIN students s ON s.id = fs.student_id
     WHERE fp.id = :id"
);
$statement->execute(['id' => $paymentId]);
$payment = $statement->fetch();

if (!$payment) {
    flash('error', 'Payment receipt not found.');
    redirect('admin/feeding.php');
}

// Calculate the balance for this subscription
$paidStatement = $pdo->prepare(
    "SELECT COALESCE(SUM(amount_paid), 0) FROM feeding_payments 
     WHERE feeding_subscription_id = :subscription_id"
);
$paidStatement->execute(['subscription_id' => $payment['subscription_id']]);
$totalPaid = (float) $paidStatement->fetchColumn();
$remainingBalance = max((float) $payment['feeding_amount'] - $totalPaid, 0);

// Prepare variables for shared receipt template
require_once __DIR__ . '/../includes/admin_header.php';

$receipt_title = 'Feeding Payment Receipt';
$receipt_no = 'FP-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT);
$receipt_date = date('M d, Y', strtotime($payment['payment_date']));
$receipt_status = ($remainingBalance <= 0.005) ? 'PAID' : 'PARTIAL';
$payer_name = $payment['full_name'];
$payer_meta = [
    'Reg Number' => $payment['registration_no'],
    'Class Level' => $payment['class_level'],
    'Academic Year' => $payment['academic_year'] . ' - ' . $payment['term'],
];
$items_out = [
    [
        'description' => 'Feeding Subscription Payment',
        'quantity' => 1,
        'unit_price' => (float) $payment['amount_paid'],
        'line_total' => (float) $payment['amount_paid'],
    ]
];
$totals = [
    'subtotal' => (float) $payment['amount_paid'],
    'discount' => 0,
    'grand_total' => (float) $payment['amount_paid'],
    'amount_paid' => (float) $payment['amount_paid'],
    'balance' => $remainingBalance,
];
$payment_method = 'M-PESA';
$mpesa_code = $payment['reference_no'] ?? '';
$served_by = $_SESSION['admin_name'] ?? '';
$receipt_type = 'FEEDING';
$back_href = url('admin/feeding.php');
$pdf_href = '';

include __DIR__ . '/../includes/receipt_template.php';
require_once __DIR__ . '/../includes/layout_end.php';
