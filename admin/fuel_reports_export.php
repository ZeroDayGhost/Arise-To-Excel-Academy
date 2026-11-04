<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$report = $_GET['report'] ?? 'monthly';
$format = $_GET['format'] ?? 'csv';

function fuel_report_pdf_escape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function fuel_report_pdf_text(float $x, float $y, string $text, float $size = 9, string $font = 'F1', string $color = '0 0 0'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . fuel_report_pdf_escape($text) . ") Tj ET\n";
}

function fuel_report_pdf_rect(float $x, float $y, float $w, float $h, string $fill, string $stroke = ''): string
{
    $command = "{$fill} rg {$x} {$y} {$w} {$h} re f\n";
    if ($stroke !== '') {
        $command .= "{$stroke} RG {$x} {$y} {$w} {$h} re S\n";
    }
    return $command;
}

function fuel_report_pdf_document(array $pageStreams): string
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

function build_fuel_report_pdf(array $rows, string $report): string
{
    $title = 'Fuel Report';
    $headers = [];
    $tableRows = [];

    if ($report === 'by_vehicle') {
        $title = 'Fuel Cost by Vehicle';
        $headers = ['Vehicle', 'Total Spent'];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['vehicle_name'],
                'KES ' . number_format((float) $row['total_spent'], 2),
            ];
        }
    } elseif ($report === 'daily') {
        $title = 'Daily Fuel Cost';
        $headers = ['Date', 'Total Spent'];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['fuel_date'],
                'KES ' . number_format((float) $row['total_spent'], 2),
            ];
        }
    } else {
        $title = 'Monthly Fuel Cost';
        $headers = ['Month', 'Total Spent'];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['ym'],
                'KES ' . number_format((float) $row['total_spent'], 2),
            ];
        }
    }

    $pages = array_chunk($tableRows, 20);
    if (!$pages) {
        $pages = [[]];
    }

    $pageStreams = [];
    foreach ($pages as $pageIndex => $pageRows) {
        $stream = '';
        $stream .= fuel_report_pdf_rect(0, 0, 842, 595, '1 1 1');
        $stream .= fuel_report_pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
        $stream .= fuel_report_pdf_text(30, 558, SCHOOL_NAME, 15, 'F2', '0.06 0.09 0.16');
        $stream .= fuel_report_pdf_text(30, 540, $title, 12, 'F2', '0.10 0.22 0.57');
        $stream .= fuel_report_pdf_text(30, 524, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.30 0.36 0.45');
        $stream .= fuel_report_pdf_text(30, 510, 'Report: ' . ucfirst($report), 8, 'F1', '0.30 0.36 0.45');
        $stream .= fuel_report_pdf_text(30, 496, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 7, 'F1', '0.30 0.36 0.45');

        $y = 460;
        $stream .= fuel_report_pdf_rect(30, $y, 760, 20, '0.11 0.30 0.85');
        $columnWidth = intdiv(760, max(1, count($headers)));
        $x = 30;
        foreach ($headers as $header) {
            $stream .= fuel_report_pdf_text($x + 3, $y + 6.5, $header, 7, 'F2', '1 1 1');
            $x += $columnWidth;
        }

        $y -= 24;
        if (!$pageRows) {
            $stream .= fuel_report_pdf_text(30, $y, 'No records found.', 9, 'F1', '0.06 0.09 0.16');
        } else {
            foreach ($pageRows as $rowIndex => $values) {
                $fill = $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1';
                $stream .= fuel_report_pdf_rect(30, $y, 760, 18, $fill, '0.88 0.91 0.95');
                $x = 30;
                foreach ($values as $value) {
                    $text = mb_strimwidth((string) $value, 0, 32, '');
                    $stream .= fuel_report_pdf_text($x + 3, $y + 5, $text, 6.8, 'F1', '0.06 0.09 0.16');
                    $x += $columnWidth;
                }
                $y -= 18;
            }
        }

        $stream .= fuel_report_pdf_text(30, 22, SCHOOL_NAME, 7, 'F1', '0.39 0.45 0.55');
        $pageStreams[] = $stream;
    }

    return fuel_report_pdf_document($pageStreams);
}

