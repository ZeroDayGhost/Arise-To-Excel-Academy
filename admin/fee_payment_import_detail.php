<?php
$pageTitle = 'Fee Import Audit';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_has_permission($pdo, 'fees.import')) {
    flash('error', 'You do not have permission to view fee import audits.');
    redirect('admin/fees.php');
}

$importId = (int) ($_GET['id'] ?? 0);
$batchQuery = $pdo->prepare(
    'SELECT i.*, COALESCE(a.name, a.username, \'System\') AS actor_name
     FROM fee_payment_imports i LEFT JOIN admin a ON a.id = i.actor_id WHERE i.id = :id LIMIT 1'
);
$batchQuery->execute(['id' => $importId]);
$batch = $batchQuery->fetch();
if (!$batch) {
    flash('error', 'Fee import audit record was not found.');
    redirect('admin/import_fee_payments.php');
}

$rowQuery = $pdo->prepare(
    'SELECT r.*, s.registration_no AS matched_registration_no, s.full_name,
            f.receipt_no, f.payment_date, f.year, f.term, f.payment_method, f.amount_paid
     FROM fee_payment_import_rows r
     JOIN students s ON s.id = r.student_id
     JOIN fees f ON f.id = r.payment_id
     WHERE r.import_id = :import_id ORDER BY r.source_row_number'
);
$rowQuery->execute(['import_id' => $importId]);
$rows = $rowQuery->fetchAll();
$canPrintFees = current_admin_has_permission($pdo, 'fees.print');

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Fee Import Audit</p>
        <h1>Import #<?= (int) $batch['id'] ?></h1>
        <p class="mb-0 text-muted"><?= h($batch['source_filename']) ?> · <?= h($batch['created_at']) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= url('admin/import_fee_payments.php') ?>">Back to Import History</a>
</div>

<section class="panel mb-4">
    <div class="row g-3">
        <div class="col-6 col-lg-3"><small class="text-muted">Administrator</small><div class="fw-semibold"><?= h($batch['actor_name']) ?></div></div>
        <div class="col-6 col-lg-3"><small class="text-muted">Academic period</small><div class="fw-semibold"><?= h(($batch['academic_year'] ?: 'Mixed years') . ($batch['term'] ? ' · ' . $batch['term'] : ' · Mixed terms')) ?></div></div>
        <div class="col-6 col-lg-3"><small class="text-muted">Imported</small><div class="fw-semibold"><?= (int) $batch['imported_rows'] ?> of <?= (int) $batch['total_rows'] ?></div></div>
        <div class="col-6 col-lg-3"><small class="text-muted">Warnings / errors</small><div class="fw-semibold"><?= (int) $batch['duplicate_warnings'] ?> / <?= (int) $batch['error_count'] ?></div></div>
        <div class="col-12"><small class="text-muted">Workbook fingerprint</small><div class="small text-break font-monospace"><?= h($batch['source_sha256']) ?></div></div>
    </div>
</section>

<section class="panel">
    <h2>Imported Rows</h2>
    <?php if (!$rows): ?>
        <p class="text-muted mb-0">This import has no committed rows.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead><tr><th>Excel row</th><th>Source admission no.</th><th>Matched student</th><th>Period</th><th>Date / method</th><th>Receipt</th><th>Amount</th><th>Import notes</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['source_row_number'] ?></td>
                        <td><?= h($row['source_registration_no'] ?: 'Not supplied (matched by name)') ?></td>
                        <td><?= h($row['full_name'] . ' · ' . $row['matched_registration_no']) ?></td>
                        <td><?= h($row['year'] . ' · ' . $row['term']) ?></td>
                        <td><?= h($row['payment_date'] . ' · ' . $row['payment_method']) ?></td>
                        <td><?php if ($canPrintFees): ?><a href="<?= url('admin/receipt.php?id=' . (int) $row['payment_id']) ?>"><?= h($row['receipt_no']) ?></a><?php else: ?><?= h($row['receipt_no']) ?><?php endif; ?></td>
                        <td><?= money((float) $row['amount_paid']) ?></td>
                        <td><?= h($row['message'] ?: 'Imported without warnings') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
