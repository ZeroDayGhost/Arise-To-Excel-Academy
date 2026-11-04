<?php

require_once __DIR__ . '/school_receipt_pdf.php';

function bulk_term_receipt_available_modules(PDO $pdo): array
{
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")
        ->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_fill_keys(array_map('strval', $tables), true);
    $requirements = [
        'fees' => ['students', 'fee_balances', 'fee_structures', 'fees'],
        'transport' => ['students', 'transport_students', 'transport_accounts', 'transport_payments'],
        'feeding' => ['students', 'feeding_subscriptions', 'feeding_payments'],
        'daycare' => ['academic_calendar', 'daycare_children', 'daycare_groups', 'daycare_fee_charges', 'daycare_payments', 'daycare_payment_allocations'],
        'uniform' => ['students', 'academic_calendar', 'uniform_sales', 'uniform_sale_items', 'uniforms'],
        'activities' => ['students', 'activities', 'activity_participants', 'activity_payments'],
    ];
    $labels = [
        'fees' => 'School Fees',
        'transport' => 'Transport',
        'feeding' => 'Feeding',
        'daycare' => 'Day Care',
        'uniform' => 'School Uniform',
        'activities' => 'Activities',
    ];

    $available = [];
    foreach ($requirements as $key => $requiredTables) {
        if (count(array_intersect($requiredTables, array_keys($tables))) === count($requiredTables)) {
            $available[$key] = $labels[$key];
        }
    }
    return $available;
}

