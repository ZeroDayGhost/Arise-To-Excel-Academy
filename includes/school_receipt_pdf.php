<?php

function school_receipt_pdf_text_value($value): string
{
    $value = (string) $value;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^\x20-\xFF]/', ' ', $value);
    return str_replace(["\\", '(', ')'], ["\\\\", '\\(', '\\)'], $value);
}

function school_receipt_pdf_text(float $x, float $y, string $text, float $size = 10, string $font = 'F1', string $color = '0.08 0.16 0.27'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . school_receipt_pdf_text_value($text) . ") Tj ET\n";
}

function school_receipt_pdf_rect(float $x, float $y, float $width, float $height, string $fill, string $stroke = ''): string
{
    $stream = "{$fill} rg {$x} {$y} {$width} {$height} re f\n";
    if ($stroke !== '') {
        $stream .= "{$stroke} RG {$x} {$y} {$width} {$height} re S\n";
    }
    return $stream;
}

function school_receipt_pdf_wrap(string $text, float $width, float $fontSize): array
{
    $plain = school_receipt_pdf_text_value($text);
    $plain = str_replace(["\\\\", '\\(', '\\)'], ['\\', '(', ')'], $plain);
    $maxCharacters = max(12, (int) floor($width / ($fontSize * 0.52)));
    $lines = [];
    foreach (preg_split('/\R/', $plain) ?: [''] as $paragraph) {
        $wrapped = wordwrap($paragraph, $maxCharacters, "\n", true);
        foreach (explode("\n", $wrapped) as $line) {
            $lines[] = trim($line);
        }
    }
    return $lines ?: [''];
}

function school_receipt_pdf_detail(string &$stream, float $y, string $label, string $value): float
{
    $lines = school_receipt_pdf_wrap($value, 220, 9);
    $height = max(25, count($lines) * 11 + 14);
    $stream .= school_receipt_pdf_text(36, $y, $label, 7.5, 'F1', '0.35 0.43 0.53');
    foreach ($lines as $index => $line) {
        $stream .= school_receipt_pdf_text(152, $y - ($index * 11), $line, 9, 'F2');
    }
    $stream .= "0.88 0.91 0.95 RG 34 " . ($y - $height + 6) . " m 386 " . ($y - $height + 6) . " l S\n";
    return $y - $height;
}

function school_receipt_pdf_document(string $stream, ?array $image, float $pageWidth = 420, float $pageHeight = 595): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $imageId = 0;
    if ($image) {
        $imageId = 6;
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n{$image['data']}\nendstream";
    }
    $xObject = $imageId ? " /XObject << /Im1 {$imageId} 0 R >>" : '';
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>{$xObject} >> /Contents 7 0 R >>";
    $objects[7] = '<< /Length ' . strlen($stream) . ">>\nstream\n{$stream}endstream";
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= isset($offsets[$id])
            ? str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT) . " 00000 n \n"
            : "0000000000 65535 f \n";
    }
    return $pdf . "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
}

