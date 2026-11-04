<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$report = $_GET['report'] ?? 'sales';
$format = $_GET['format'] ?? 'csv';

function uniform_report_pdf_escape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function uniform_report_pdf_text(float $x, float $y, string $text, float $size = 9, string $font = 'F1', string $color = '0 0 0'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . uniform_report_pdf_escape($text) . ") Tj ET\n";
}

function uniform_report_pdf_rect(float $x, float $y, float $w, float $h, string $fill, string $stroke = ''): string
{
    $command = "{$fill} rg {$x} {$y} {$w} {$h} re f\n";
    if ($stroke !== '') {
        $command .= "{$stroke} RG {$x} {$y} {$w} {$h} re S\n";
    }
    return $command;
}

function uniform_report_pdf_document(array $pageStreams): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Count ' . count($pageStreams) . ' /Kids [',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];

    $nextObjectId = 6;
    $kids = [];
    foreach ($pageStreams as $stream) {
        $pageId = $nextObjectId++;
        $contentId = $nextObjectId++;
        $kids[] = "{$pageId} 0 R";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents {$contentId} 0 R >>";
        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    }
    $objects[2] .= implode(' ', $kids) . '] >>';

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
        if (isset($offsets[$id])) {
            $pdf .= str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        } else {
            $pdf .= "0000000000 65535 f \n";
        }
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function build_uniform_report_pdf(array $rows, string $report): string
{
    $title = 'Uniform Report';
    $headers = [];
    $tableRows = [];

    if ($report === 'sales') {
        $title = 'Uniform Sales Report';
        $headers = ['Receipt', 'Registration', 'Student', 'Enrollment', 'Amount', 'Paid', 'Balance', 'Date'];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['receipt_no'],
                $row['registration_no'],
                $row['full_name'],
                $row['enrollment_status'] ?: 'Not linked to a student',
                'KES ' . number_format((float) $row['grand_total'], 2),
                'KES ' . number_format((float) $row['amount_paid'], 2),
                'KES ' . number_format((float) $row['balance'], 2),
                $row['payment_date'],
            ];
        }
    } elseif ($report === 'stock') {
        $title = 'Uniform Stock Report';
        $headers = ['Name', 'Gender', 'Category', 'Size', 'Price', 'Opening', 'Available', 'Reorder', 'Status'];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['uniform_name'],
                $row['gender'],
                $row['category'],
                $row['size'],
                'KES ' . number_format((float) $row['selling_price'], 2),
                (int) $row['opening_stock'],
                (int) $row['available_stock'],
                (int) $row['reorder_level'],
                $row['variant_status'],
            ];
        }
    } else {
        $title = 'Uniform Low Stock Report';
        $headers = ['Name', 'Gender', 'Category', 'Size', 'Price', 'Available', 'Reorder'];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['uniform_name'],
                $row['gender'],
                $row['category'],
                $row['size'],
                'KES ' . number_format((float) $row['selling_price'], 2),
                (int) $row['available_stock'],
                (int) $row['reorder_level'],
            ];
        }
    }

    $rowsPerPage = $report === 'sales' ? 14 : 20;
    $pages = array_chunk($tableRows, $rowsPerPage);
    if (!$pages) {
        $pages = [[]];
    }

    $pageStreams = [];
    foreach ($pages as $pageIndex => $pageRows) {
        $stream = '';
        $stream .= uniform_report_pdf_rect(0, 0, 842, 595, '1 1 1');
        $stream .= uniform_report_pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
        $stream .= uniform_report_pdf_text(30, 558, SCHOOL_NAME, 15, 'F2', '0.06 0.09 0.16');
        $stream .= uniform_report_pdf_text(30, 540, $title, 12, 'F2', '0.10 0.22 0.57');
        $stream .= uniform_report_pdf_text(30, 524, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.30 0.36 0.45');
        $stream .= uniform_report_pdf_text(30, 510, 'Report: ' . ucfirst($report), 8, 'F1', '0.30 0.36 0.45');
        $stream .= uniform_report_pdf_text(30, 496, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 7, 'F1', '0.30 0.36 0.45');

        $y = 460;
        $stream .= uniform_report_pdf_rect(30, $y, 760, 20, '0.11 0.30 0.85');
        $columnWidth = intdiv(760, max(1, count($headers)));
        $x = 30;
        foreach ($headers as $header) {
            $stream .= uniform_report_pdf_text($x + 3, $y + 6.5, $header, 7, 'F2', '1 1 1');
            $x += $columnWidth;
        }

        $y -= 24;
        if (!$pageRows) {
            $stream .= uniform_report_pdf_text(30, $y, 'No records found.', 9, 'F1', '0.06 0.09 0.16');
        } else {
            foreach ($pageRows as $rowIndex => $values) {
                $fill = $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1';
                $stream .= uniform_report_pdf_rect(30, $y, 760, 18, $fill, '0.88 0.91 0.95');
                $x = 30;
                foreach ($values as $value) {
                    $text = mb_strimwidth((string) $value, 0, 32, '');
                    $stream .= uniform_report_pdf_text($x + 3, $y + 5, $text, 6.8, 'F1', '0.06 0.09 0.16');
                    $x += $columnWidth;
                }
                $y -= 18;
            }
        }

        $stream .= uniform_report_pdf_text(30, 22, SCHOOL_NAME, 7, 'F1', '0.39 0.45 0.55');
        $pageStreams[] = $stream;
    }

    return uniform_report_pdf_document($pageStreams);
}

