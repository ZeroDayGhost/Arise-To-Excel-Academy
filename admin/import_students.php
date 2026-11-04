<?php
$pageTitle = 'Import Students';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/xlsx_reader.php';
require_once __DIR__ . '/../includes/parent_registration_helpers.php';
require_once __DIR__ . '/../includes/registration_number_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

// Permission: import students
if (!current_admin_has_permission($pdo, 'students.import')) {
    flash('error', 'You do not have permission to import students.');
    redirect('admin/students.php');
}

if (!parent_registration_schema_ready($pdo)) {
    flash('error', 'Apply database/migrations/20261018_parent_registration.sql before importing students.');
    redirect('admin/students.php');
}

$errors = [];
$classLevels = class_level_options();
$genderOptions = gender_options();
$currentContext = current_academic_context($pdo);
$academicYear = $currentContext['academic_year'];  
$actorId = (int) ($_SESSION['admin_id'] ?? 0);
$confirmRepeatedGuardians = ($_POST['confirm_repeated_guardians'] ?? '') === '1';

function normalize_import_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    $header = trim((string) $header, '_');

    $aliases = [
        'registration_no' => 'regno',
        'registration_number' => 'regno',
        'reg_no' => 'regno',
        'student' => 'student_name',
        'name' => 'student_name',
        'full_name' => 'student_name',
        'parent' => 'parent_name',
        'guardian_name' => 'parent_name',
        'guardian_phone' => 'parent_phone',
        'phone' => 'parent_phone',
        'alternate_phone' => 'guardian_alternate_phone',
        'alternative_phone' => 'guardian_alternate_phone',
        'parent_alternate_phone' => 'guardian_alternate_phone',
        'parent_alternative_phone' => 'guardian_alternate_phone',
        'alt_phone' => 'guardian_alternate_phone',
    ];

    return $aliases[$header] ?? $header;
}

function normalize_import_gender(string $gender): string
{
    $gender = strtolower(trim($gender));

    if (in_array($gender, ['m', 'male'], true)) {
        return 'Male';
    }
    if (in_array($gender, ['f', 'female'], true)) {
        return 'Female';
    }

    return '';
}

function import_row_is_blank(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}

function xlsx_cell(string $reference, string $value): string
{
    return '<c r="' . h($reference) . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
}

function send_student_import_template(): void
{
    $headers = ['student_name', 'gender', 'parent_name', 'class_level', 'parent_phone', 'guardian_alternate_phone'];
    $sample = ['Jane Wanjiku', 'Female', 'Mary Wanjiku', 'Grade 1', '0712345678', '0722345678'];
    $sheetRows = [];
    foreach ([$headers, $sample] as $rowIndex => $row) {
        $cells = [];
        foreach ($row as $columnIndex => $value) {
            $cells[] = xlsx_cell(chr(65 + $columnIndex) . ($rowIndex + 1), $value);
        }
        $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>',
    ];

    $temporaryFile = tempnam(sys_get_temp_dir(), 'student_template_');
    if ($temporaryFile === false) {
        http_response_code(500);
        echo 'Unable to create the Excel template.';
        exit;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
            unlink($temporaryFile);
            http_response_code(500);
            echo 'Unable to create the Excel template.';
            exit;
        }
        foreach ($entries as $name => $contents) {
            if (!$zip->addFromString($name, $contents)) {
                $zip->close();
                unlink($temporaryFile);
                http_response_code(500);
                echo 'Unable to create the Excel template.';
                exit;
            }
        }
        $zip->close();
    } elseif (file_put_contents($temporaryFile, xlsx_create_stored_zip($entries), LOCK_EX) === false) {
        unlink($temporaryFile);
        http_response_code(500);
        echo 'Unable to create the Excel template.';
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="student_import_template.xlsx"');
    header('Content-Length: ' . filesize($temporaryFile));
    readfile($temporaryFile);
    unlink($temporaryFile);
    exit;
}