if ($format === 'pdf') {
    if ($report === 'by_vehicle') {
        $rows = $pdo->query("SELECT v.vehicle_name, SUM(ft.total_amount) AS total_spent FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id GROUP BY ft.vehicle_id ORDER BY total_spent DESC")->fetchAll();
    } elseif ($report === 'daily') {
        $rows = $pdo->query("SELECT fuel_date, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY fuel_date ORDER BY fuel_date DESC LIMIT 31")->fetchAll();
    } else {
        $rows = $pdo->query("SELECT DATE_FORMAT(fuel_date,'%Y-%m') AS ym, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY ym ORDER BY ym DESC LIMIT 24")->fetchAll();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="fuel_report_' . $report . '_' . date('Y-m-d') . '.pdf"');
    echo build_fuel_report_pdf($rows, $report);
    exit;
}

if ($format === 'print') {
    require_once __DIR__ . '/../includes/admin_header.php';
    echo '<div class="page-title"><div><p class="eyebrow">Export</p><h1>Fuel Report - ' . h(ucfirst($report)) . '</h1></div><div class="action-row"><button class="btn btn-primary" onclick="window.print()">Print</button><a class="btn btn-outline-primary" href="' . url('admin/fuel.php') . '">Back</a></div></div>';
    echo '<section class="panel"><div class="mt-4">';
    if ($report === 'by_vehicle') {
        $rows = $pdo->query("SELECT v.vehicle_name, SUM(ft.total_amount) AS total_spent FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id GROUP BY ft.vehicle_id ORDER BY total_spent DESC")->fetchAll();
        echo '<h3>Fuel Cost by Vehicle</h3><div class="table-responsive"><table class="table"><thead><tr><th>Vehicle</th><th>Total Spent</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . h($r['vehicle_name']) . '</td><td>' . money((float)$r['total_spent']) . '</td></tr>';
        echo '</tbody></table></div>';
    } elseif ($report === 'daily') {
        $rows = $pdo->query("SELECT fuel_date, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY fuel_date ORDER BY fuel_date DESC LIMIT 31")->fetchAll();
        echo '<h3>Daily Fuel Cost (last 31 days)</h3><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Total Spent</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . h($r['fuel_date']) . '</td><td>' . money((float)$r['total_spent']) . '</td></tr>';
        echo '</tbody></table></div>';
    } else {
        $rows = $pdo->query("SELECT DATE_FORMAT(fuel_date, '%Y-%m') AS ym, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY ym ORDER BY ym DESC LIMIT 24")->fetchAll();
        echo '<h3>Monthly Fuel Cost</h3><div class="table-responsive"><table class="table"><thead><tr><th>Month</th><th>Total Spent</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . h($r['ym']) . '</td><td>' . money((float)$r['total_spent']) . '</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</div></section>';
    require_once __DIR__ . '/../includes/layout_end.php';
    exit;
}

$outFormat = $format === 'xlsx' ? 'xlsx' : 'csv';
$filename = 'fuel_report_' . $report . '.' . ($outFormat === 'xlsx' ? 'xlsx' : 'csv');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output','wb');

if ($report === 'by_vehicle') {
    fputcsv($out, ['Vehicle','Total Spent']);
    $rows = $pdo->query("SELECT v.vehicle_name, SUM(ft.total_amount) AS total_spent FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id GROUP BY ft.vehicle_id ORDER BY total_spent DESC")->fetchAll();
    foreach ($rows as $r) fputcsv($out, [$r['vehicle_name'],$r['total_spent']]);
} elseif ($report === 'daily') {
    fputcsv($out, ['Date','Total Spent']);
    $rows = $pdo->query("SELECT fuel_date, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY fuel_date ORDER BY fuel_date DESC LIMIT 31")->fetchAll();
    foreach ($rows as $r) fputcsv($out, [$r['fuel_date'],$r['total_spent']]);
} else {
    fputcsv($out, ['Month','Total Spent']);
    $rows = $pdo->query("SELECT DATE_FORMAT(fuel_date,'%Y-%m') AS ym, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY ym ORDER BY ym DESC LIMIT 24")->fetchAll();
    foreach ($rows as $r) fputcsv($out, [$r['ym'],$r['total_spent']]);
}

fclose($out);
exit;
