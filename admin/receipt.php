<?php
$pageTitle = 'Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/school_receipt_pdf.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$receiptId = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare(
    "SELECT
        fees.*,
        students.registration_no,
        students.full_name,
        " . student_class_sql('students', 'fees.year') . " AS class_level,
        students.enrollment_status,
        fees.balance_after_payment AS current_balance
     FROM fees
     JOIN students ON students.id = fees.student_id
     WHERE fees.id = :id"
);
$statement->execute(['id' => $receiptId]);
$receipt = $statement->fetch();

if (!$receipt) {
    redirect('admin/fees.php');
}

// Permission: viewing receipts requires explicit receipt access.
if (!current_admin_has_permission($pdo, 'fees.print')) {
    flash('error', 'You do not have permission to view receipts.');
    redirect('admin/fees.php');
}

// If current admin is a Teacher, ensure receipt is for their class
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
if ($adminId) {
    $role = get_user_role_template_name($pdo, $adminId);
    $adminClass = current_admin_class_level($pdo);
    if ($role === 'Teacher' && $adminClass && $receipt['class_level'] !== $adminClass) {
        flash('error', 'You do not have permission to view receipts for this class.');
        redirect('admin/fees.php');
    }
}

if (($_GET['format'] ?? '') === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $receipt['receipt_no']) . '.pdf"');
    echo build_school_receipt_pdf($receipt);
    exit;
}

// prepare variables for shared receipt template (HTML view)
if (($_GET['format'] ?? '') !== 'pdf') {
    // Add receipt CSS to page head
    if (!isset($GLOBALS['receipt_css_loaded'])) {
        echo '<link rel="stylesheet" href="' . asset('css/receipt.css') . '">';
        $GLOBALS['receipt_css_loaded'] = true;
    }
    
    require_once __DIR__ . '/../includes/admin_header.php';

    $receipt_title = 'Fee Payment Receipt';
    $receipt_no = $receipt['receipt_no'];
    $receipt_date = date('M d, Y', strtotime($receipt['payment_date']));
    $receipt_status = ($receipt['payment_status'] ?? 'Recorded') !== 'Recorded'
        ? strtoupper((string) $receipt['payment_status'])
        : (($receipt['current_balance'] <= 0.005) ? 'PAID' : 'PARTIAL');
    $payer_name = $receipt['full_name'];
    $payer_meta = [
        'Reg Number' => $receipt['registration_no'],
        'Class Level' => $receipt['class_level'],
        'Enrollment Status' => $receipt['enrollment_status'],
        'Term' => $receipt['term'] . ' - ' . $receipt['year'],
        'Payment Status' => $receipt['payment_status'] ?? 'Recorded',
    ];
    if (($receipt['payment_status'] ?? 'Recorded') !== 'Recorded' && !empty($receipt['reversal_reason'])) {
        $payer_meta['Action Note'] = $receipt['reversal_reason'];
    }
    $items_out = [];
    // fees are single-line payments; represent as one item
    $items_out[] = ['description' => 'Fee Payment', 'quantity' => 1, 'unit_price' => (float)$receipt['amount_paid'], 'line_total' => (float)$receipt['amount_paid']];
    $items = $items_out;
    $totals = [
        'subtotal' => (float)$receipt['amount_paid'],
        'discount' => 0,
        'grand_total' => (float)$receipt['amount_paid'],
        'amount_paid' => (float)$receipt['amount_paid'],
        'balance' => (float) ($receipt['current_balance'] ?? 0),
    ];
    $payment_method = $receipt['payment_method'] ?? 'M-PESA';
    $mpesa_code = $receipt['mpesa_code'];
    $mpesa_reference_text = $receipt['mpesa_reference_text'] ?? '';
    $balance_before_payment = $receipt['balance_before_payment'] ?? null;
    $served_by = $_SESSION['admin_name'] ?? '';
    $receipt_type = 'SCHOOL FEES';
    $back_href = url('admin/fees.php');
    $pdf_href = url('admin/receipt.php?id=' . (int) $receipt['id'] . '&format=pdf');

    include __DIR__ . '/../includes/receipt_template.php';

    require_once __DIR__ . '/../includes/layout_end.php';
}
