<?php

function kitchen_export_ascii(string $value): string
{
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $converted === false ? $value : $converted;
    return preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? '';
}

function kitchen_export_pdf_text(float $x, float $y, string $text, float $size = 8, string $font = 'F1', string $color = '0 0 0'): string
{
    $text = kitchen_export_ascii($text);
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td ({$text}) Tj ET\n";
}

function kitchen_export_pdf_rect(float $x, float $y, float $width, float $height, string $fill, ?string $stroke = null): string
{
    $stream = "q {$fill} rg {$x} {$y} {$width} {$height} re f ";
    if ($stroke !== null) {
        $stream .= "{$stroke} RG 0.5 w {$x} {$y} {$width} {$height} re S ";
    }
    return $stream . "Q\n";
}

function kitchen_export_pdf_line(float $x1, float $y1, float $x2, float $y2, string $color = '0.85 0.89 0.93'): string
{
    return "q {$color} RG 0.5 w {$x1} {$y1} m {$x2} {$y2} l S Q\n";
}

function kitchen_export_pdf_document(array $pageStreams, ?array $image = null): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Count ' . count($pageStreams) . ' /Kids [',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $nextId = 6;
    $imageId = 0;
    if ($image && !empty($image['data']) && !empty($image['width']) && !empty($image['height'])) {
        $imageId = $nextId++;
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width " . (int) $image['width'] . " /Height " . (int) $image['height'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    }
    $pageRefs = [];
    foreach ($pageStreams as $stream) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $pageRefs[] = "{$pageId} 0 R";
        $imageResource = $imageId ? " /XObject << /Im1 {$imageId} 0 R >>" : '';
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>{$imageResource} >> /Contents {$contentId} 0 R >>";
        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    }
    $objects[2] .= implode(' ', $pageRefs) . '] >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= isset($offsets[$id])
            ? sprintf("%010d 00000 n \n", $offsets[$id])
            : "0000000000 65535 f \n";
    }
    return $pdf . "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
}

function kitchen_export_load_school_logo(): ?array
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    if (!is_file($logoPath) || !($dimensions = @getimagesize($logoPath)) || ($data = @file_get_contents($logoPath)) === false) {
        return null;
    }

    return [
        'data' => $data,
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

function kitchen_export_pdf_clip(string $value, int $width): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return mb_strimwidth($value, 0, $width, '...');
}

