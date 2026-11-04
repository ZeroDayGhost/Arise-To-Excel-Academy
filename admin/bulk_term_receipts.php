<?php
$pageTitle = 'Generate Term Receipts';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
require_once __DIR__ . '/../includes/bulk_term_receipts_helpers.php';

if (!current_admin_has_permission($pdo, 'reports.access')
    || !current_admin_has_permission($pdo, 'reports.export')) {
    flash('error', 'You do not have permission to generate term balance statements.');
    redirect('admin/reports.php');
}

$modules = bulk_term_receipt_available_modules($pdo);
$years = academic_view_year_options($pdo);
$currentContext = current_academic_context($pdo);
$requestedYear = (string) ($_GET['academic_year'] ?? '');
$academicYear = in_array($requestedYear, $years, true)
    ? $requestedYear
    : (in_array((string) $currentContext['academic_year'], $years, true)
        ? (string) $currentContext['academic_year']
        : (string) ($years[0] ?? ''));
$terms = $academicYear !== '' ? term_options() : [];
$requestedTerm = (string) ($_GET['term'] ?? '');
$term = in_array($requestedTerm, $terms, true)
    ? $requestedTerm
    : (in_array((string) $currentContext['term'], $terms, true)
        ? (string) $currentContext['term']
        : (string) ($terms[0] ?? ''));
$module = (string) ($_GET['module'] ?? 'fees');
$errorMessage = '';
$infoMessage = '';

if (($_GET['download'] ?? '') === '1') {
    try {
        if (!isset($modules[$module])) {
            throw new InvalidArgumentException('Select an available payment module.');
        }
        if (!preg_match('/^\d{4}$/', $academicYear) || !in_array($term, $terms, true)) {
            throw new InvalidArgumentException('Select a valid academic year and term.');
        }

        $calendar = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term LIMIT 1');
        $calendar->execute(['academic_year' => $academicYear, 'term' => $term]);
        $period = $calendar->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$period && in_array($module, ['daycare', 'uniform'], true)) {
            throw new InvalidArgumentException('The selected academic term has no calendar dates. Set its dates in Academic Calendar and try again.');
        }

        $statements = bulk_term_receipt_statement_rows($pdo, $module, $academicYear, $term, $period);
        if (!$statements) {
            $infoMessage = 'No outstanding balances were found for this module and academic period.';
        } else {
            $pdf = bulk_term_receipt_pdf($statements, [
                'module' => $module,
                'module_label' => $modules[$module],
                'year' => $academicYear,
                'term' => $term,
            ]);
            $filename = 'term_balance_statements_' . preg_replace('/[^a-z0-9_-]/i', '_', $module)
                . '_' . $academicYear . '_' . str_replace(' ', '_', $term) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, no-store, max-age=0');
            echo $pdf;
            exit;
        }
    } catch (InvalidArgumentException $exception) {
        $errorMessage = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Bulk term balance PDF failed: ' . $exception->getMessage());
        $errorMessage = 'The statements could not be generated. Please check the selected period or contact the system administrator.';
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">School Reports</p>
        <h1>Generate Term Receipts</h1>
        <p>Select a module and academic period to prepare individual, print-ready balance statements.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= h(url('admin/reports.php')) ?>">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Reports
        </a>
    </div>
</div>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
<?php endif; ?>
<?php if ($infoMessage !== ''): ?>
    <div class="alert alert-info" role="status"><?= h($infoMessage) ?></div>
<?php endif; ?>

<section class="panel bulk-statements-panel" aria-labelledby="bulk-statements-title">
    <div class="panel-header">
        <div>
            <h2 id="bulk-statements-title"><i class="fa-solid fa-file-circle-check" aria-hidden="true"></i> Outstanding account statements</h2>
            <p class="panel-subtitle">Only accounts with an outstanding balance are included. Each student or account prints on its own A4 page.</p>
        </div>
    </div>
    <div class="alert alert-warning" role="note">
        These are balance statements, not proof of payment. Generation is read-only and does not create or change payment records.
    </div>

    <?php if (!$modules): ?>
        <div class="alert alert-danger" role="alert">No supported payment modules are available in the current database.</div>
    <?php elseif (!$years): ?>
        <div class="alert alert-warning" role="status">No academic years are configured. Add academic terms in Academic Calendar first.</div>
    <?php else: ?>
        <form method="get" class="bulk-statements-form">
            <div class="bulk-statements-fields">
                <div class="form-group">
                    <label for="module">Payment module</label>
                    <select class="form-control" id="module" name="module" required>
                        <?php foreach ($modules as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $module === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="academic-year">Academic year</label>
                    <select class="form-control" id="academic-year" name="academic_year" required>
                        <?php foreach ($years as $yearOption): ?>
                            <option value="<?= h($yearOption) ?>" <?= $academicYear === $yearOption ? 'selected' : '' ?>><?= h($yearOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="term">Term</label>
                    <select class="form-control" id="term" name="term" required <?= $terms ? '' : 'disabled' ?>>
                        <?php foreach ($terms as $termOption): ?>
                            <option value="<?= h($termOption) ?>" <?= $term === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary" type="submit" name="download" value="1" <?= $terms ? '' : 'disabled' ?>>
                <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> Generate &amp; Download Receipts
            </button>
        </form>
        <?php if (!$terms): ?>
            <p class="text-muted mb-0">The selected academic year has no configured terms.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<style>
.bulk-statements-panel { max-width: 1080px; margin: 0 auto; }
.bulk-statements-panel .panel-header { margin-bottom: 14px; }
.bulk-statements-panel h2 { margin: 0; font-size: 1.05rem; }
.bulk-statements-panel .panel-subtitle { margin: 5px 0 0; color: var(--muted, #667085); }
.bulk-statements-form { display: grid; gap: 18px; }
.bulk-statements-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.bulk-statements-form .form-group { min-width: 0; }
@media (max-width: 760px) {
    .bulk-statements-fields { grid-template-columns: 1fr; gap: 12px; }
    .bulk-statements-form > .btn { width: 100%; }
}
</style>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
