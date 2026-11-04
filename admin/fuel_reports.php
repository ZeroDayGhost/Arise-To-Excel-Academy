<?php
$pageTitle = 'Fuel Reports';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$report = $_GET['report'] ?? 'monthly';

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Reports</p>
        <h1>School Van Fuel Reports</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/fuel.php') ?>">Fuel History</a>
    </div>
</div>

<section class="panel">
    <div class="row g-3">
        <div class="col-md-3"><a class="btn btn-light w-100" href="<?= url('admin/fuel_reports.php?report=daily') ?>">Daily</a></div>
        <div class="col-md-3"><a class="btn btn-light w-100" href="<?= url('admin/fuel_reports.php?report=monthly') ?>">Monthly</a></div>
        <div class="col-md-3"><a class="btn btn-light w-100" href="<?= url('admin/fuel_reports.php?report=by_vehicle') ?>">By Vehicle</a></div>
        <div class="col-md-3">
            <div class="btn-group w-100">
                <a class="btn btn-primary" href="<?= url('admin/fuel_reports_export.php?report=' . $report . '&format=csv') ?>">CSV</a>
                <a class="btn btn-primary" href="<?= url('admin/fuel_reports_export.php?report=' . $report . '&format=xlsx') ?>">Excel</a>
                <a class="btn btn-primary" target="_blank" href="<?= url('admin/fuel_reports_export.php?report=' . $report . '&format=pdf') ?>">Download PDF</a>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <?php if ($report === 'by_vehicle'): ?>
            <?php $rows = $pdo->query("SELECT v.vehicle_name, SUM(ft.total_amount) AS total_spent FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id GROUP BY ft.vehicle_id ORDER BY total_spent DESC")->fetchAll(); ?>
            <h3>Fuel Cost by Vehicle</h3>
            <div class="table-responsive"><table class="table"><thead><tr><th>Vehicle</th><th>Total Spent</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= h($r['vehicle_name']) ?></td><td><?= money((float)$r['total_spent']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($report === 'daily'): ?>
            <?php $rows = $pdo->query("SELECT fuel_date, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY fuel_date ORDER BY fuel_date DESC LIMIT 31")->fetchAll(); ?>
            <h3>Daily Fuel Cost (last 31 days)</h3>
            <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Total Spent</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= h($r['fuel_date']) ?></td><td><?= money((float)$r['total_spent']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <?php $rows = $pdo->query("SELECT DATE_FORMAT(fuel_date, '%Y-%m') AS ym, SUM(total_amount) AS total_spent FROM fuel_transactions GROUP BY ym ORDER BY ym DESC LIMIT 24")->fetchAll(); ?>
            <h3>Monthly Fuel Cost</h3>
            <div class="table-responsive"><table class="table"><thead><tr><th>Month</th><th>Total Spent</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= h($r['ym']) ?></td><td><?= money((float)$r['total_spent']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