if ($format === 'pdf') {
    if ($report === 'sales') {
        $rows = $pdo->query("SELECT us.receipt_no, s.registration_no, s.full_name, s.enrollment_status, us.grand_total, us.amount_paid, us.balance, us.payment_date FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id ORDER BY us.payment_date DESC")->fetchAll();
    } elseif ($report === 'stock') {
        $rows = uniform_stock_report_rows($pdo);
    } else {
        $rows = uniform_stock_report_rows($pdo, true);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="uniform_report_' . $report . '_' . date('Y-m-d') . '.pdf"');
    echo build_uniform_report_pdf($rows, $report);
    exit;
}

// CSV/XLSX handled via CSV content; Print renders HTML table.
if ($format === 'print') {
    require_once __DIR__ . '/../includes/admin_header.php';
    echo '<div class="page-title"><div><p class="eyebrow">Export</p><h1>Uniform Report - ' . h(ucfirst($report)) . '</h1></div><div class="action-row"><button class="btn btn-primary" onclick="window.print()">Print</button><a class="btn btn-outline-primary" href="' . url('admin/uniform_reports.php?report=' . $report) . '">Back</a></div></div>';
    echo '<section class="panel"><div class="mt-4">';
    if ($report === 'sales') {
        $rows = $pdo->query("SELECT us.receipt_no, s.registration_no, s.full_name, s.enrollment_status, us.grand_total, us.amount_paid, us.balance, us.payment_date FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id ORDER BY us.payment_date DESC")->fetchAll();
        echo '<h3>Uniform Sales</h3><div class="table-responsive"><table class="table"><thead><tr><th>Receipt</th><th>Student</th><th>Enrollment</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Date</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . h($r['receipt_no']) . '</td><td>' . h(($r['registration_no'] ?: 'N/A').' - '.($r['full_name'] ?: 'Unlinked sale')) . '</td><td>' . h($r['enrollment_status'] ?: 'Not linked to a student') . '</td><td>' . money((float)$r['grand_total']) . '</td><td>' . money((float)$r['amount_paid']) . '</td><td>' . money((float)$r['balance']) . '</td><td>' . h($r['payment_date']) . '</td></tr>';
        echo '</tbody></table></div>';
    } elseif ($report === 'stock') {
        $rows = uniform_stock_report_rows($pdo);
        echo '<h3>Stock Report</h3><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Gender</th><th>Category</th><th>Size</th><th>Price</th><th>Opening</th><th>Available</th><th>Reorder</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . h($r['uniform_name']) . '</td><td>' . h($r['gender']) . '</td><td>' . h($r['category']) . '</td><td>' . h($r['size']) . '</td><td>' . money((float)$r['selling_price']) . '</td><td>' . (int)$r['opening_stock'] . '</td><td>' . (int)$r['available_stock'] . '</td><td>' . (int)$r['reorder_level'] . '</td><td>' . h($r['variant_status']) . '</td></tr>';
        echo '</tbody></table></div>';
    } else {
        $rows = uniform_stock_report_rows($pdo, true);
        echo '<h3>Low Stock</h3><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Gender</th><th>Category</th><th>Size</th><th>Price</th><th>Available</th><th>Reorder</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . h($r['uniform_name']) . '</td><td>' . h($r['gender']) . '</td><td>' . h($r['category']) . '</td><td>' . h($r['size']) . '</td><td>' . money((float)$r['selling_price']) . '</td><td>' . (int)$r['available_stock'] . '</td><td>' . (int)$r['reorder_level'] . '</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</div></section>';
    require_once __DIR__ . '/../includes/layout_end.php';
    exit;
}

$outFormat = $format === 'xlsx' ? 'xlsx' : 'csv';
$filename = 'uniform_report_' . $report . '.' . ($outFormat === 'xlsx' ? 'xlsx' : 'csv');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'wb');
if ($report === 'sales') {
    fputcsv($out, ['Receipt','Registration','Student','Enrollment','Amount','Paid','Balance','Date']);
    $rows = $pdo->query("SELECT us.receipt_no, s.registration_no, s.full_name, s.enrollment_status, us.grand_total, us.amount_paid, us.balance, us.payment_date FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id ORDER BY us.payment_date DESC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['receipt_no'],$r['registration_no'],$r['full_name'],$r['enrollment_status'] ?: 'Not linked to a student',$r['grand_total'],$r['amount_paid'],$r['balance'],$r['payment_date']]);
    }
} elseif ($report === 'stock') {
    fputcsv($out, ['Name','Gender','Category','Size','Price','Opening','Available','Reorder','Status']);
    $rows = uniform_stock_report_rows($pdo);
    foreach ($rows as $r) {
        fputcsv($out, [$r['uniform_name'],$r['gender'],$r['category'],$r['size'],$r['selling_price'],$r['opening_stock'],$r['available_stock'],$r['reorder_level'],$r['variant_status']]);
    }
} else {
    fputcsv($out, ['Name','Gender','Category','Size','Price','Available','Reorder']);
    $rows = uniform_stock_report_rows($pdo, true);
    foreach ($rows as $r) {
        fputcsv($out, [$r['uniform_name'],$r['gender'],$r['category'],$r['size'],$r['selling_price'],$r['available_stock'],$r['reorder_level']]);
    }
}

fclose($out);
exit;
