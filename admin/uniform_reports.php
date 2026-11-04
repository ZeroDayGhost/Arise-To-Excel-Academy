<?php
$pageTitle = 'Uniform Reports';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$report = $_GET['report'] ?? 'sales';

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Reports</p>
        <h1>School Uniform Reports</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/uniforms.php') ?>">Catalog</a>
    </div>
</div>

<section class="panel">
    <div class="row g-3">
        <div class="col-md-3">
            <a class="btn btn-light w-100" href="<?= url('admin/uniform_reports.php?report=sales') ?>">Uniform Sales</a>
        </div>
        <div class="col-md-3">
            <a class="btn btn-light w-100" href="<?= url('admin/uniform_reports.php?report=stock') ?>">Stock Report</a>
        </div>
        <div class="col-md-3">
            <a class="btn btn-light w-100" href="<?= url('admin/uniform_reports.php?report=low_stock') ?>">Low Stock</a>
        </div>
        <div class="col-md-3">
            <div class="btn-group w-100">
                <a class="btn btn-primary" href="<?= url('admin/uniform_reports_export.php?report=' . $report . '&format=csv') ?>">CSV</a>
                <a class="btn btn-primary" href="<?= url('admin/uniform_reports_export.php?report=' . $report . '&format=xlsx') ?>">Excel</a>
                <a class="btn btn-primary" target="_blank" href="<?= url('admin/uniform_reports_export.php?report=' . $report . '&format=pdf') ?>">Download PDF</a>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <?php if ($report === 'sales'): ?>
            <?php
                $rows = $pdo->query("SELECT us.receipt_no, s.registration_no, s.full_name, s.enrollment_status, us.grand_total, us.amount_paid, us.balance, us.payment_date FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id ORDER BY us.payment_date DESC")->fetchAll();
            ?>
            <h3>Uniform Sales</h3>
            <div class="table-responsive"><table class="table"><thead><tr><th>Receipt</th><th>Student</th><th>Enrollment</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Date</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= h($r['receipt_no']) ?></td><td><?= h(($r['registration_no'] ?: 'N/A').' - '.($r['full_name'] ?: 'Unlinked sale')) ?></td><td><?= h($r['enrollment_status'] ?: 'Not linked to a student') ?></td><td><?= money((float)$r['grand_total']) ?></td><td><?= money((float)$r['amount_paid']) ?></td><td><?= money((float)$r['balance']) ?></td><td><?= h($r['payment_date']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($report === 'stock'): ?>
            <?php $rows = uniform_stock_report_rows($pdo); ?>
            <h3>Stock Report</h3>
            <div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Gender</th><th>Category</th><th>Size</th><th>Price</th><th>Opening</th><th>Available</th><th>Reorder</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= h($r['uniform_name']) ?></td><td><?= h($r['gender']) ?></td><td><?= h($r['category']) ?></td><td><?= h($r['size']) ?></td><td><?= money((float)$r['selling_price']) ?></td><td><?= (int)$r['opening_stock'] ?></td><td><?= (int)$r['available_stock'] ?></td><td><?= (int)$r['reorder_level'] ?></td><td><?= h($r['variant_status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <?php $rows = uniform_stock_report_rows($pdo, true); ?>
            <h3>Low Stock</h3>
            <div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Gender</th><th>Category</th><th>Size</th><th>Price</th><th>Available</th><th>Reorder</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= h($r['uniform_name']) ?></td><td><?= h($r['gender']) ?></td><td><?= h($r['category']) ?></td><td><?= h($r['size']) ?></td><td><?= money((float)$r['selling_price']) ?></td><td><?= (int)$r['available_stock'] ?></td><td><?= (int)$r['reorder_level'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