function build_school_receipt_pdf(array $receipt): string
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    $image = null;
    if (is_file($logoPath) && ($dimensions = @getimagesize($logoPath)) && ($data = @file_get_contents($logoPath)) !== false) {
        $image = ['data' => $data, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Arise To Excel Academy';
    $schoolAddress = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : '';
    $schoolPhone = defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '';
    $schoolEmail = defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : '';
    $receiptNo = (string) ($receipt['receipt_no'] ?? '');
    $paymentDate = !empty($receipt['payment_date']) ? date('M d, Y', strtotime((string) $receipt['payment_date'])) : '';
    $amount = function_exists('money') ? money((float) ($receipt['amount_paid'] ?? 0)) : 'KES ' . number_format((float) ($receipt['amount_paid'] ?? 0), 2);
    $balanceValue = $receipt['balance_after_payment'] ?? $receipt['current_balance'] ?? null;
    $status = (string) ($receipt['payment_status'] ?? 'Recorded');
    $pageWidth = 420;
    $pageHeight = 595;
    $content = school_receipt_pdf_rect(0, 510, $pageWidth, 85, '0.95 0.98 1');
    $content .= school_receipt_pdf_rect(0, 508, $pageWidth, 2, '0.05 0.57 0.76');
    if ($image) {
        $content .= "q 48 0 0 48 32 528 cm /Im1 Do Q\n";
    }
    $content .= school_receipt_pdf_text(92, 568, $schoolName, 14, 'F2');
    $content .= school_receipt_pdf_text(92, 551, $schoolAddress, 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(92, 537, trim($schoolPhone . ($schoolPhone && $schoolEmail ? ' | ' : '') . $schoolEmail), 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(286, 568, 'OFFICIAL RECEIPT', 9, 'F2', '0.05 0.42 0.65');
    $content .= school_receipt_pdf_text(286, 551, $receiptNo, 8, 'F2');

    $content .= school_receipt_pdf_text(36, 480, 'PAYMENT RECEIPT', 13, 'F2');
    $content .= school_receipt_pdf_text(36, 462, 'Receipt number: ' . $receiptNo, 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(278, 480, 'Date: ' . $paymentDate, 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_rect(36, 430, 348, 22, '0.94 0.98 0.96', '0.82 0.90 0.85');
    $content .= school_receipt_pdf_text(48, 437, 'PAYMENT STATUS: ' . strtoupper($status), 8, 'F2', '0.06 0.38 0.24');

    $y = 406;
    $details = [
        ['Student name', $receipt['full_name'] ?? ''],
        ['Admission number', $receipt['registration_no'] ?? ''],
        ['Class', $receipt['class_level'] ?? ''],
        ['Enrollment status', $receipt['enrollment_status'] ?? ''],
        ['Academic period', trim((string) ($receipt['year'] ?? '') . ' - ' . (string) ($receipt['term'] ?? ''))],
        ['Payment method', $receipt['payment_method'] ?? 'M-PESA'],
    ];
    $reference = trim((string) ($receipt['mpesa_code'] ?? ''));
    if ($reference !== '') {
        $details[] = ['M-PESA transaction code', $reference];
    }
    $referenceText = trim((string) ($receipt['mpesa_reference_text'] ?? ''));
    if ($referenceText !== '') {
        $details[] = ['Payment reference', $referenceText];
    }
    if ($balanceValue !== null && is_numeric($balanceValue)) {
        $details[] = ['Balance after payment', function_exists('money') ? money((float) $balanceValue) : 'KES ' . number_format((float) $balanceValue, 2)];
    }
    if (array_key_exists('balance_before_payment', $receipt) && is_numeric($receipt['balance_before_payment'])) {
        $details[] = ['Balance before payment', function_exists('money') ? money((float) $receipt['balance_before_payment']) : 'KES ' . number_format((float) $receipt['balance_before_payment'], 2)];
    }
    if ($status !== 'Recorded' && !empty($receipt['reversal_reason'])) {
        $details[] = ['Action note', (string) $receipt['reversal_reason']];
    }
    foreach ($details as [$label, $value]) {
        if ((string) $value !== '') {
            $y = school_receipt_pdf_detail($content, $y, (string) $label, (string) $value);
        }
    }

    $amountBoxY = $y - 48;
    $content .= school_receipt_pdf_rect(36, $amountBoxY, 348, 56, '0.93 0.97 1', '0.75 0.86 0.94');
    $content .= school_receipt_pdf_text(50, $amountBoxY + 36, 'AMOUNT RECEIVED', 8, 'F2', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(50, $amountBoxY + 12, $amount, 18, 'F2', '0.05 0.42 0.65');
    $signatureLabelY = $amountBoxY - 24;
    $signatureLineY = $signatureLabelY - 20;
    $content .= school_receipt_pdf_text(36, $signatureLabelY, 'Received by', 7.5, 'F1', '0.35 0.43 0.53');
    $content .= "0.55 0.62 0.69 RG 36 {$signatureLineY} m 188 {$signatureLineY} l S\n";
    $content .= school_receipt_pdf_text(222, $signatureLabelY, 'School stamp', 7.5, 'F1', '0.35 0.43 0.53');
    $content .= "0.55 0.62 0.69 RG 222 {$signatureLineY} m 384 {$signatureLineY} l S\n";

    $footerY = $signatureLineY - 32;
    $verticalOffset = max(0, 40 - $footerY);
    $pageHeight += $verticalOffset;
    $stream = school_receipt_pdf_rect(0, 0, $pageWidth, $pageHeight, '1 1 1');
    $stream .= "q 1 0 0 1 0 {$verticalOffset} cm\n" . $content . "Q\n";
    $stream .= school_receipt_pdf_text(36, $footerY + $verticalOffset, 'Thank you for your payment. Please retain this receipt for your records.', 7.5, 'F1', '0.35 0.43 0.53');
    return school_receipt_pdf_document($stream, $image, $pageWidth, $pageHeight);
}

function build_combined_school_receipt_pdf(array $transaction, array $allocations): string
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    $image = null;
    if (is_file($logoPath) && ($dimensions = @getimagesize($logoPath)) && ($data = @file_get_contents($logoPath)) !== false) {
        $image = ['data' => $data, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    $pageWidth = 420;
    $rowHeight = 24;
    $pageHeight = 650 + (max(0, count($allocations) - 5) * $rowHeight);
    $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Arise To Excel Academy';
    $schoolAddress = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : '';
    $schoolPhone = defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '';
    $schoolEmail = defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : '';
    $receiptNo = (string) ($transaction['receipt_no'] ?? '');
    $date = !empty($transaction['payment_date']) ? date('M d, Y', strtotime((string) $transaction['payment_date'])) : '';
    $parentName = trim((string) ($transaction['parent_name'] ?? ''));
    $studentNames = [];
    foreach ($allocations as $allocation) {
        $studentName = trim((string) ($allocation['student_name'] ?? ''));
        $admissionNo = trim((string) ($allocation['admission_no'] ?? ''));
        if ($studentName !== '') {
            $studentNames[$studentName . ($admissionNo !== '' ? ' (' . $admissionNo . ')' : '')] = true;
        }
    }
    $studentsLabel = implode(', ', array_keys($studentNames));
    $status = strtoupper((string) ($transaction['status'] ?? 'RECORDED'));
    $amount = (float) ($transaction['total_amount'] ?? 0);
    $feeBalanceAllocations = array_values(array_filter($allocations, static fn(array $allocation): bool =>
        ($allocation['source_module'] ?? '') === 'fees' && is_numeric($allocation['fee_balance_after_payment'] ?? null)
    ));
    $showFeeBalance = count($feeBalanceAllocations) > 0;
    $feeBalanceTotal = array_sum(array_map(static fn(array $allocation): float => (float) $allocation['fee_balance_after_payment'], $feeBalanceAllocations));
    $content = school_receipt_pdf_rect(0, $pageHeight - 85, $pageWidth, 85, '0.95 0.98 1');
    $content .= school_receipt_pdf_rect(0, $pageHeight - 87, $pageWidth, 2, '0.05 0.57 0.76');
    if ($image) {
        $content .= 'q 48 0 0 48 32 ' . ($pageHeight - 68) . " cm /Im1 Do Q\n";
    }
    $content .= school_receipt_pdf_text(92, $pageHeight - 27, $schoolName, 14, 'F2');
    $content .= school_receipt_pdf_text(92, $pageHeight - 44, $schoolAddress, 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(92, $pageHeight - 58, trim($schoolPhone . ($schoolPhone && $schoolEmail ? ' | ' : '') . $schoolEmail), 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(286, $pageHeight - 27, 'OFFICIAL RECEIPT', 9, 'F2', '0.05 0.42 0.65');
    $content .= school_receipt_pdf_text(286, $pageHeight - 44, $receiptNo, 8, 'F2');

    $content .= school_receipt_pdf_text(36, $pageHeight - 115, 'PAYMENT RECEIPT', 13, 'F2');
    $content .= school_receipt_pdf_text(36, $pageHeight - 133, 'Receipt number: ' . $receiptNo, 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(278, $pageHeight - 115, 'Date: ' . $date, 8, 'F1', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_rect(36, $pageHeight - 165, 348, 22, '0.94 0.98 0.96', '0.82 0.90 0.85');
    $content .= school_receipt_pdf_text(48, $pageHeight - 158, 'PAYMENT STATUS: ' . $status, 8, 'F2', '0.06 0.38 0.24');

    $y = $pageHeight - 190;
    foreach ([
        ['Student name', $studentsLabel],
        ['Parent / payer', $parentName],
        ['Students covered', (string) count(array_unique(array_map(static fn($row) => (string) ($row['student_id'] ?? $row['student_name'] ?? ''), $allocations)))],
        ['Payment method', (string) ($transaction['payment_method'] ?? '')],
    ] as [$label, $value]) {
        $y = school_receipt_pdf_detail($content, $y, $label, $value);
    }
    $code = trim((string) ($transaction['mpesa_code'] ?? ''));
    if ($code !== '') {
        $y = school_receipt_pdf_detail($content, $y, 'M-PESA transaction code', $code);
    }

    $tableTop = $y - 8;
    $content .= school_receipt_pdf_rect(36, $tableTop - 19, 348, 22, '0.93 0.97 1', '0.75 0.86 0.94');
    $content .= school_receipt_pdf_text(44, $tableTop - 12, 'STUDENT AND PAYMENT CATEGORY', 7, 'F2', '0.18 0.34 0.50');
    if ($showFeeBalance) {
        $content .= school_receipt_pdf_text(250, $tableTop - 12, 'FEE BALANCE', 7, 'F2', '0.18 0.34 0.50');
    }
    $content .= school_receipt_pdf_text($showFeeBalance ? 335 : 330, $tableTop - 12, 'AMOUNT', 7, 'F2', '0.18 0.34 0.50');
    $cursor = $tableTop - 33;
    foreach ($allocations as $index => $allocation) {
        $student = trim((string) ($allocation['student_name'] ?? 'Student'));
        $admission = trim((string) ($allocation['admission_no'] ?? ''));
        $category = trim((string) ($allocation['payment_category'] ?? 'Payment'));
        $description = $student . ($admission !== '' ? ' (' . $admission . ')' : '') . ' - ' . $category;
        $lines = school_receipt_pdf_wrap($description, $showFeeBalance ? 195 : 270, 8);
        $height = max($rowHeight, count($lines) * 10 + 10);
        foreach ($lines as $lineIndex => $line) {
            $content .= school_receipt_pdf_text(44, $cursor - ($lineIndex * 10), $line, 8, $lineIndex === 0 ? 'F2' : 'F1');
        }
        if ($showFeeBalance) {
            $feeBalance = ($allocation['source_module'] ?? '') === 'fees' && is_numeric($allocation['fee_balance_after_payment'] ?? null)
                ? 'KES ' . number_format((float) $allocation['fee_balance_after_payment'], 2)
                : '-';
            $content .= school_receipt_pdf_text(247, $cursor, $feeBalance, 6.5, 'F1');
        }
        $content .= school_receipt_pdf_text(329, $cursor, 'KES ' . number_format((float) ($allocation['amount'] ?? 0), 2), 7.5, 'F2');
        $content .= "0.88 0.91 0.95 RG 36 " . ($cursor - $height + 3) . " m 384 " . ($cursor - $height + 3) . " l S\n";
        $cursor -= $height;
    }

    $boxHeight = $showFeeBalance ? 76 : 56;
    $boxY = $cursor - $boxHeight - 8;
    $content .= school_receipt_pdf_rect(36, $boxY, 348, $boxHeight, '0.93 0.97 1', '0.75 0.86 0.94');
    $content .= school_receipt_pdf_text(50, $boxY + ($showFeeBalance ? 56 : 36), 'AMOUNT RECEIVED', 8, 'F2', '0.35 0.43 0.53');
    $content .= school_receipt_pdf_text(50, $boxY + ($showFeeBalance ? 34 : 12), 'KES ' . number_format($amount, 2), 18, 'F2', '0.05 0.42 0.65');
    if ($showFeeBalance) {
        $content .= school_receipt_pdf_text(50, $boxY + 12, 'OUTSTANDING FEE BALANCES AFTER PAYMENT', 7.5, 'F2', '0.35 0.43 0.53');
        $content .= school_receipt_pdf_text(295, $boxY + 12, 'KES ' . number_format($feeBalanceTotal, 2), 8, 'F2');
    }
    $signatureY = $boxY - 28;
    $content .= school_receipt_pdf_text(36, $signatureY, 'Received by', 7.5, 'F1', '0.35 0.43 0.53');
    $content .= '0.55 0.62 0.69 RG 36 ' . ($signatureY - 20) . " m 188 " . ($signatureY - 20) . " l S\n";
    $content .= school_receipt_pdf_text(222, $signatureY, 'School stamp', 7.5, 'F1', '0.35 0.43 0.53');
    $content .= '0.55 0.62 0.69 RG 222 ' . ($signatureY - 20) . " m 384 " . ($signatureY - 20) . " l S\n";
    $footerY = $signatureY - 55;
    $pageHeight += max(0, 40 - $footerY);
    $offset = $pageHeight - (650 + (max(0, count($allocations) - 5) * $rowHeight));
    $stream = school_receipt_pdf_rect(0, 0, $pageWidth, $pageHeight, '1 1 1');
    $stream .= "q 1 0 0 1 0 {$offset} cm\n" . $content . "Q\n";
    $stream .= school_receipt_pdf_text(36, $footerY + $offset, 'Thank you for your payment. Please retain this receipt for your records.', 7.5, 'F1', '0.35 0.43 0.53');
    return school_receipt_pdf_document($stream, $image, $pageWidth, $pageHeight);
}