if (isset($_GET['download_template'])) {
    send_student_import_template();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['students_file']) || $_FILES['students_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a valid .xlsx file to import.';
    } else {
        $file = $_FILES['students_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'xlsx') {
            $errors[] = 'Only .xlsx files are accepted.';
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The uploaded file must be between 1 byte and 5 MB.';
        }
    }

    $records = [];

    if (!$errors) {
        try {
            $rows = xlsx_read_rows($file['tmp_name']);
            $headerRowIndex = null;
            $headers = [];

            foreach ($rows as $index => $row) {
                if (!import_row_is_blank($row)) {
                    $headerRowIndex = $index;
                    foreach ($row as $columnIndex => $header) {
                        $normalizedHeader = normalize_import_header((string) $header);
                        if ($normalizedHeader !== '') {
                            $headers[$normalizedHeader] = $columnIndex;
                        }
                    }
                    break;
                }
            }

            $requiredHeaders = ['student_name', 'gender', 'parent_name', 'class_level', 'parent_phone'];
            foreach ($requiredHeaders as $requiredHeader) {
                if (!array_key_exists($requiredHeader, $headers)) {
                    $errors[] = "Missing Excel column: {$requiredHeader}.";
                }
            }

            if ($headerRowIndex === null) {
                $errors[] = 'The uploaded Excel file does not contain student rows.';
            }

            $seenStudentIdentities = [];
            $phoneGroups = [];

            if (!$errors) {
                foreach (array_slice($rows, $headerRowIndex + 1, null, true) as $rowIndex => $row) {
                    if (import_row_is_blank($row)) {
                        continue;
                    }

                    $rowNumber = $rowIndex + 1;
                    $studentName = trim((string) ($row[$headers['student_name']] ?? ''));
                    $gender = normalize_import_gender((string) ($row[$headers['gender']] ?? ''));
                    $parentName = trim((string) ($row[$headers['parent_name']] ?? ''));
                    $classLevel = trim((string) ($row[$headers['class_level']] ?? ''));
                    $parentPhone = trim((string) ($row[$headers['parent_phone']] ?? ''));
                    $alternatePhoneColumn = $headers['guardian_alternate_phone'] ?? null;
                    $alternatePhone = trim((string) ($alternatePhoneColumn !== null ? ($row[$alternatePhoneColumn] ?? '') : ''));

                    if ($studentName === '') {
                        $errors[] = "Row {$rowNumber}: student_name is required.";
                    }
                    if (!in_array($gender, $genderOptions, true)) {
                        $errors[] = "Row {$rowNumber}: gender must be Male or Female.";
                    }
                    if ($parentName === '') {
                        $errors[] = "Row {$rowNumber}: parent_name is required.";
                    }
                    if (!in_array($classLevel, $classLevels, true)) {
                        $errors[] = "Row {$rowNumber}: class_level is not valid.";
                    }
                    if ($parentPhone === '') {
                        $errors[] = "Row {$rowNumber}: parent_phone is required.";
                    }
                    if (strlen($parentPhone) > 40) {
                        $errors[] = "Row {$rowNumber}: parent_phone is too long.";
                    }
                    if ($alternatePhone !== '' && strlen($alternatePhone) > 40) {
                        $errors[] = "Row {$rowNumber}: guardian_alternate_phone is too long.";
                    } elseif ($alternatePhone !== '' && parent_registration_normalize_phone($alternatePhone) === null) {
                        $errors[] = "Row {$rowNumber}: guardian_alternate_phone must be a valid phone number.";
                    }
                    $normalizedPhone = parent_registration_normalize_phone($parentPhone);
                    if ($normalizedPhone === null) {
                        $errors[] = "Row {$rowNumber}: parent_phone must be a valid phone number.";
                    } else {
                        $identityKey = hash('sha256', parent_registration_normalize_name($studentName) . '|' . $normalizedPhone);
                        if (isset($seenStudentIdentities[$identityKey])) {
                            $errors[] = "Row {$rowNumber}: this student name and parent phone are already repeated in the file.";
                        }
                        $seenStudentIdentities[$identityKey] = true;
                        $existingStudent = find_existing_student_identity($pdo, $studentName, $parentPhone);
                        if ($existingStudent) {
                            $errors[] = "Row {$rowNumber}: {$existingStudent['full_name']} already exists as {$existingStudent['registration_no']} ({$existingStudent['enrollment_status']}). Reactivate that record instead of importing a new student.";
                        }
                        $parentNameKey = parent_registration_normalize_name($parentName);
                        if (isset($phoneGroups[$normalizedPhone]) && $phoneGroups[$normalizedPhone]['name'] !== $parentNameKey) {
                            $errors[] = "Row {$rowNumber}: this phone appears with a different parent name elsewhere in the file. Import those records separately and verify the parent account.";
                        }
                        $phoneGroups[$normalizedPhone] = [
                            'name' => $parentNameKey,
                            'count' => (int) ($phoneGroups[$normalizedPhone]['count'] ?? 0) + 1,
                        ];
                    }
                    if (in_array($classLevel, $classLevels, true) && !get_fee_structure($pdo, $classLevel, $academicYear)) {
                        $errors[] = "Row {$rowNumber}: no {$academicYear} fee structure exists for {$classLevel}.";
                    }

                    $records[] = [
                        'full_name' => $studentName,
                        'gender' => $gender,
                        'class_level' => $classLevel,
                        'parent_name' => $parentName,
                        'guardian_phone' => $parentPhone,
                        'guardian_alternate_phone' => $alternatePhone,
                        'source_row' => $rowNumber,
                        'normalized_phone' => $normalizedPhone,
                    ];
                }
            }

            $hasRepeatedPhone = (bool) array_filter($phoneGroups, static fn(array $group): bool => $group['count'] > 1);
            if ($hasRepeatedPhone && !$confirmRepeatedGuardians) {
                $errors[] = 'The file repeats one or more parent phone numbers. Confirm that repeated phone and parent-name pairs refer to the same guardian before importing.';
            }

            if (!$errors && !$records) {
                $errors[] = 'No student rows were found to import.';
            }
        } catch (Throwable $exception) {
            error_log('Student Excel parsing failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to read the Excel file. Check that it is a valid .xlsx workbook.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                "INSERT INTO students (registration_no, full_name, gender, class_level, admitted_academic_year, parent_name, guardian_phone, guardian_alternate_phone, enrollment_status)
                 VALUES (:registration_no, :full_name, :gender, :class_level, :admitted_academic_year, :parent_name, :guardian_phone, :guardian_alternate_phone, 'Active')"
            );
            $activationNotices = [];
            $batchParentIds = [];
            foreach ($records as $record) {
                $existingStudent = find_existing_student_identity($pdo, $record['full_name'], $record['guardian_phone']);
                if ($existingStudent) {
                    throw new InvalidArgumentException('Row ' . $record['source_row'] . ': this student already exists as ' . $existingStudent['registration_no'] . '. No students were imported.');
                }
                $registrationNo = generate_registration_no($pdo);
                $insert->execute([
                    'registration_no' => $registrationNo,
                    'full_name' => $record['full_name'],
                    'gender' => $record['gender'],
                    'class_level' => $record['class_level'],
                    'admitted_academic_year' => $academicYear,
                    'parent_name' => $record['parent_name'],
                    'guardian_phone' => $record['guardian_phone'],
                    'guardian_alternate_phone' => $record['guardian_alternate_phone'] !== '' ? $record['guardian_alternate_phone'] : null,
                ]);
                $studentId = (int) $pdo->lastInsertId();
                student_class_assignment_set($pdo, $studentId, $academicYear, $record['class_level'], 'manual', $actorId);
                $candidates = parent_registration_phone_candidates($pdo, (string) $record['normalized_phone']);
                $repeatedGuardianVerified = $confirmRepeatedGuardians
                    && (int) ($phoneGroups[$record['normalized_phone']]['count'] ?? 0) > 1;
                $parentAlreadySeenInBatch = isset($batchParentIds[$record['normalized_phone']]);
                $selectedBatchParentId = $parentAlreadySeenInBatch ? (int) $batchParentIds[$record['normalized_phone']] : 0;
                $confirmThisIdentity = $parentAlreadySeenInBatch
                    || ($repeatedGuardianVerified && $candidates === []);
                try {
                    $parentResult = parent_registration_resolve(
                        $pdo,
                        $actorId,
                        [
                            'parent_name' => $record['parent_name'],
                            'parent_phone' => $record['guardian_phone'],
                            'alternate_phone' => $record['guardian_alternate_phone'],
                            'parent_email' => '',
                            'parent_address' => '',
                        ],
                        $selectedBatchParentId,
                        $confirmThisIdentity,
                        false,
                        $candidates
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidArgumentException('Row ' . $record['source_row'] . ': ' . $exception->getMessage());
                }
                $batchParentIds[$record['normalized_phone']] = (int) $parentResult['parent_user_id'];
                parent_portal_link_child($pdo, $actorId, (int) $parentResult['parent_user_id'], $studentId, 'Parent/Guardian', true, true);
                sync_fee_balance_for_student($pdo, $studentId, $record['class_level'], $academicYear, $currentContext['term']);
                if (!empty($parentResult['created']) && !empty($parentResult['activation_token'])) {
                    $activationNotices[] = [
                        'username' => (string) $parentResult['username'],
                        'activation_code' => (string) $parentResult['activation_token'],
                        'activation_url' => url('parent/activate.php'),
                    ];
                }
            }

            $pdo->commit();
            flash('success', count($records) . ' student(s) imported with automatically assigned admission numbers, linked to parent accounts, and assigned current-term balances.');
            if ($activationNotices) {
                $_SESSION['parent_activation_notices'] = $activationNotices;
            }
            redirect('admin/import_students.php');
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage() . ' No students were imported.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Student import failed with code ' . (string) $exception->getCode());
            $errors[] = 'The student import failed and no records were saved. Please review the file and try again.';
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Student Registration</p>
        <h1>Import Students</h1>
        <p class="mb-0 text-muted"><?= h($academicYear . ' - ' . $currentContext['term']) ?></p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Back to Students</a>
        <a class="btn btn-primary" href="<?= url('admin/student_form.php') ?>">Add Student</a>
    </div>
</div>

<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <section class="panel">
            <h2>Upload Excel File</h2>
            <p class="text-muted">Admission numbers are assigned automatically. Any <code>regno</code> column in an older workbook is ignored.</p>
            <form class="row g-3" method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="col-12">
                    <label class="form-label" for="students_file">Excel File</label>
                    <input class="form-control" type="file" id="students_file" name="students_file" accept=".xlsx" required>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" id="confirm-repeated-guardians" name="confirm_repeated_guardians" type="checkbox" value="1" <?= $confirmRepeatedGuardians ? 'checked' : '' ?>>
                        <label class="form-check-label" for="confirm-repeated-guardians">I verified that repeated parent names and phone numbers in this file belong to the same guardian</label>
                    </div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit">Import Students</button>
                </div>
                <div class="col-12">
                    <a class="btn btn-outline-primary w-100" href="<?= url('admin/import_students.php?download_template=1') ?>">Download Sample Excel Template</a>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="panel">
            <h2>Accepted Columns</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Required</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([
                            'student_name' => true,
                            'gender' => true,
                            'parent_name' => true,
                            'class_level' => true,
                            'parent_phone' => true,
                            'guardian_alternate_phone' => false,
                        ] as $column => $required): ?>
                            <tr>
                                <td><?= h($column) ?></td>
                                <td><?= $required ? 'Yes' : 'No, optional' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