function kitchen_export_build_pdf(array $rows, array $totals, array $meta): string
{
    $pageRows = [];
    if (!$rows) {
        $pageRows[] = [];
    } else {
        $pageRows[] = array_slice($rows, 0, 16);
        for ($offset = 16, $count = count($rows); $offset < $count; $offset += 20) {
            $pageRows[] = array_slice($rows, $offset, 20);
        }
    }

    $showRecordedBy = (bool) ($meta['show_recorded_by'] ?? false);
    $columns = [
        ['Date', 64, 14], ['Item', 142, 34], ['Category', 100, 21], ['Quantity', 72, 17],
        ['Unit price', 78, 18], ['Total spent', 86, 20], ['Notes', 140, 33], ['Recorded by', 96, 22],
    ];
    if (!$showRecordedBy) {
        $columns[6] = ['Notes', 236, 59];
        array_pop($columns);
    }
    $logo = kitchen_export_load_school_logo();
    $pageStreams = [];
    foreach ($pageRows as $pageIndex => $records) {
        $firstPage = $pageIndex === 0;
        $stream = kitchen_export_pdf_rect(0, 0, 842, 595, '1 1 1');
        $headerBottom = $firstPage ? 493 : 500;
        $stream .= kitchen_export_pdf_rect(0, $headerBottom, 842, 595 - $headerBottom, '0.06 0.17 0.31');
        $stream .= kitchen_export_pdf_rect(0, $headerBottom, 7, 595 - $headerBottom, '0.05 0.55 0.80');
        if ($logo) {
            $badgeSize = 48.0;
            $logoInset = 4.0;
            $badgeX = 32.0;
            $badgeY = 542.0;
            $stream .= kitchen_export_pdf_rect($badgeX, $badgeY, $badgeSize, $badgeSize, '1 1 1', '0.80 0.87 0.93');
            $logoBox = $badgeSize - ($logoInset * 2);
            $logoScale = min($logoBox / $logo['width'], $logoBox / $logo['height']);
            $logoWidth = $logo['width'] * $logoScale;
            $logoHeight = $logo['height'] * $logoScale;
            $logoX = $badgeX + $logoInset + (($logoBox - $logoWidth) / 2);
            $logoY = $badgeY + $logoInset + (($logoBox - $logoHeight) / 2);
            $stream .= "q {$logoWidth} 0 0 {$logoHeight} {$logoX} {$logoY} cm /Im1 Do Q\n";
        }
        $schoolTextX = $logo ? 94 : 32;
        $stream .= kitchen_export_pdf_text($schoolTextX, 571, (string) ($meta['school'] ?? 'Arise To Excel Academy'), 14, 'F2', '1 1 1');
        $stream .= kitchen_export_pdf_text($schoolTextX, 554, 'Kitchen finance report | Generated ' . (string) ($meta['generated'] ?? ''), 7.5, 'F1', '0.78 0.86 0.94');
        $stream .= kitchen_export_pdf_text(32, 528, 'KITCHEN EXPENDITURE', 13, 'F2', '1 1 1');
        $filterLine = kitchen_export_pdf_clip((string) ($meta['filters'] ?? ''), 96);
        $stream .= kitchen_export_pdf_text(32, 510, $filterLine, 7.5, 'F1', '0.84 0.90 0.97');
        $stream .= kitchen_export_pdf_text(645, 562, 'TOTAL SPENT', 7.5, 'F2', '0.78 0.86 0.94');
        $stream .= kitchen_export_pdf_text(645, 539, 'KSh ' . number_format((float) ($totals['Total'] ?? 0), 2), 13, 'F2', '1 1 1');

        if ($firstPage) {
            $stream .= kitchen_export_pdf_text(32, 486, 'SPENDING SUMMARY', 7, 'F2', '0.33 0.43 0.55');
            $summary = [
                ['Food', $totals['Food'] ?? 0, '0.08 0.55 0.40'],
                ['Gas', $totals['Gas'] ?? 0, '0.05 0.52 0.73'],
                ['Other', $totals['Other'] ?? 0, '0.70 0.40 0.12'],
                ['Total', $totals['Total'] ?? 0, '0.28 0.37 0.62'],
            ];
            $x = 32;
            foreach ($summary as [$label, $amount, $tone]) {
                $stream .= kitchen_export_pdf_rect($x, 422, 188, 55, '0.96 0.98 1', '0.86 0.90 0.94');
                $stream .= kitchen_export_pdf_rect($x, 422, 3, 55, $tone);
                $stream .= kitchen_export_pdf_text($x + 12, 459, $label, 7.5, 'F2', '0.37 0.44 0.53');
                $stream .= kitchen_export_pdf_text($x + 12, 437, 'KSh ' . number_format((float) $amount, 2), 10, 'F2', '0.08 0.17 0.29');
                $x += 196;
            }
            $tableHeaderY = 394;
        } else {
            $tableHeaderY = 485;
        }

        $tableWidth = array_sum(array_column($columns, 1));
        $stream .= kitchen_export_pdf_rect(32, $tableHeaderY - 22, $tableWidth, 22, '0.08 0.27 0.48');
        $x = 32;
        foreach ($columns as [$heading, $width]) {
            $stream .= kitchen_export_pdf_text($x + 5, $tableHeaderY - 14.5, $heading, 7, 'F2', '1 1 1');
            $x += $width;
        }

        $rowTop = $tableHeaderY - 22;
        if (!$records) {
            $stream .= kitchen_export_pdf_rect(32, $rowTop - 25, $tableWidth, 25, '0.98 0.99 1', '0.87 0.90 0.94');
            $stream .= kitchen_export_pdf_text(42, $rowTop - 16, 'No kitchen expenditure records match these filters.', 8, 'F1', '0.34 0.42 0.51');
        } else {
            foreach ($records as $rowIndex => $record) {
                $rowBottom = $rowTop - 20;
                $fill = $rowIndex % 2 === 0 ? '1 1 1' : '0.97 0.98 1';
                $stream .= kitchen_export_pdf_rect(32, $rowBottom, $tableWidth, 20, $fill, '0.88 0.91 0.95');
                $quantity = ($record['quantity'] === null || $record['quantity'] === '')
                    ? '-'
                    : number_format((float) $record['quantity'], 2) . (($record['unit'] ?? '') !== '' ? ' ' . $record['unit'] : '');
                $unitPrice = $record['unit_price'] === null ? '-' : 'KSh ' . number_format((float) $record['unit_price'], 2);
                $category = trim((string) ($record['kitchen_category'] ?? '')) ?: (string) ($record['report_category'] ?? 'Other');
                $values = [
                    (string) ($record['expense_date'] ?? ''), (string) ($record['item_name'] ?? ''),
                    $category, $quantity, $unitPrice,
                    'KSh ' . number_format((float) ($record['total_amount'] ?? 0), 2),
                    (string) ($record['description'] ?? ''),
                ];
                if ($showRecordedBy) {
                    $values[] = (string) ($record['recorded_by'] ?? 'Not recorded');
                }
                $x = 32;
                foreach ($values as $columnIndex => $value) {
                    $stream .= kitchen_export_pdf_text($x + 5, $rowBottom + 7, kitchen_export_pdf_clip($value, $columns[$columnIndex][2]), 7, 'F1', '0.13 0.20 0.29');
                    $x += $columns[$columnIndex][1];
                }
                $rowTop = $rowBottom;
            }
        }

        $stream .= kitchen_export_pdf_line(32, 35, 810, 35);
        $stream .= kitchen_export_pdf_text(32, 22, kitchen_export_ascii((string) ($meta['school'] ?? 'Arise To Excel Academy')) . ' | Kitchen expenditure', 7, 'F1', '0.42 0.49 0.57');
        $stream .= kitchen_export_pdf_text(748, 22, 'Page ' . ($pageIndex + 1) . ' of ' . count($pageRows), 7, 'F1', '0.42 0.49 0.57');
        $pageStreams[] = $stream;
    }

    return kitchen_export_pdf_document($pageStreams, $logo);
}

