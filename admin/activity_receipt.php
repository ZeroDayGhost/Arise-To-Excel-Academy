<?php
$pageTitle = 'Activity Payment Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
activities_ensure_schema($pdo);

$paymentId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
if ($paymentId <= 0) {
    flash('error', 'Activity receipt was not found.');
    redirect('admin/activities.php');
}
if (!current_admin_has_permission($pdo, 'activities.view')) {
    rbac_deny($pdo, 'You do not have permission to view activity receipts.');
}

$classSql = student_class_sql('s', 'a.academic_year');
$stmt = $pdo->prepare(
    "SELECT p.*, a.name AS activity_name, a.activity_date, a.academic_year, a.term,
            ap.expected_amount, s.full_name, s.registration_no, {$classSql} AS class_level,
            u.name AS received_by
     FROM activity_payments p
     JOIN activities a ON a.id = p.activity_id
     JOIN activity_participants ap ON ap.id = p.participant_id
     JOIN students s ON s.id = ap.student_id
     LEFT JOIN admin u ON u.id = p.recorded_by
     WHERE p.id = :id LIMIT 1"
);
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();
if (!$payment) {
    flash('error', 'Activity receipt was not found.');
    redirect('admin/activities.php');
}
$paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM activity_payments WHERE participant_id = :participant_id AND status = 'Recorded'");
$paidStmt->execute(['participant_id' => $payment['participant_id']]);
$balance = max(0.0, (float) $payment['expected_amount'] - (float) $paidStmt->fetchColumn());
$receiptStatus = match ($payment['status']) {
    'Refunded' => 'REFUNDED',
    'Reversed' => 'REVERSED',
    default => $balance <= 0.005 ? 'PAID' : 'PARTIAL',
};

require_once __DIR__ . '/../includes/admin_header.php';
$receipt_title = 'Activity Collection Receipt';
$receipt_no = $payment['receipt_no'];
$receipt_date = date('M d, Y', strtotime($payment['payment_date']));
$receipt_status = $receiptStatus;
$payer_name = $payment['full_name'];
$payer_meta = [
    'Admission number' => $payment['registration_no'],
    'Class' => $payment['class_level'],
    'Activity' => $payment['activity_name'],
    'Academic period' => $payment['academic_year'] . ' - ' . $payment['term'],
];
$items = [[
    'description' => $payment['activity_name'] . ' collection',
    'quantity' => 1,
    'unit_price' => (float) $payment['amount'],
    'line_total' => (float) $payment['amount'],
]];
$totals = [
    'subtotal' => (float) $payment['amount'],
    'discount' => 0,
    'grand_total' => (float) $payment['amount'],
    'amount_paid' => (float) $payment['amount'],
    'balance' => $balance,
];
$payment_method = $payment['payment_method'];
$mpesa_code = $payment['reference_no'] ?? '';
$served_by = $payment['received_by'] ?? '';
$receipt_type = 'ACTIVITY';
$back_href = url('admin/activities.php');
$pdf_href = '';
include __DIR__ . '/../includes/receipt_template.php';
require_once __DIR__ . '/../includes/layout_end.php';
