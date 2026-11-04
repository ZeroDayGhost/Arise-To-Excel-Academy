<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen_expenditure.php');

$report = $_GET['report'] ?? 'low_stock';
$format = $_GET['format'] ?? 'pdf';

function kitchen_report_pdf_escape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function kitchen_report_pdf_text(float $x, float $y, string $text, float $size = 9, string $font = 'F1', string $color = '0 0 0'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . kitchen_report_pdf_escape($text) . ") Tj ET\n";
}

function kitchen_report_pdf_rect(float $x, float $y, float $w, float $h, string $fill, string $stroke = ''): string
{
    $command = "{$fill} rg {$x} {$y} {$w} {$h} re f\n";
    if ($stroke !== '') {
        $command .= "{$stroke} RG {$x} {$y} {$w} {$h} re S\n";
    }
    return $command;
}

function kitchen_report_pdf_document(array $pageStreams): string
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

function build_kitchen_lowstock_pdf(array $rows): string
{
    $title = 'Kitchen Low / Out of Stock Items';
    $headers = ['Item', 'Unit', 'Remaining', 'Buy?', 'Status', 'Stock Value'];
    $tableRows = [];
    foreach ($rows as $r) {
        $status = ($r['remaining_stock'] <= 0) ? 'Out of Stock' : 'Low Stock';
        $tableRows[] = [
            $r['item_name'],
            $r['unit'] ?? '',
            (string) $r['remaining_stock'],
            '',
            $status,
            'KES ' . number_format((float) ($r['stock_value'] ?? 0), 2),
        ];
    }

    $pages = array_chunk($tableRows, 20);
    if (!$pages) $pages = [[]];
    $pageStreams = [];

    foreach ($pages as $pageIndex => $pageRows) {
        $stream = '';
        $stream .= kitchen_report_pdf_rect(0, 0, 842, 595, '1 1 1');
        $stream .= kitchen_report_pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
        $stream .= kitchen_report_pdf_text(30, 558, defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School', 15, 'F2', '0.06 0.09 0.16');
        $stream .= kitchen_report_pdf_text(30, 540, $title, 12, 'F2', '0.10 0.22 0.57');
        $stream .= kitchen_report_pdf_text(30, 524, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.30 0.36 0.45');
        $stream .= kitchen_report_pdf_text(30, 510, 'Report: Low stock items', 8, 'F1', '0.30 0.36 0.45');
        $stream .= kitchen_report_pdf_text(30, 496, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 7, 'F1', '0.30 0.36 0.45');

        $y = 460;
        $stream .= kitchen_report_pdf_rect(30, $y, 760, 20, '0.11 0.30 0.85');
        $columnWidth = intdiv(760, max(1, count($headers)));
        $x = 30;
        foreach ($headers as $header) {
            $stream .= kitchen_report_pdf_text($x + 3, $y + 6.5, $header, 7, 'F2', '1 1 1');
            $x += $columnWidth;
        }

        $y -= 24;
        if (!$pageRows) {
            $stream .= kitchen_report_pdf_text(30, $y, 'No records found.', 9, 'F1', '0.06 0.09 0.16');
        } else {
            foreach ($pageRows as $rowIndex => $values) {
                $fill = $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1';
                $stream .= kitchen_report_pdf_rect(30, $y, 760, 18, $fill, '0.88 0.91 0.95');
                $x = 30;
                foreach ($values as $colIndex => $value) {
                    // If this is the Buy? column, draw an empty checkbox box for printing
                    if ($colIndex === 3) {
                        $boxSize = 10;
                        $boxX = $x + intdiv($columnWidth, 2) - intdiv($boxSize, 2);
                        $boxY = $y + 4; // vertically center in the 18px row
                        $stream .= kitchen_report_pdf_rect($boxX, $boxY, $boxSize, $boxSize, '1 1 1', '0 0 0');
                    } else {
                        $text = mb_strimwidth((string) $value, 0, 48, '');
                        $stream .= kitchen_report_pdf_text($x + 3, $y + 5, $text, 6.8, 'F1', '0.06 0.09 0.16');
                    }
                    $x += $columnWidth;
                }
                $y -= 18;
            }
        }

        $stream .= kitchen_report_pdf_text(30, 22, defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School', 7, 'F1', '0.39 0.45 0.55');
        $pageStreams[] = $stream;
    }

    return kitchen_report_pdf_document($pageStreams);
}

if ($format === 'pdf') {
    if ($report === 'low_stock') {
        $rows = low_stock_kitchen_items($pdo);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="kitchen_low_stock_' . date('Y-m-d') . '.pdf"');
        echo build_kitchen_lowstock_pdf($rows);
        exit;
    }
    if ($report === 'weekly_shopping') {
        // If a specific weekly shopping id provided, export that batch; otherwise export all recent weekly shopping items
        $wsId = isset($_GET['ws_id']) ? (int) $_GET['ws_id'] : 0;
        if ($wsId > 0) {
            $stmt = $pdo->prepare("SELECT w.shopping_date, w.supplier, w.total_amount AS batch_total, i.* FROM weekly_shopping_items i JOIN weekly_shopping w ON w.id = i.weekly_shopping_id WHERE w.id = :id ORDER BY i.id ASC");
            $stmt->execute(['id' => $wsId]);
            $rows = $stmt->fetchAll();
        } else {
            $stmt = $pdo->query("SELECT w.shopping_date, w.supplier, w.total_amount AS batch_total, i.* FROM weekly_shopping_items i JOIN weekly_shopping w ON w.id = i.weekly_shopping_id ORDER BY w.shopping_date DESC, w.id DESC, i.id ASC");
            $rows = $stmt->fetchAll();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="weekly_shopping_' . date('Y-m-d') . '.pdf"');
        echo build_kitchen_weekly_shopping_pdf($rows, $wsId > 0 ? $wsId : null);
        exit;
    }
}

function build_kitchen_weekly_shopping_pdf(array $rows, ?int $wsId = null): string
{
    $title = 'Weekly Shopping List';
    $headers = ['Date', 'Supplier', 'Item', 'Unit', 'Qty', 'Unit Price', 'Total'];
    $tableRows = [];
    foreach ($rows as $r) {
        $tableRows[] = [
            $r['shopping_date'] ?? '',
            $r['supplier'] ?? '',
            $r['item_name'] ?? '',
            $r['unit'] ?? '',
            (string) ($r['quantity'] ?? ''),
            'KES ' . number_format((float) ($r['unit_price'] ?? 0), 2),
            'KES ' . number_format((float) ($r['total_amount'] ?? 0), 2),
        ];
    }

    $pages = array_chunk($tableRows, 20);
    if (!$pages) $pages = [[]];
    $pageStreams = [];
    foreach ($pages as $pageIndex => $pageRows) {
        $stream = '';
        $stream .= kitchen_report_pdf_rect(0, 0, 842, 595, '1 1 1');
        $stream .= kitchen_report_pdf_rect(0, 520, 842, 75, '0.96 0.98 1');
        $stream .= kitchen_report_pdf_text(30, 558, defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School', 15, 'F2', '0.06 0.09 0.16');
        $stream .= kitchen_report_pdf_text(30, 540, $title, 12, 'F2', '0.10 0.22 0.57');
        $stream .= kitchen_report_pdf_text(30, 524, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.30 0.36 0.45');
        $stream .= kitchen_report_pdf_text(30, 510, 'Report: Weekly shopping' . ($wsId ? ' (Batch #' . $wsId . ')' : ''), 8, 'F1', '0.30 0.36 0.45');
        $stream .= kitchen_report_pdf_text(30, 496, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 7, 'F1', '0.30 0.36 0.45');

        $y = 460;
        $stream .= kitchen_report_pdf_rect(30, $y, 760, 20, '0.11 0.30 0.85');
        $columnWidth = intdiv(760, max(1, count($headers)));
        $x = 30;
        foreach ($headers as $header) {
            $stream .= kitchen_report_pdf_text($x + 3, $y + 6.5, $header, 7, 'F2', '1 1 1');
            $x += $columnWidth;
        }

        $y -= 24;
        if (!$pageRows) {
            $stream .= kitchen_report_pdf_text(30, $y, 'No records found.', 9, 'F1', '0.06 0.09 0.16');
        } else {
            foreach ($pageRows as $rowIndex => $values) {
                $fill = $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1';
                $stream .= kitchen_report_pdf_rect(30, $y, 760, 18, $fill, '0.88 0.91 0.95');
                $x = 30;
                foreach ($values as $value) {
                    $text = mb_strimwidth((string) $value, 0, 48, '');
                    $stream .= kitchen_report_pdf_text($x + 3, $y + 5, $text, 6.8, 'F1', '0.06 0.09 0.16');
                    $x += $columnWidth;
                }
                $y -= 18;
            }
        }

        $stream .= kitchen_report_pdf_text(30, 22, defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School', 7, 'F1', '0.39 0.45 0.55');
        $pageStreams[] = $stream;
    }

    return kitchen_report_pdf_document($pageStreams);
}

if ($format === 'print') {
    require_once __DIR__ . '/../includes/admin_header.php';
    echo '<div class="page-title"><div><p class="eyebrow">Export</p><h1>Kitchen Low Stock</h1></div><div class="action-row"><button class="btn btn-primary" onclick="window.print()">Print</button><a class="btn btn-outline-primary" href="' . url('admin/kitchen_inventory.php') . '">Back</a></div></div>';
    echo '<section class="panel"><div class="mt-4">';
    $rows = low_stock_kitchen_items($pdo);
    echo '<div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Unit</th><th>Remaining</th><th>Min Level</th><th>Status</th><th>Stock Value</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $status = ($r['remaining_stock'] <= 0) ? 'Out of Stock' : 'Low Stock';
        echo '<tr><td>' . h($r['item_name']) . '</td><td>' . h($r['unit'] ?? '') . '</td><td>' . h((string)$r['remaining_stock']) . '</td><td>' . h((string)$r['min_stock_level']) . '</td><td>' . h($status) . '</td><td>' . money((float)($r['stock_value'] ?? 0)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</div></section>';
    require_once __DIR__ . '/../includes/layout_end.php';
    exit;
}

// fallback: CSV
$filename = 'kitchen_low_stock.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output','wb');
fputcsv($out, ['Item','Unit','Remaining','Min Level','Status','Stock Value']);
$rows = low_stock_kitchen_items($pdo);
foreach ($rows as $r) {
    $status = ($r['remaining_stock'] <= 0) ? 'Out of Stock' : 'Low Stock';
    fputcsv($out, [$r['item_name'], $r['unit'] ?? '', $r['remaining_stock'], $r['min_stock_level'], $status, $r['stock_value'] ?? 0]);
}
fclose($out);
exit;
