<?php

function pdf_escape_text(string $text): string
{
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_text(float $x, float $y, string $text, int $size = 10, string $font = 'F1'): string
{
    return "BT /{$font} {$size} Tf {$x} {$y} Td (" . pdf_escape_text($text) . ") Tj ET\n";
}

function pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return "{$x1} {$y1} m {$x2} {$y2} l S\n";
}

function pdf_rect(float $x, float $y, float $w, float $h): string
{
    return "{$x} {$y} {$w} {$h} re S\n";
}

function pdf_fill_rect(float $x, float $y, float $w, float $h, string $gray = '0.96'): string
{
    return "{$gray} rg {$x} {$y} {$w} {$h} re f\n";
}

function pdf_fit_text(string $text, int $maxChars): string
{
    $text = trim($text);
    if (strlen($text) <= $maxChars) {
        return $text;
    }
    return substr($text, 0, max(0, $maxChars - 3)) . '...';
}

function pdf_build_document(string $content, ?array $jpegImage = null): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];

    $contentObjectId = 6;
    $xObjects = '';
    if ($jpegImage) {
        $objects[6] = "<< /Type /XObject /Subtype /Image /Width {$jpegImage['width']} /Height {$jpegImage['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpegImage['data']) . " >>\nstream\n" . $jpegImage['data'] . "\nendstream";
        $contentObjectId = 7;
        $xObjects = '/XObject << /Im1 6 0 R >>';
    }

    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> {$xObjects} >> /Contents {$contentObjectId} 0 R >>";
    $objects[$contentObjectId] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";

    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) {
        if (isset($offsets[$i])) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        } else {
            $pdf .= "0000000000 65535 f \n";
        }
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

