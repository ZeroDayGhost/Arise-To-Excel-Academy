<?php
$pageTitle = 'Transport Receipt';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$receiptId = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare(
    'SELECT
        transport_payments.*, 
        transport_accounts.amount_due,
        transport_accounts.academic_year,
        transport_accounts.term,
        transport_students.student_id,
        transport_students.student_name,
        transport_students.pickup_location,
        transport_students.is_outside,
        transport_students.parent_name,
        transport_students.parent_phone,
        students.registration_no,
        students.class_level
     FROM transport_payments
     JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
     JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
     LEFT JOIN students ON students.id = transport_students.student_id
     WHERE transport_payments.id = :id'
);
$statement->execute(['id' => $receiptId]);
$receipt = $statement->fetch();

if (!$receipt) {
    redirect('admin/transport.php');
}

function receipt_pdf_escape(string $text): string
{
    $text = preg_replace('/[^\x00-\x7F]/', ' ', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function receipt_pdf_text(float $x, float $y, string $text, float $size = 10, string $font = 'F1', string $color = '0 0 0'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . receipt_pdf_escape($text) . ") Tj ET\n";
}

function receipt_pdf_rect(float $x, float $y, float $w, float $h, string $fill, string $stroke = ''): string
{
    $command = "{$fill} rg {$x} {$y} {$w} {$h} re f\n";
    if ($stroke !== '') {
        $command .= "{$stroke} RG {$x} {$y} {$w} {$h} re S\n";
    }
    return $command;
}

function receipt_pdf_document(string $stream, ?array $image): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $imageId = 0;
    if ($image) {
        $imageId = 6;
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n{$image['data']}\nendstream";
    }
    $xObject = $imageId ? " /XObject << /Im1 {$imageId} 0 R >>" : '';
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>{$xObject} >> /Contents 7 0 R >>";
    $objects[7] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= isset($offsets[$id]) ? str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT) . " 00000 n \n" : "0000000000 65535 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function build_transport_receipt_pdf(array $receipt): string
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    $image = null;
    if (is_file($logoPath) && ($size = @getimagesize($logoPath))) {
        $image = ['data' => file_get_contents($logoPath), 'width' => $size[0], 'height' => $size[1]];
    }

    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    $stream = '';
    $stream .= receipt_pdf_rect(0, 0, 595, 842, '1 1 1');
    $stream .= receipt_pdf_rect(0, 742, 595, 100, '0.96 0.98 1');
    if ($image) {
        $stream .= "q 58 0 0 58 42 760 cm /Im1 Do Q\n";
    }
    $stream .= receipt_pdf_text(116, 804, SCHOOL_NAME, 18, 'F2', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(116, 785, SCHOOL_ADDRESS, 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(116, 770, SCHOOL_PHONE . ' | ' . SCHOOL_EMAIL, 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(410, 804, 'TRANSPORT RECEIPT', 14, 'F2', '0.10 0.22 0.57');
    $stream .= receipt_pdf_text(410, 784, 'TR-' . str_pad((string)$receipt['id'], 6, '0', STR_PAD_LEFT), 10, 'F2', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(410, 768, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.39 0.45 0.55');

    $items = [
        ['Student Name', $receipt['student_name']],
        ['Admission Number', $receipt['registration_no'] ?: 'N/A'],
        ['Class Level', $receipt['class_level'] ?: 'N/A'],
        ['Route', $receipt['pickup_location']],
        ['Term', $receipt['term'] . ' - ' . $receipt['academic_year']],
        ['Amount Paid', money((float)$receipt['amount_paid'])],
        ['M-PESA Code', $receipt['reference_no'] ?: 'N/A'],
        ['Payment Method', 'M-PESA'],
        ['Remaining Balance', money(max((float)$receipt['amount_due'] - (float)($receipt['_paid_so_far'] ?? $receipt['amount_paid']), 0))],
        ['Payment Date', date('M d, Y', strtotime($receipt['payment_date']))],
        ['Admin Name', $adminName],
    ];

    $x = 54;
    $y = 678;
    foreach ($items as $index => $item) {
        $stream .= receipt_pdf_rect($x, $y, 230, 50, $index % 2 === 0 ? '0.97 0.98 1' : '1 1 1', '0.88 0.91 0.95');
        $stream .= receipt_pdf_text($x + 14, $y + 31, $item[0], 8, 'F1', '0.39 0.45 0.55');
        $stream .= receipt_pdf_text($x + 14, $y + 13, (string) $item[1], 11, 'F2', '0.06 0.09 0.16');
        if ($x > 250) {
            $x = 54;
            $y -= 62;
        } else {
            $x = 310;
        }
    }

    $stream .= receipt_pdf_rect(54, 242, 486, 72, '0.94 0.97 1', '0.76 0.84 0.96');
    $stream .= receipt_pdf_text(72, 286, 'Amount Received', 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(72, 260, money((float)$receipt['amount_paid']), 20, 'F2', '0.10 0.22 0.57');
    $stream .= receipt_pdf_text(54, 166, 'Received By', 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(54, 132, '____________________________', 10, 'F1', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(342, 166, 'School Stamp', 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(342, 132, '____________________________', 10, 'F1', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(54, 62, SCHOOL_NAME . ' | This receipt was generated by Arise To Excel Fees.', 8, 'F1', '0.39 0.45 0.55');

    return receipt_pdf_document($stream, $image);
}

if (($_GET['format'] ?? '') === 'pdf') {
    $paidStatement = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM transport_payments WHERE transport_account_id = :id');
    $paidStatement->execute(['id' => $receipt['transport_account_id']]);
    $receipt['_paid_so_far'] = (float) $paidStatement->fetchColumn();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="transport_receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', 'TR-' . str_pad((string)$receipt['id'], 6, '0', STR_PAD_LEFT)) . '.pdf"');
    echo build_transport_receipt_pdf($receipt);
    exit;
}

require_once __DIR__ . '/../includes/admin_header.php';

$receipt_title = 'Transport Payment Receipt';
$receipt_no = 'TR-' . str_pad((string)$receipt['id'], 6, '0', STR_PAD_LEFT);
$receipt_date = date('M d, Y', strtotime($receipt['payment_date']));
$receipt_status = 'PAID';
$payer_name = $receipt['student_name'];
$payer_meta = [
    'Reg Number' => $receipt['registration_no'] ?: 'N/A',
    'Class Level' => $receipt['class_level'] ?: 'N/A',
    'Route' => $receipt['pickup_location'],
    'Term' => $receipt['term'] . ' - ' . $receipt['academic_year'],
    'M-PESA Code' => $receipt['reference_no'] ?: 'N/A',
];
$amountPaid = (float) $receipt['amount_paid'];
$paidStatement = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM transport_payments WHERE transport_account_id = :id');
$paidStatement->execute(['id' => $receipt['transport_account_id']]);
$paidSoFar = (float) $paidStatement->fetchColumn();
$balance = max((float) $receipt['amount_due'] - $paidSoFar, 0);
$items = [
    ['description' => 'Transport Fee Payment', 'quantity' => 1, 'unit_price' => $amountPaid, 'line_total' => $amountPaid],
];
$totals = [
    'subtotal' => $amountPaid,
    'discount' => 0,
    'grand_total' => $amountPaid,
    'amount_paid' => $amountPaid,
    'balance' => $balance,
];
$payment_method = 'M-PESA';
$mpesa_code = $receipt['reference_no'] ?? '';
$served_by = $_SESSION['admin_name'] ?? 'Admin';
$receipt_type = 'TRANSPORT FEES';
$back_href = url('admin/transport.php');
$pdf_href = url('admin/transport_receipt.php?id=' . (int) $receipt['id'] . '&format=pdf');

include __DIR__ . '/../includes/receipt_template.php';

require_once __DIR__ . '/../includes/layout_end.php';