function kitchen_export_xml(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function kitchen_export_spreadsheet_xml(array $rows, array $totals, array $meta): string
{
    $showRecordedBy = (bool) ($meta['show_recorded_by'] ?? false);
    $headers = ['Date', 'Item', 'Category', 'Quantity', 'Unit price', 'Total spent', 'Notes'];
    $columnWidths = [95, 190, 90, 95, 115, 115, $showRecordedBy ? 240 : 390];
    if ($showRecordedBy) {
        $headers[] = 'Recorded by';
        $columnWidths[] = 150;
    }
    $columnCount = count($headers);
    $mergeLastColumn = $columnCount - 1;
    $mergeMetaColumns = $columnCount - 2;
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    $xml .= '<Styles><Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Aptos" ss:Size="10" ss:Color="#20344B"/></Style>';
    $xml .= '<Style ss:ID="Title"><Font ss:FontName="Aptos Display" ss:Size="18" ss:Bold="1" ss:Color="#0E3154"/><Alignment ss:Vertical="Center"/></Style>';
    $xml .= '<Style ss:ID="Meta"><Font ss:FontName="Aptos" ss:Size="10" ss:Color="#52677C"/></Style>';
    $xml .= '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#164A78" ss:Pattern="Solid"/><Alignment ss:Vertical="Center"/></Style>';
    $xml .= '<Style ss:ID="Currency"><NumberFormat ss:Format="&quot;KSh &quot;#,##0.00"/><Alignment ss:Horizontal="Right"/></Style>';
    $xml .= '<Style ss:ID="Total"><Font ss:Bold="1" ss:Color="#0E3154"/><Interior ss:Color="#EAF3FA" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;KSh &quot;#,##0.00"/></Style>';
    $xml .= '<Style ss:ID="Alternate"><Interior ss:Color="#F2F7FB" ss:Pattern="Solid"/></Style></Styles>';
    $xml .= '<Worksheet ss:Name="Kitchen Expenditure"><Table>';
    foreach ($columnWidths as $width) {
        $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . $width . '"/>';
    }
    $appendRow = static function (array $cells, ?string $rowStyle = null) use (&$xml): void {
        $xml .= '<Row>';
        foreach ($cells as $cell) {
            [$value, $type, $style, $mergeAcross] = array_pad($cell, 4, null);
            $styleAttribute = $style ? ' ss:StyleID="' . $style . '"' : ($rowStyle ? ' ss:StyleID="' . $rowStyle . '"' : '');
            $mergeAttribute = $mergeAcross !== null ? ' ss:MergeAcross="' . (int) $mergeAcross . '"' : '';
            $xml .= '<Cell' . $styleAttribute . $mergeAttribute . '><Data ss:Type="' . ($type === 'Number' ? 'Number' : 'String') . '">';
            $xml .= $type === 'Number' ? (string) (float) $value : kitchen_export_xml((string) $value);
            $xml .= '</Data></Cell>';
        }
        $xml .= '</Row>';
    };

    $appendRow([['KITCHEN EXPENDITURE', 'String', 'Title', $mergeLastColumn]]);
    $appendRow([[(string) ($meta['school'] ?? 'Arise To Excel Academy'), 'String', 'Meta', $mergeLastColumn]]);
    $appendRow([['Generated', 'String', 'Meta'], [(string) ($meta['generated'] ?? ''), 'String', 'Meta', $mergeMetaColumns]]);
    $appendRow([['Filters', 'String', 'Meta'], [(string) ($meta['filters'] ?? ''), 'String', 'Meta', $mergeMetaColumns]]);
    $appendRow([['Period total', 'String', 'Meta'], [(float) ($totals['Total'] ?? 0), 'Number', 'Total', $mergeMetaColumns]]);
    $appendRow([]);
    $appendRow([['Category', 'String', 'Header'], ['Expenditure', 'String', 'Header']]);
    foreach (['Food', 'Gas', 'Other', 'Total'] as $category) {
        $appendRow([[$category, 'String', $category === 'Total' ? 'Total' : null], [(float) ($totals[$category] ?? 0), 'Number', $category === 'Total' ? 'Total' : 'Currency']]);
    }
    $appendRow([]);
    $appendRow(array_map(static fn(string $heading): array => [$heading, 'String', 'Header'], $headers));

    foreach ($rows as $index => $record) {
        $quantity = ($record['quantity'] === null || $record['quantity'] === '')
            ? ''
            : number_format((float) $record['quantity'], 2) . (($record['unit'] ?? '') !== '' ? ' ' . $record['unit'] : '');
        $unitPrice = $record['unit_price'] === null ? '' : (float) $record['unit_price'];
        $category = trim((string) ($record['kitchen_category'] ?? '')) ?: (string) ($record['report_category'] ?? 'Other');
        $cells = [
            [(string) ($record['expense_date'] ?? ''), 'String'],
            [(string) ($record['item_name'] ?? ''), 'String'],
            [$category, 'String'],
            [$quantity, 'String'],
            [$unitPrice, 'Number', 'Currency'],
            [(float) ($record['total_amount'] ?? 0), 'Number', 'Currency'],
            [(string) ($record['description'] ?? ''), 'String'],
        ];
        if ($showRecordedBy) {
            $cells[] = [(string) ($record['recorded_by'] ?? 'Not recorded'), 'String'];
        }
        $appendRow($cells, $index % 2 === 1 ? 'Alternate' : null);
    }
    $xml .= '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>11</SplitHorizontal><TopRowBottomPane>11</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions></Worksheet></Workbook>';
    return $xml;
}