function academic_report_card_pdf(PDO $pdo, int $studentId, string $academicYear, string $term, ?string $examType = null): string
{
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        throw new InvalidArgumentException('Student not found.');
    }

    $summary = academic_student_marks_summary_for_exam($pdo, $studentId, $academicYear, $term, $examType);
    $result = academic_student_result_for_exam($pdo, $studentId, $academicYear, $term, $examType);
    $reportCard = get_academic_report_card($pdo, $studentId, $academicYear, $term) ?: [];
    $gradeExpectationLabel = academic_grade_expectation_label($result['grade'] ?? '');
    
    // Get exam name for header if filtering by exam type
    $examHeaderText = '';
    if ($examType && $examType !== 'all') {
        $examHeaderText = academic_exam_label($examType);
    }

    $content = "0.5 w\n0 0 0 RG\n0 0 0 rg\n";
    $content .= pdf_text(42, 820, SCHOOL_NAME, 16, 'F2');
    $content .= pdf_text(42, 802, SCHOOL_ADDRESS . ' | ' . SCHOOL_PHONE, 9);
    $content .= pdf_text(42, 786, 'Academic Report Card', 13, 'F2');
    if ($examHeaderText) {
        $content .= pdf_text(42, 772, $examHeaderText, 11, 'F2');
    }
    $content .= pdf_line(42, 780, 553, 780);

    $blockY = $examHeaderText ? 750 : 760;
    $content .= pdf_rect(42, $blockY - 80, 512, 72);
    $content .= pdf_text(50, $blockY - 18, 'Student: ' . $student['full_name'], 9, 'F2');
    $content .= pdf_text(50, $blockY - 32, 'Class: ' . $student['class_level'], 8);
    $content .= pdf_text(50, $blockY - 46, 'Term: ' . $term, 8);
    $content .= pdf_text(50, $blockY - 60, 'Admission No: ' . $student['registration_no'], 8);
    $content .= pdf_text(320, $blockY - 18, 'Academic Year: ' . $academicYear, 8);
    $content .= pdf_text(320, $blockY - 32, 'Generated: ' . date('Y-m-d'), 8);
    $content .= pdf_text(320, $blockY - 46, 'Average: ' . number_format((float) ($result['average'] ?? 0), 2) . '%', 8);
    $content .= pdf_text(320, $blockY - 60, 'Performance Level: ' . ($gradeExpectationLabel ?: 'N/A'), 8);

    $summaryY = $blockY - 96;
    $summaryBoxes = [
        ['Average (%)', number_format((float) ($result['average'] ?? 0), 2) . '%'],
        ['Performance Level', $gradeExpectationLabel ?: 'N/A'],
        ['Class Position', (string) ($result['class_position'] ?? 'N/A')],
    ];
    $boxWidth = 164;
    $boxX = 42;
    foreach ($summaryBoxes as $box) {
        $content .= pdf_rect($boxX, $summaryY - 42, $boxWidth, 42);
        $content .= pdf_text($boxX + 6, $summaryY - 18, $box[0], 8, 'F2');
        $content .= pdf_text($boxX + 6, $summaryY - 30, $box[1], 10);
        $boxX += $boxWidth + 5;
    }

    $tableY = $summaryY - 64;
    $rowH = 20;
    
    // Adjust table columns based on exam filter
    if ($examType && $examType !== 'all') {
        $columns = [
            ['Subject', 110],
            ['Teacher', 55],
            [$examHeaderText, 40],
            ['Total', 40],
            ['Average (%)', 35],
            ['Level', 55],
            ['Position', 40],
        ];
    } else {
        $columns = [
            ['Subject', 110],
            ['Teacher', 55],
            ['Opening', 40],
            ['Midterm', 40],
            ['Closing', 40],
            ['Total', 40],
            ['Average (%)', 35],
            ['Level', 55],
            ['Position', 40],
        ];
    }
    
    $tableW = array_sum(array_column($columns, 1));
    $content .= pdf_rect(42, $tableY, $tableW, $rowH);
    $cx = 42;
    foreach ($columns as $column) {
        $content .= pdf_text($cx + 3, $tableY + 6, $column[0], 8, 'F2');
        $content .= pdf_line($cx, $tableY, $cx, $tableY + $rowH);
        $cx += $column[1];
    }
    $content .= pdf_line(42 + $tableW, $tableY, 42 + $tableW, $tableY + $rowH);
    $y = $tableY - $rowH;

    foreach ($summary as $row) {
        if ($y < 160) {
            break;
        }
        $content .= pdf_rect(42, $y, $tableW, $rowH);
        
        if ($examType && $examType !== 'all') {
            $values = [
                pdf_fit_text($row['subject_name'], 18),
                pdf_fit_text($row['teacher_name'] ?? '-', 10),
                ($examType === 'Opening' && $row['opening'] !== null) ? number_format((float) $row['opening'], 1) : 
                (($examType === 'Midterm' && $row['midterm'] !== null) ? number_format((float) $row['midterm'], 1) : 
                (($examType === 'Closing' && $row['closing'] !== null) ? number_format((float) $row['closing'], 1) : '-')),
                number_format((float) $row['subject_total'], 1),
                number_format((float) $row['average'], 1) . '%',
                academic_grade_expectation_label($row['grade']),
                (string) ($row['subject_position'] ?? '-'),
            ];
        } else {
            $values = [
                pdf_fit_text($row['subject_name'], 18),
                pdf_fit_text($row['teacher_name'] ?? '-', 10),
                $row['opening'] !== null ? number_format((float) $row['opening'], 1) : '-',
                $row['midterm'] !== null ? number_format((float) $row['midterm'], 1) : '-',
                $row['closing'] !== null ? number_format((float) $row['closing'], 1) : '-',
                number_format((float) $row['subject_total'], 1),
                number_format((float) $row['average'], 1) . '%',
                academic_grade_expectation_label($row['grade']),
                (string) ($row['subject_position'] ?? '-'),
            ];
        }
        
        $cx = 42;
        foreach ($columns as $index => $column) {
            $content .= pdf_text($cx + 3, $y + 6, $values[$index], 8);
            $content .= pdf_line($cx, $y, $cx, $y + $rowH);
            $cx += $column[1];
        }
        $content .= pdf_line(42 + $tableW, $y, 42 + $tableW, $y + $rowH);
        $y -= $rowH;
    }

    $remarksY = $y - 30;
    $content .= pdf_text(42, $remarksY, 'Teacher Remarks', 10, 'F2');
    $content .= pdf_rect(42, $remarksY - 54, 245, 44);
    $content .= pdf_text(46, $remarksY - 36, pdf_fit_text((string) ($reportCard['teacher_comment'] ?? ''), 64), 8);

    $content .= pdf_text(310, $remarksY, 'Head Teacher Remarks', 10, 'F2');
    $content .= pdf_rect(310, $remarksY - 54, 245, 44);
    $content .= pdf_text(314, $remarksY - 36, pdf_fit_text((string) ($reportCard['head_teacher_comment'] ?? ''), 64), 8);

    $descY = $remarksY - 90;
    $content .= pdf_text(42, $descY, 'Performance Descriptors', 10, 'F2');
    $descY -= 18;
    $descX = 42;
    $descW = 512;
    $firstCol = 120;
    $secondCol = 240;
    $rowH = 18;
    $content .= pdf_rect($descX, $descY, $descW, $rowH);
    $content .= pdf_text($descX + 3, $descY + 5, 'Performance Level', 8, 'F2');
    $content .= pdf_text($descX + $firstCol + 3, $descY + 5, 'Points', 8, 'F2');
    $content .= pdf_text($descX + $secondCol + 3, $descY + 5, 'Score Range (%)', 8, 'F2');
    $content .= pdf_line($descX + $firstCol, $descY, $descX + $firstCol, $descY + $rowH);
    $content .= pdf_line($descX + $secondCol, $descY, $descX + $secondCol, $descY + $rowH);
    $descY -= $rowH;

    $descriptors = academic_grade_point_descriptors($pdo);
    $placeholderRows = max(0, 8 - count($descriptors));
    for ($i = 0; $i < $placeholderRows; $i++) {
        $descriptors[] = ['grade' => '', 'label' => '', 'points' => '', 'range' => ''];
    }

    foreach ($descriptors as $descriptor) {
        if ($descY < 90) {
            break;
        }
        $content .= pdf_rect($descX, $descY, $descW, $rowH);
        $content .= pdf_text($descX + 3, $descY + 5, pdf_fit_text($descriptor['label'], 18), 8);
        $content .= pdf_text($descX + $firstCol + 3, $descY + 5, pdf_fit_text((string) $descriptor['points'], 18), 8);
        $content .= pdf_text($descX + $secondCol + 3, $descY + 5, pdf_fit_text($descriptor['range'], 18), 8);
        $content .= pdf_line($descX + $firstCol, $descY, $descX + $firstCol, $descY + $rowH);
        $content .= pdf_line($descX + $secondCol, $descY, $descX + $secondCol, $descY + $rowH);
        $descY -= $rowH;
    }

    $content .= pdf_line(70, 98, 230, 98);
    $content .= pdf_text(96, 83, 'Class Teacher Signature', 8);
    $content .= pdf_line(350, 98, 510, 98);
    $content .= pdf_text(374, 83, 'Head Teacher Signature', 8);

    return pdf_build_document($content);
}