function bulk_term_receipt_statement_rows(PDO $pdo, string $module, string $year, string $term, array $period): array
{
    if ($module === 'fees') {
        $rows = fetch_fee_term_report($pdo, [
            'year' => $year,
            'term' => $term,
            'enrollment_status' => 'All',
        ]);
        $statements = [];
        foreach ($rows as $row) {
            $balance = max(0.0, (float) ($row['balance'] ?? 0));
            if ($balance <= 0.005 || ($row['fee_sponsorship_status'] ?? '') === 'Sponsored Student') {
                continue;
            }
            $statements[] = [
                'student_id' => (int) $row['student_id'],
                'student_name' => (string) $row['full_name'],
                'admission_no' => (string) $row['registration_no'],
                'class_level' => (string) $row['class_level'],
                'required_amount' => (float) $row['required_amount'],
                'paid_amount' => (float) $row['paid_amount'],
                'balance' => $balance,
                'details' => ['School fee account for ' . $year . ' - ' . $term],
            ];
        }
        return $statements;
    }

    if ($module === 'feeding') {
        $classSql = student_class_sql('s', 'fs.academic_year');
        $stmt = $pdo->prepare("SELECT fs.id AS account_id, s.id AS student_id, s.full_name AS student_name,
                    s.registration_no AS admission_no, {$classSql} AS class_level,
                    fs.feeding_amount AS required_amount,
                    COALESCE(SUM(fp.amount_paid), 0) AS paid_amount,
                    fs.feeding_amount - COALESCE(SUM(fp.amount_paid), 0) AS balance
                FROM feeding_subscriptions fs
                JOIN students s ON s.id = fs.student_id
                LEFT JOIN feeding_payments fp ON fp.feeding_subscription_id = fs.id
                WHERE fs.academic_year = :academic_year AND fs.term = :term
                GROUP BY fs.id
                HAVING balance > 0.005
                ORDER BY s.full_name ASC");
        $stmt->execute(['academic_year' => $year, 'term' => $term]);
        return array_map(static function (array $row) use ($year, $term): array {
            return [
                'student_id' => (int) $row['student_id'],
                'student_name' => (string) $row['student_name'],
                'admission_no' => (string) $row['admission_no'],
                'class_level' => (string) $row['class_level'],
                'required_amount' => (float) $row['required_amount'],
                'paid_amount' => (float) $row['paid_amount'],
                'balance' => max(0.0, (float) $row['balance']),
                'details' => ['Feeding subscription for ' . $year . ' - ' . $term],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($module === 'transport') {
        $classSql = student_class_sql('s', 'ta.academic_year');
        $stmt = $pdo->prepare("SELECT ta.id AS account_id, ts.id AS transport_student_id,
                    s.id AS student_id,
                    COALESCE(NULLIF(s.full_name, ''), ts.student_name) AS student_name,
                    COALESCE(NULLIF(s.registration_no, ''), CONCAT('Outside account #', ts.id)) AS admission_no,
                    COALESCE({$classSql}, 'Outside transport account') AS class_level,
                    ts.pickup_location, ts.is_outside,
                    ta.amount_due AS required_amount,
                    COALESCE(SUM(tp.amount_paid), 0) AS paid_amount,
                    ta.amount_due - COALESCE(SUM(tp.amount_paid), 0) AS balance
                FROM transport_accounts ta
                JOIN transport_students ts ON ts.id = ta.transport_student_id
                LEFT JOIN students s ON s.id = ts.student_id
                LEFT JOIN transport_payments tp ON tp.transport_account_id = ta.id
                WHERE ta.academic_year = :academic_year AND ta.term = :term
                GROUP BY ta.id
                HAVING balance > 0.005
                ORDER BY student_name ASC");
        $stmt->execute(['academic_year' => $year, 'term' => $term]);
        $statements = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = ['Transport account for ' . $year . ' - ' . $term];
            if (trim((string) ($row['pickup_location'] ?? '')) !== '') {
                $details[] = 'Route / pickup: ' . $row['pickup_location'];
            }
            if ((int) ($row['is_outside'] ?? 0) === 1 || empty($row['student_id'])) {
                $details[] = 'Outside transport account';
            }
            $statements[] = [
                'student_id' => (int) ($row['student_id'] ?? 0),
                'student_name' => (string) $row['student_name'],
                'admission_no' => (string) $row['admission_no'],
                'class_level' => (string) $row['class_level'],
                'required_amount' => (float) $row['required_amount'],
                'paid_amount' => (float) $row['paid_amount'],
                'balance' => max(0.0, (float) $row['balance']),
                'details' => $details,
            ];
        }
        return $statements;
    }

    if ($module === 'daycare') {
        $stmt = $pdo->prepare("SELECT c.id AS charge_id, c.child_id, c.category, c.billing_period, c.description,
                    c.amount - c.discount AS required_amount,
                    COALESCE(SUM(CASE WHEN p.payment_status = 'Recorded' THEN a.amount ELSE 0 END), 0) AS paid_amount,
                    ch.admission_no, ch.full_name AS student_name, g.name AS group_name
                FROM daycare_fee_charges c
                JOIN daycare_children ch ON ch.id = c.child_id
                LEFT JOIN daycare_groups g ON g.id = ch.group_id
                LEFT JOIN daycare_payment_allocations a ON a.charge_id = c.id
                LEFT JOIN daycare_payments p ON p.id = a.payment_id
                WHERE c.status = 'Posted' AND c.charge_date >= :period_start AND c.charge_date <= :period_end
                GROUP BY c.id
                ORDER BY ch.full_name ASC, c.charge_date ASC, c.id ASC");
        $stmt->execute(['period_start' => $period['start_date'], 'period_end' => $period['end_date']]);
        $byChild = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $charge) {
            $required = max(0.0, (float) $charge['required_amount']);
            $paid = max(0.0, (float) $charge['paid_amount']);
            $outstanding = max(0.0, $required - $paid);
            if ($outstanding <= 0.005) {
                continue;
            }
            $id = (int) $charge['child_id'];
            if (!isset($byChild[$id])) {
                $byChild[$id] = [
                    'student_id' => $id,
                    'student_name' => (string) $charge['student_name'],
                    'admission_no' => (string) $charge['admission_no'],
                    'class_level' => trim((string) ($charge['group_name'] ?? '')) ?: 'Day Care',
                    'required_amount' => 0.0,
                    'paid_amount' => 0.0,
                    'balance' => 0.0,
                    'details' => [],
                ];
            }
            $byChild[$id]['required_amount'] += $required;
            $byChild[$id]['paid_amount'] += min($paid, $required);
            $byChild[$id]['balance'] += $outstanding;
            $label = trim((string) $charge['description']);
            if ($label === '') {
                $label = trim((string) $charge['category']);
            }
            if (trim((string) ($charge['billing_period'] ?? '')) !== '') {
                $label .= ' (' . $charge['billing_period'] . ')';
            }
            $byChild[$id]['details'][] = $label . ' - due ' . number_format($required, 2)
                . ', applied ' . number_format(min($paid, $required), 2)
                . ', outstanding ' . number_format($outstanding, 2);
        }
        return array_values($byChild);
    }

    if ($module === 'uniform') {
        $classSql = student_class_sql('s', "'{$year}'");
        $stmt = $pdo->prepare("SELECT us.id AS sale_id, us.student_id, us.receipt_no, us.grand_total,
                    us.amount_paid, us.balance, us.payment_date,
                    s.full_name AS student_name, s.registration_no AS admission_no,
                    {$classSql} AS class_level
                FROM uniform_sales us
                JOIN students s ON s.id = us.student_id
                WHERE DATE(us.payment_date) >= :period_start AND DATE(us.payment_date) <= :period_end
                ORDER BY s.full_name ASC, us.payment_date ASC, us.id ASC");
        $stmt->execute(['period_start' => $period['start_date'], 'period_end' => $period['end_date']]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$sales) {
            return [];
        }

        $itemsStmt = $pdo->prepare("SELECT si.sale_id, u.uniform_name, si.size, si.quantity, si.line_total
            FROM uniform_sale_items si
            JOIN uniform_sales us ON us.id = si.sale_id
            JOIN uniforms u ON u.id = si.uniform_id
            WHERE DATE(us.payment_date) >= :period_start AND DATE(us.payment_date) <= :period_end
            ORDER BY si.sale_id, si.id");
        $itemsStmt->execute(['period_start' => $period['start_date'], 'period_end' => $period['end_date']]);
        $itemsBySale = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsBySale[(int) $item['sale_id']][] = trim((string) $item['uniform_name']) . ' / size '
                . trim((string) $item['size']) . ' x ' . (int) $item['quantity']
                . ' - ' . number_format((float) $item['line_total'], 2);
        }

        $byStudent = [];
        foreach ($sales as $sale) {
            $balance = max(0.0, (float) $sale['balance']);
            if ($balance <= 0.005) {
                continue;
            }
            $id = (int) $sale['student_id'];
            if (!isset($byStudent[$id])) {
                $byStudent[$id] = [
                    'student_id' => $id,
                    'student_name' => (string) $sale['student_name'],
                    'admission_no' => (string) $sale['admission_no'],
                    'class_level' => (string) $sale['class_level'],
                    'required_amount' => 0.0,
                    'paid_amount' => 0.0,
                    'balance' => 0.0,
                    'details' => [],
                ];
            }
            $required = max(0.0, (float) $sale['grand_total']);
            $byStudent[$id]['required_amount'] += $required;
            $byStudent[$id]['paid_amount'] += max(0.0, $required - $balance);
            $byStudent[$id]['balance'] += $balance;
            $byStudent[$id]['details'][] = 'Sale ' . $sale['receipt_no'] . ' - '
                . date('d M Y', strtotime((string) $sale['payment_date']));
            foreach ($itemsBySale[(int) $sale['sale_id']] ?? [] as $itemLabel) {
                $byStudent[$id]['details'][] = $itemLabel;
            }
        }
        return array_values($byStudent);
    }

    if ($module === 'activities') {
        $data = activity_report_data($pdo, [
            'year' => $year,
            'term' => $term,
            'enrollment_status' => 'All',
        ]);
        $byStudent = [];
        foreach ($data['participants'] as $participant) {
            $outstanding = max(0.0, (float) $participant['outstanding']);
            if ($outstanding <= 0.005) {
                continue;
            }
            $id = (int) $participant['student_id'];
            if (!isset($byStudent[$id])) {
                $byStudent[$id] = [
                    'student_id' => $id,
                    'student_name' => (string) $participant['student_name'],
                    'admission_no' => (string) $participant['registration_no'],
                    'class_level' => (string) $participant['class_level'],
                    'required_amount' => 0.0,
                    'paid_amount' => 0.0,
                    'balance' => 0.0,
                    'details' => [],
                ];
            }
            $required = max(0.0, (float) $participant['expected_amount']);
            $paid = min($required, max(0.0, (float) $participant['amount_paid']));
            $byStudent[$id]['required_amount'] += $required;
            $byStudent[$id]['paid_amount'] += $paid;
            $byStudent[$id]['balance'] += $outstanding;
            $byStudent[$id]['details'][] = trim((string) $participant['activity_name']) . ' - '
                . trim((string) $participant['activity_type']) . ' - due '
                . number_format($required, 2) . ', paid ' . number_format($paid, 2)
                . ', outstanding ' . number_format($outstanding, 2);
        }
        return array_values($byStudent);
    }

    throw new InvalidArgumentException('Select a supported report module.');
}

function bulk_term_receipt_format_amount(float $amount): string
{
    return 'KES ' . number_format($amount, 2);
}

function bulk_term_receipt_page_stream(array $statement, array $context, ?array $image, int $number, int $count): string
{
    $width = 595;
    $height = 842;
    $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Arise To Excel Academy';
    $address = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : '';
    $contact = trim((defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '')
        . ((defined('SCHOOL_PHONE') && defined('SCHOOL_EMAIL')) ? ' | ' : '')
        . (defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : ''));
    $stream = school_receipt_pdf_rect(0, 0, $width, $height, '1 1 1');
    $stream .= school_receipt_pdf_rect(0, 746, $width, 96, '0.05 0.20 0.38');
    if ($image) {
        $stream .= "q 52 0 0 52 34 768 cm /Im1 Do Q\n";
    }
    $stream .= school_receipt_pdf_text(102, 810, $schoolName, 16, 'F2', '1 1 1');
    if ($address !== '') {
        $stream .= school_receipt_pdf_text(102, 792, $address, 8, 'F1', '0.88 0.93 0.98');
    }
    if ($contact !== '') {
        $stream .= school_receipt_pdf_text(102, 778, $contact, 8, 'F1', '0.88 0.93 0.98');
    }
    $stream .= school_receipt_pdf_text(398, 811, 'TERM BALANCE', 10, 'F2', '0.62 0.85 1');
    $stream .= school_receipt_pdf_text(398, 793, (string) $context['module_label'], 9, 'F2', '1 1 1');

    $stream .= school_receipt_pdf_text(38, 716, 'TERM BALANCE STATEMENT', 18, 'F2', '0.05 0.20 0.38');
    $stream .= school_receipt_pdf_text(38, 696, 'Statement No. ' . $statement['statement_no'], 8, 'F1', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(398, 696, 'Generated ' . date('d M Y'), 8, 'F1', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_rect(36, 611, 523, 64, '0.96 0.98 1', '0.83 0.89 0.94');

    $studentName = trim((string) $statement['student_name']);
    $nameLines = school_receipt_pdf_wrap($studentName, 315, 12);
    $stream .= school_receipt_pdf_text(50, 654, 'STUDENT', 7, 'F2', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(50, 635, $nameLines[0] ?? '', 12, 'F2');
    if (isset($nameLines[1])) {
        $stream .= school_receipt_pdf_text(50, 620, $nameLines[1], 10, 'F2');
    }
    $stream .= school_receipt_pdf_text(387, 654, 'ADMISSION / ACCOUNT', 7, 'F2', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(387, 635, (string) $statement['admission_no'], 10, 'F2');

    $stream .= school_receipt_pdf_text(40, 582, 'CLASS / GROUP', 7, 'F2', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(40, 566, (string) ($statement['class_level'] ?: 'Not recorded'), 9, 'F2');
    $stream .= school_receipt_pdf_text(225, 582, 'ACADEMIC YEAR', 7, 'F2', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(225, 566, (string) $context['year'], 9, 'F2');
    $stream .= school_receipt_pdf_text(390, 582, 'TERM', 7, 'F2', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(390, 566, (string) $context['term'], 9, 'F2');
    $stream .= school_receipt_pdf_rect(36, 551, 523, 1, '0.84 0.89 0.94');

    $stream .= school_receipt_pdf_text(40, 524, 'ACCOUNT SUMMARY', 9, 'F2', '0.05 0.42 0.65');
    $cards = [
        ['label' => 'AMOUNT PAYABLE', 'amount' => (float) $statement['required_amount'], 'x' => 36, 'fill' => '0.95 0.98 1', 'color' => '0.05 0.42 0.65'],
        ['label' => 'AMOUNT PAID', 'amount' => (float) $statement['paid_amount'], 'x' => 213, 'fill' => '0.95 0.98 0.96', 'color' => '0.06 0.48 0.29'],
        ['label' => 'BALANCE DUE', 'amount' => (float) $statement['balance'], 'x' => 390, 'fill' => '1 0.97 0.92', 'color' => '0.69 0.34 0.04'],
    ];
    foreach ($cards as $card) {
        $stream .= school_receipt_pdf_rect((float) $card['x'], 464, 169, 45, (string) $card['fill'], '0.84 0.89 0.94');
        $stream .= school_receipt_pdf_text((float) $card['x'] + 10, 493, (string) $card['label'], 7, 'F2', '0.35 0.43 0.53');
        $stream .= school_receipt_pdf_text((float) $card['x'] + 10, 476, bulk_term_receipt_format_amount((float) $card['amount']), 10, 'F2', (string) $card['color']);
    }

    $stream .= school_receipt_pdf_text(40, 434, 'MODULE DETAILS', 9, 'F2', '0.05 0.42 0.65');
    $stream .= school_receipt_pdf_rect(36, 407, 523, 19, '0.05 0.42 0.65');
    $stream .= school_receipt_pdf_text(48, 414, 'DESCRIPTION', 7, 'F2', '1 1 1');
    $stream .= school_receipt_pdf_text(430, 414, 'PERIOD', 7, 'F2', '1 1 1');
    $details = array_values(array_filter(array_map('strval', (array) ($statement['details'] ?? [])), static fn(string $detail): bool => trim($detail) !== ''));
    if (!$details) {
        $details = [(string) $context['module_label'] . ' account balance'];
    }
    $maxDetails = 9;
    $omitted = max(0, count($details) - $maxDetails);
    $details = array_slice($details, 0, $maxDetails);
    if ($omitted > 0) {
        $details[] = $omitted . ' additional account detail(s) omitted; summary totals include all records.';
    }
    $y = 387;
    foreach ($details as $index => $detail) {
        $lineList = school_receipt_pdf_wrap($detail, 360, 8.5);
        $lineList = array_slice($lineList, 0, 2);
        $rowHeight = max(25, count($lineList) * 11 + 8);
        if ($index % 2 === 0) {
            $stream .= school_receipt_pdf_rect(36, $y - $rowHeight + 5, 523, $rowHeight, '0.97 0.98 0.99');
        }
        foreach ($lineList as $lineIndex => $line) {
            $stream .= school_receipt_pdf_text(48, $y - ($lineIndex * 11), $line, 8.5, 'F1');
        }
        $stream .= school_receipt_pdf_text(430, $y, $context['year'] . ' - ' . $context['term'], 8, 'F1', '0.35 0.43 0.53');
        $y -= $rowHeight;
    }

    $stream .= school_receipt_pdf_rect(36, 72, 523, 1, '0.84 0.89 0.94');
    $stream .= school_receipt_pdf_text(40, 54, 'This statement shows an outstanding account; it is not proof of payment.', 8, 'F2', '0.69 0.34 0.04');
    $stream .= school_receipt_pdf_text(40, 38, 'Arise To Excel Academy  |  ' . $context['year'] . ' - ' . $context['term'], 7.5, 'F1', '0.35 0.43 0.53');
    $stream .= school_receipt_pdf_text(495, 38, 'Page ' . $number . ' of ' . $count, 7.5, 'F1', '0.35 0.43 0.53');
    return $stream;
}

function bulk_term_receipt_pdf(array $statements, array $context): string
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    $image = null;
    if (is_file($logoPath) && ($dimensions = @getimagesize($logoPath)) && ($data = @file_get_contents($logoPath)) !== false) {
        $image = ['data' => $data, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    $pageWidth = 595;
    $pageHeight = 842;
    $token = date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
    $streams = [];
    $total = count($statements);
    foreach ($statements as $index => $statement) {
        $statement['statement_no'] = 'TBS-' . strtoupper(substr((string) $context['module'], 0, 3))
            . '-' . $context['year'] . '-' . str_replace(' ', '', (string) $context['term'])
            . '-' . $token . '-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
        $streams[] = bulk_term_receipt_page_stream($statement, $context, $image, $index + 1, $total);
    }

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $nextId = 5;
    $imageId = 0;
    if ($image) {
        $imageId = $nextId++;
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n{$image['data']}\nendstream";
    }

    $kids = [];
    foreach ($streams as $stream) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $kids[] = $pageId . ' 0 R';
        $xObject = $imageId ? " /XObject << /Im1 {$imageId} 0 R >>" : '';
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$xObject} >> /Contents {$contentId} 0 R >>";
        $objects[$contentId] = '<< /Length ' . strlen($stream) . ">>\nstream\n{$stream}endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
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
