<?php
$pageTitle = 'Combined Payment Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment_transactions.php';
require_once __DIR__ . '/../includes/school_receipt_pdf.php';

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare('SELECT t.*,COALESCE(p.name,g.full_name,t.payer_name) AS parent_name,u.name AS recorded_by_name
    FROM payment_transactions t LEFT JOIN admin p ON p.id=t.parent_user_id
    LEFT JOIN daycare_guardians g ON g.id=t.daycare_guardian_id LEFT JOIN admin u ON u.id=t.recorded_by WHERE t.id=:id');
$stmt->execute(['id' => $id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transaction) {
    http_response_code(404);
    exit('This combined payment receipt was not found.');
}

$stmt = $pdo->prepare("SELECT a.*,COALESCE(s.full_name,d.full_name,ts.student_name,'Unknown student') AS student_name,
        COALESCE(s.registration_no,d.admission_no,CASE WHEN ts.is_outside=1 THEN 'Outside transport account' ELSE '' END,'') AS admission_no,
        CASE a.source_module
            WHEN 'fees' THEN f.receipt_no
            WHEN 'activity' THEN ap.receipt_no
            WHEN 'daycare' THEN dp.receipt_no
            WHEN 'transport' THEN tp.reference_no
            WHEN 'uniform' THEN us.receipt_no
            WHEN 'feeding' THEN fp.reference_no
            ELSE NULL
        END AS source_receipt,
        CASE a.source_module
            WHEN 'fees' THEN f.balance_after_payment
            ELSE NULL
        END AS fee_balance_after_payment
    FROM payment_allocations a
    LEFT JOIN students s ON s.id=a.student_id
    LEFT JOIN daycare_children d ON d.id=a.daycare_child_id
    LEFT JOIN transport_payments tp ON a.source_module='transport' AND tp.id=a.source_payment_id
    LEFT JOIN transport_accounts ta ON ta.id=tp.transport_account_id
    LEFT JOIN transport_students ts ON ts.id=ta.transport_student_id
    LEFT JOIN uniform_sale_payments usp ON a.source_module='uniform' AND usp.id=a.source_payment_id
    LEFT JOIN uniform_sales us ON us.id=usp.sale_id
    LEFT JOIN feeding_payments fp ON a.source_module='feeding' AND fp.id=a.source_payment_id
    LEFT JOIN fees f ON a.source_module='fees' AND f.id=a.source_payment_id
    LEFT JOIN activity_payments ap ON a.source_module='activity' AND ap.id=a.source_payment_id
    LEFT JOIN daycare_payments dp ON a.source_module='daycare' AND dp.id=a.source_payment_id
    WHERE a.transaction_id=:id ORDER BY a.id");
$stmt->execute(['id' => $id]);
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$modulePermissions = [
    'fees' => ['fees.print', 'fees.record'],
    'activity' => ['activities.view', 'activities.payments.collect'],
    'daycare' => ['daycare.payments.view', 'daycare.payments.record'],
    'transport' => ['transport.view_receipt', 'transport.view', 'transport.save'],
    'uniform' => ['school_uniform.view_receipt', 'school_uniform.pay_balance'],
    'feeding' => ['feeding.view_receipt', 'feeding.record_payment', 'feeding.reports'],
];
$canViewAll = current_admin_has_permission($pdo, 'reports.view');
if (!$canViewAll) {
    $canViewAll = count($allocations) > 0;
    foreach ($allocations as $allocation) {
        $permissions = $modulePermissions[$allocation['source_module']] ?? [];
        if (!$permissions || !array_filter($permissions, static fn($permission) => current_admin_has_permission($pdo, $permission))) {
            $canViewAll = false;
            break;
        }
    }
}
if (!$canViewAll) {
    http_response_code(403);
    exit('You do not have permission to view all parts of this combined payment receipt.');
}

$allocated = array_sum(array_map(static fn($row) => (float) $row['amount'], $allocations));
if (($_GET['format'] ?? '') === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $transaction['receipt_no']) . '.pdf"');
    echo build_combined_school_receipt_pdf($transaction, $allocations);
    exit;
}

require_once __DIR__ . '/../includes/admin_header.php';
$receipt_title = 'Combined Payment Receipt';
$receipt_no = $transaction['receipt_no'];
$receipt_date = date('M d, Y', strtotime($transaction['payment_date']));
$receipt_status = $transaction['status'] ?: 'Recorded';
$parentName = trim((string) ($transaction['parent_name'] ?? ''));
$uniqueStudents = [];
$periods = [];
$items = [];
foreach ($allocations as $allocation) {
    $studentName = trim((string) ($allocation['student_name'] ?? 'Unknown student'));
    $admissionNo = trim((string) ($allocation['admission_no'] ?? ''));
    $studentKey = (string) ($allocation['student_id'] ?? $allocation['daycare_child_id'] ?? $studentName);
    $uniqueStudents[$studentKey] = $studentName . ($admissionNo !== '' ? ' (' . $admissionNo . ')' : '');
    $period = trim((string) $allocation['academic_year'] . ' - ' . (string) $allocation['term'], ' -');
    if ($period !== '') $periods[$period] = true;
    $items[] = [
        'description' => $allocation['payment_category'] . ' — ' . $studentName . ($admissionNo !== '' ? ' (' . $admissionNo . ')' : ''),
        'quantity' => 1,
        'unit_price' => (float) $allocation['amount'],
        'line_total' => (float) $allocation['amount'],
        'fee_balance_after' => $allocation['source_module'] === 'fees' && is_numeric($allocation['fee_balance_after_payment'] ?? null)
            ? (float) $allocation['fee_balance_after_payment']
            : null,
    ];
}
$feeAllocations = array_values(array_filter($allocations, static fn(array $allocation): bool => $allocation['source_module'] === 'fees'));
$receipt_has_fee_balances = $feeAllocations !== [];
$feeBalanceAfter = array_sum(array_map(
    static fn(array $allocation): float => is_numeric($allocation['fee_balance_after_payment'] ?? null)
        ? (float) $allocation['fee_balance_after_payment']
        : 0.0,
    $feeAllocations
));
$payer_name = implode(', ', array_values($uniqueStudents));
$payer_meta = [
    'Students covered' => (string) count($uniqueStudents),
    'Payment allocations' => (string) count($allocations),
];
if ($parentName !== '') $payer_meta['Parent / guardian'] = $parentName;
if ($periods) $payer_meta['Academic period'] = implode(', ', array_keys($periods));
$totals = [
    'subtotal' => (float) $transaction['total_amount'],
    'discount' => 0,
    'grand_total' => (float) $transaction['total_amount'],
    'amount_paid' => (float) $transaction['total_amount'],
    'balance' => max(0, (float) $transaction['total_amount'] - $allocated),
    'balance_label' => 'Unallocated amount',
];
if ($receipt_has_fee_balances) $totals['fee_balance_after'] = $feeBalanceAfter;
$payment_method = $transaction['payment_method'];
$mpesa_code = $transaction['mpesa_code'] ?? '';
$mpesa_reference_text = '';
$served_by = $transaction['recorded_by_name'] ?? 'School office';
$receipt_type = 'COMBINED PAYMENT';
$back_href = url('admin/fees.php');
$pdf_href = url('admin/payment_transaction_receipt.php?id=' . (int) $transaction['id'] . '&format=pdf');
require __DIR__ . '/../includes/receipt_template.php';
require_once __DIR__ . '/../includes/layout_end.php';
