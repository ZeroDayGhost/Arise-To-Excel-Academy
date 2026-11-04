<?php
$pageTitle = 'Import Fee Payments';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/fee_payment_import_helpers.php';

if (!current_admin_has_permission($pdo, 'fees.import')) {
    flash('error', 'You do not have permission to import fee payments.');
    redirect('admin/fees.php');
}

function fee_import_schema_ready(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (\'fee_payment_imports\', \'fee_payment_import_rows\')'
    );
    $statement->execute();
    return (int) $statement->fetchColumn() === 2;
}

function fee_import_send_template(): void
{
    $workbook = fee_import_create_template();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="fee_payment_import_template.xlsx"');
    header('Content-Length: ' . strlen($workbook));
    echo $workbook;
    exit;
}

if (isset($_GET['download_template'])) {
    fee_import_send_template();
}
if (($_GET['cancel'] ?? '') === '1') {
    unset($_SESSION['fee_payment_import_stage']);
    redirect('admin/import_fee_payments.php');
}

$schemaReady = fee_import_schema_ready($pdo);
$activeAcademicContext = current_academic_context($pdo);
$uploadYear = trim((string) ($_POST['fallback_year'] ?? $activeAcademicContext['academic_year']));
$uploadTerm = trim((string) ($_POST['fallback_term'] ?? $activeAcademicContext['term']));
$stage = $_SESSION['fee_payment_import_stage'] ?? null;
$errors = [];
$analysis = null;
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'upload') {
        unset($_SESSION['fee_payment_import_stage']);
        $uploadYear = trim((string) ($_POST['fallback_year'] ?? ''));
        $uploadTerm = trim((string) ($_POST['fallback_term'] ?? ''));
        $file = $_FILES['payments_file'] ?? null;
        if (!preg_match('/^\d{4}$/', $uploadYear) || (int) $uploadYear < 1900 || (int) $uploadYear > (int) date('Y') + 2 || !in_array($uploadTerm, term_options(), true)) {
            $errors[] = 'Choose a valid academic year and term for this import.';
        } elseif (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Choose a valid .xlsx workbook to preview.';
        } elseif (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $errors[] = 'Only .xlsx workbooks are supported by the current Excel reader.';
        } elseif ((int) $file['size'] <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The workbook must be between 1 byte and 5 MB.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'The uploaded workbook could not be verified.';
        } else {
            try {
                $signature = file_get_contents($file['tmp_name'], false, null, 0, 4);
                if (!is_string($signature) || !str_starts_with($signature, "PK\x03\x04")) {
                    throw new RuntimeException('The selected file is not a valid .xlsx workbook.');
                }
                $workbook = fee_import_read_workbook($file['tmp_name']);
                $hash = hash_file('sha256', $file['tmp_name']);
                if (!is_string($hash)) {
                    throw new RuntimeException('The uploaded workbook could not be verified.');
                }
                if ($schemaReady) {
                    $alreadyImported = $pdo->prepare('SELECT id FROM fee_payment_imports WHERE source_sha256 = :hash LIMIT 1');
                    $alreadyImported->execute(['hash' => $hash]);
                    if ($alreadyImported->fetchColumn()) {
                        throw new RuntimeException('This exact workbook has already been imported. It cannot be imported a second time.');
                    }
                }
                $stage = [
                    'token' => bin2hex(random_bytes(24)),
                    'filename' => basename((string) $file['name']),
                    'sha256' => $hash,
                    'rows' => $workbook['rows'],
                    'columns' => $workbook['columns'],
                    'fallback_year' => $uploadYear,
                    'fallback_term' => $uploadTerm,
                    'mappings' => [],
                    'created_at' => time(),
                ];
                $_SESSION['fee_payment_import_stage'] = $stage;
            } catch (Throwable $exception) {
                $errors[] = $exception instanceof PDOException
                    ? 'The workbook could not be checked against the payment records. Please try again or contact the system administrator.'
                    : ($exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The workbook could not be read. Check that it opens in Excel and try again.');
            }
        }
    } else {
        $postedToken = (string) ($_POST['stage_token'] ?? '');
        if (!$stage || $postedToken === '' || !hash_equals((string) ($stage['token'] ?? ''), $postedToken)) {
            unset($_SESSION['fee_payment_import_stage']);
            $stage = null;
            $errors[] = 'The preview expired. Upload the workbook again.';
        } elseif (time() - (int) ($stage['created_at'] ?? 0) > 1800) {
            unset($_SESSION['fee_payment_import_stage']);
            $stage = null;
            $errors[] = 'The preview expired after 30 minutes. Upload the workbook again.';
        } else {
            $stage['fallback_year'] = trim((string) ($_POST['fallback_year'] ?? $stage['fallback_year'] ?? ''));
            $stage['fallback_term'] = trim((string) ($_POST['fallback_term'] ?? $stage['fallback_term'] ?? ''));
            $postedMappings = $_POST['student_mapping'] ?? [];
            $mappings = [];
            if (is_array($postedMappings)) {
                foreach ($postedMappings as $rowNumber => $registrationNo) {
                    if (is_scalar($registrationNo)) {
                        $value = trim((string) $registrationNo);
                        if ($value !== '' && strlen($value) <= 60) {
                            $mappings[(int) $rowNumber] = $value;
                        }
                    }
                }
            }
            $stage['mappings'] = $mappings;
            $_SESSION['fee_payment_import_stage'] = $stage;

            if (!$schemaReady && $action === 'import') {
                $errors[] = 'The fee-import database migration has not been applied. Ask the system administrator to run database/migrations/20261021_fee_payment_imports.php.';
            } elseif (!in_array($action, ['validate_mapping', 'import'], true)) {
                $errors[] = 'Choose a valid import action.';
            } else {
                try {
                    $analysis = fee_import_validate_rows(
                        $pdo,
                        $stage['rows'],
                        $stage['mappings'],
                        $stage['fallback_year'],
                        $stage['fallback_term']
                    );
                    if ($action === 'import') {
                        if ($analysis['error_count'] > 0) {
                            $errors[] = 'The workbook has invalid rows. Correct the listed problems and upload the corrected workbook.';
                        } elseif ($analysis['warning_count'] > 0 && ($_POST['confirm_warnings'] ?? '') !== '1') {
                            $errors[] = 'Review the warnings and check the confirmation box before importing.';
                        } else {
                            if (!$schemaReady) {
                                throw new RuntimeException('The fee-import database migration has not been applied.');
                            }
                            $summary = fee_import_commit(
                                $pdo,
                                $stage['rows'],
                                $stage['mappings'],
                                $stage['fallback_year'],
                                $stage['fallback_term'],
                                $stage['filename'],
                                $stage['sha256'],
                                (int) ($_SESSION['admin_id'] ?? 0),
                                ($_POST['confirm_warnings'] ?? '') === '1'
                            );
                            unset($_SESSION['fee_payment_import_stage']);
                            flash('success', 'Imported ' . $summary['imported_rows'] . ' payment(s). ' . $summary['duplicate_warnings'] . ' warning(s) were reviewed. Import #' . $summary['import_id'] . ' is in the audit history below.');
                            redirect('admin/import_fee_payments.php');
                        }
                    }
                } catch (Throwable $exception) {
                    $errors[] = $exception instanceof PDOException
                        ? 'The import could not be completed. No partial payments were saved.'
                        : ($exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The import could not be completed. No partial payments were saved.');
                    try {
                        $analysis = fee_import_validate_rows(
                            $pdo,
                            $stage['rows'],
                            $stage['mappings'],
                            $stage['fallback_year'],
                            $stage['fallback_term']
                        );
                    } catch (Throwable $ignored) {
                        $analysis = null;
                    }
                }
            }
        }
    }
}

if ($stage && $analysis === null) {
    try {
        $analysis = fee_import_validate_rows(
            $pdo,
            $stage['rows'],
            $stage['mappings'] ?? [],
            (string) ($stage['fallback_year'] ?? ''),
            (string) ($stage['fallback_term'] ?? '')
        );
    } catch (Throwable $exception) {
        $errors[] = 'The preview could not be refreshed. Upload the workbook again.';
    }
}

$studentSuggestions = $pdo->query('SELECT registration_no, full_name FROM students ORDER BY registration_no')->fetchAll();
$importHistory = [];
if ($schemaReady) {
    $importHistory = $pdo->query(
        'SELECT i.*, COALESCE(a.name, a.username, \'System\') AS actor_name
         FROM fee_payment_imports i LEFT JOIN admin a ON a.id = i.actor_id
         ORDER BY i.created_at DESC, i.id DESC LIMIT 20'
    )->fetchAll();
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Fee Payments</p>
        <h1>Import Historical Payments</h1>
        <p class="mb-0 text-muted">Preview, match, and import older fee transactions into the payment history.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= url('admin/fees.php') ?>">Back to Fees</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endforeach; ?>
<?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Import is unavailable until the fee-import database migration is applied. You can still download the workbook template.</div>
<?php endif; ?>

<?php if (!$stage): ?>
    <section class="panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h2>Prepare an Import</h2>
                <p class="text-muted mb-0">Enter a student name. A unique match fills the registered admission number automatically; duplicate names must be resolved in the preview.</p>
            </div>
            <a class="btn btn-outline-primary" href="<?= url('admin/import_fee_payments.php?download_template=1') ?>">Download Sample Template</a>
        </div>
        <hr>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload">
            <div class="col-12 col-xl-5">
                <label class="form-label" for="payments_file">Fee payments workbook (.xlsx)</label>
                <input class="form-control" type="file" name="payments_file" id="payments_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <div class="form-text">Maximum 5 MB and 1,000 payment rows.</div>
            </div>
            <div class="col-6 col-xl-2">
                <label class="form-label" for="fallback_year">Academic year for all rows</label>
                <select class="form-select" name="fallback_year" id="fallback_year" required>
                    <?php for ($year = (int) date('Y') + 1; $year >= 1900; $year--): ?>
                        <option value="<?= $year ?>" <?= $uploadYear === (string) $year ? 'selected' : '' ?>><?= $year ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-6 col-xl-2">
                <label class="form-label" for="fallback_term">Term for all rows</label>
                <select class="form-select" name="fallback_term" id="fallback_term" required>
                    <?php foreach (term_options() as $term): ?>
                        <option value="<?= h($term) ?>" <?= $uploadTerm === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-xl-3">
                <button class="btn btn-primary w-100" type="submit">Upload and Preview</button>
            </div>
        </form>
        <div class="small text-muted mt-3">The selected academic year and term apply to every row. The system fills the admission number, enrollment status, and balance from its records. Dates may be ISO (YYYY-MM-DD), day/month/year, or actual Excel date cells. M-PESA transaction codes are not verified by importing.</div>
    </section>
<?php else: ?>
    <section class="panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h2>Review Import Preview</h2>
                <p class="text-muted mb-0"><?= h($stage['filename']) ?> · <?= count($stage['rows']) ?> payment row(s) · SHA-256 protected against exact-file retries</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="<?= url('admin/import_fee_payments.php?download_template=1') ?>">Download Template</a>
                <a class="btn btn-outline-secondary" href="<?= url('admin/import_fee_payments.php?cancel=1') ?>">Cancel</a>
            </div>
        </div>
        <hr>
        <?php if ($analysis): ?>
            <div class="row g-3 mb-3">
                <div class="col-4 col-md-3"><div class="border rounded p-3 h-100"><small>Total rows</small><div class="fs-5 fw-semibold"><?= count($analysis['rows']) ?></div></div></div>
                <div class="col-4 col-md-3"><div class="border rounded p-3 h-100"><small>Valid rows</small><div class="fs-5 fw-semibold text-success"><?= count($analysis['rows']) - count(array_filter($analysis['rows'], static fn($row) => $row['errors'])) ?></div></div></div>
                <div class="col-4 col-md-3"><div class="border rounded p-3 h-100"><small>Invalid rows</small><div class="fs-5 fw-semibold text-danger"><?= count(array_filter($analysis['rows'], static fn($row) => $row['errors'])) ?></div></div></div>
                <div class="col-12 col-md-3"><div class="border rounded p-3 h-100"><small>Warnings to review</small><div class="fs-5 fw-semibold text-warning-emphasis"><?= $analysis['warning_count'] ?></div></div></div>
            </div>
            <div class="alert alert-info">Supplied receipt numbers and payment dates are preserved. Blank receipt numbers receive a new system receipt only when the import is confirmed. Reversed/cancelled rows and exact duplicate identifiers are rejected.</div>
            <form method="post">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="stage_token" value="<?= h($stage['token']) ?>">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <label class="form-label" for="preview_year">Academic year for all rows</label>
                        <select class="form-select" name="fallback_year" id="preview_year" required>
                            <?php for ($year = (int) date('Y') + 1; $year >= 1900; $year--): ?>
                                <option value="<?= $year ?>" <?= (string) $stage['fallback_year'] === (string) $year ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label" for="preview_term">Term for all rows</label>
                        <select class="form-select" name="fallback_term" id="preview_term" required>
                            <?php foreach (term_options() as $term): ?>
                                <option value="<?= h($term) ?>" <?= $stage['fallback_term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-6 d-flex align-items-end"><p class="small text-muted mb-2">These selections will be assigned to every row. Year and term columns in older workbooks are ignored.</p></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead><tr><th>Excel row</th><th>Admission number / mapping</th><th>Student</th><th>Period</th><th>Payment</th><th>Review</th></tr></thead>
                        <tbody>
                        <?php foreach ($analysis['rows'] as $row): ?>
                            <tr>
                                <td><?= (int) $row['source_row'] ?></td>
                                <td>
                                    <div><?= h($row['student']['registration_no'] ?? ($row['source_registration_no'] ?: 'Not matched')) ?></div>
                                    <?php if (!empty($row['matched_by_name']) && $row['student']): ?><small class="text-success">Matched by student name</small><?php endif; ?>
                                    <?php if ($row['needs_mapping']): ?>
                                        <label class="form-label small mt-2 mb-1" for="map_<?= (int) $row['source_row'] ?>">Explicitly map to an existing admission number</label>
                                        <input class="form-control form-control-sm" list="fee-import-students" name="student_mapping[<?= (int) $row['source_row'] ?>]" id="map_<?= (int) $row['source_row'] ?>" value="<?= h($row['mapped_registration_no']) ?>" placeholder="Search by admission number">
                                    <?php elseif ($row['mapped_registration_no'] !== ''): ?>
                                        <small class="text-muted">Mapped to <?= h($row['mapped_registration_no']) ?></small>
                                        <input type="hidden" name="student_mapping[<?= (int) $row['source_row'] ?>]" value="<?= h($row['mapped_registration_no']) ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['student'] ? h($row['student']['full_name'] . ' · ' . $row['student']['registration_no']) : 'Not matched' ?></td>
                                <td><?= h(($row['data']['year'] ?: 'Year missing') . ' · ' . ($row['data']['term'] ?: 'Term missing')) ?><br><?= h($row['data']['payment_date'] ?: 'Date invalid') ?></td>
                                <td><?= $row['data']['payment_method'] ? h($row['data']['payment_method']) : 'Method invalid' ?><br><?= $row['data']['amount_paid'] !== null ? money((float) $row['data']['amount_paid']) : 'Amount invalid' ?><br><small><?= h($row['data']['receipt_no'] ?: 'Receipt assigned on import') ?></small></td>
                                <td>
                                    <?php foreach ($row['errors'] as $message): ?><div class="text-danger small"><strong>Error:</strong> <?= h($message) ?></div><?php endforeach; ?>
                                    <?php foreach ($row['warnings'] as $message): ?><div class="text-warning-emphasis small"><strong>Review:</strong> <?= h($message) ?></div><?php endforeach; ?>
                                    <?php if (!$row['errors'] && !$row['warnings']): ?><span class="text-success small">Ready</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <datalist id="fee-import-students">
                    <?php foreach ($studentSuggestions as $suggestion): ?>
                        <option value="<?= h($suggestion['registration_no']) ?>"><?= h($suggestion['full_name']) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3">
                    <button class="btn btn-outline-primary" type="submit" name="action" value="validate_mapping">Revalidate preview and student mappings</button>
                    <div class="d-flex flex-column align-items-start align-items-md-end gap-2">
                        <?php if ($analysis['warning_count'] > 0): ?>
                            <label class="form-check-label d-flex gap-2 align-items-start">
                                <input class="form-check-input mt-1" type="checkbox" name="confirm_warnings" value="1">
                                <span>I reviewed the warnings, possible duplicates, receipt assignments, and balance reconciliation.</span>
                            </label>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit" name="action" value="import" <?= $analysis['error_count'] > 0 || !$schemaReady ? 'disabled' : '' ?>>Confirm and Import <?= count($analysis['rows']) ?> Payments</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div><h2>Recent Import History</h2><p class="text-muted mb-0">Completed imports and their row-level audit trails.</p></div>
    </div>
    <?php if (!$schemaReady): ?>
        <p class="text-muted mb-0">Import history will appear after the database migration is applied.</p>
    <?php elseif (!$importHistory): ?>
        <p class="text-muted mb-0">No fee payment imports have been completed.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Date</th><th>Source file</th><th>Administrator</th><th>Period</th><th>Imported</th><th>Rejected / skipped</th><th>Warnings</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($importHistory as $history): ?>
                    <tr>
                        <td><?= h(date('Y-m-d H:i', strtotime($history['created_at']))) ?></td>
                        <td><?= h($history['source_filename']) ?></td>
                        <td><?= h($history['actor_name']) ?></td>
                        <td><?= h(($history['academic_year'] ?: 'Mixed years') . ($history['term'] ? ' · ' . $history['term'] : ' · Mixed terms')) ?></td>
                        <td><?= (int) $history['imported_rows'] ?> / <?= (int) $history['total_rows'] ?></td>
                        <td><?= (int) $history['rejected_rows'] ?> / <?= (int) $history['skipped_rows'] ?></td>
                        <td><?= (int) $history['duplicate_warnings'] ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/fee_payment_import_detail.php?id=' . (int) $history['id']) ?>">View audit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
