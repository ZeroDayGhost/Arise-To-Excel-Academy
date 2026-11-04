<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Only allow Settings module access. Profile and password changes live in admin/profile.php.
if (!current_admin_can_access_module($pdo, 'Settings')) {
    flash('error', 'You do not have permission to access Settings.');
    redirect('admin/dashboard.php');
}

$currentContext = current_academic_context($pdo);
$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active'")->fetchColumn();
$feeStructures = (int) $pdo->query("SELECT COUNT(*) FROM fee_structures")->fetchColumn();
$calendarTerms = (int) $pdo->query("SELECT COUNT(*) FROM academic_calendar")->fetchColumn();
$expenseCategories = count(expense_category_options());

// Get users and roles data
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">System Settings</p>
        <h1>Settings</h1>
        <p class="mb-0 text-muted">Manage the academic period, fee setup, imports, and operational controls.</p>
    </div>
    <div class="action-row">
        <?php if (current_admin_has_permission($pdo, 'settings.academic_calendar')): ?>
            <a class="btn btn-outline-primary" href="<?= url('admin/academic_calendar.php') ?>"><i class="fa-regular fa-calendar"></i>Academic Calendar</a>
        <?php else: ?>
            <button class="btn btn-outline-primary" disabled><i class="fa-regular fa-calendar"></i>Academic Calendar</button>
        <?php endif; ?>

        <?php if (current_admin_has_permission($pdo, 'settings.fee_structure')): ?>
            <a class="btn btn-primary" href="<?= url('admin/fee_structures.php') ?>"><i class="fa-solid fa-file-invoice-dollar"></i>Fee Structure</a>
        <?php else: ?>
            <button class="btn btn-primary" disabled><i class="fa-solid fa-file-invoice-dollar"></i>Fee Structure</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <div class="panel-heading">
                <h2>Active Academic Period</h2>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-12">
                    <div class="mini-stat">
                        <span>Academic Year</span>
                        <strong><?= h($currentContext['academic_year']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-12">
                    <div class="mini-stat">
                        <span>Current Term</span>
                        <strong><?= h($currentContext['term']) ?></strong>
                    </div>
                </div>
                <div class="col-12">
                    <div class="mini-stat">
                        <span>System Date</span>
                        <strong><?= h($currentContext['today']) ?></strong>
                    </div>
                </div>
            </div>
            <a class="btn btn-outline-primary w-100 mt-3" href="<?= url('admin/academic_calendar.php') ?>">Update Academic Period</a>
        </section>
        <section class="panel mt-4">
            <div class="panel-heading">
                <h2>Account</h2>
            </div>
            <div class="p-3">
                <a class="btn btn-outline-primary w-100 mb-2" href="<?= url('admin/profile.php') ?>">My Profile</a>
                <a class="btn btn-primary w-100" href="<?= url('admin/profile.php#change-password') ?>">Change My Password</a>
            </div>
        </section>
    </div>

    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading">
                <h2>Configuration Overview</h2>
            </div>
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Students</span><strong><?= $totalStudents ?></strong></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Fee Structures</span><strong><?= $feeStructures ?></strong></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Calendar Terms</span><strong><?= $calendarTerms ?></strong></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="mini-stat"><span>Expense Types</span><strong><?= $expenseCategories ?></strong></div>
                </div>
            </div>
        </section>

        <section class="panel mt-4">
            <div class="panel-heading">
                <h2>Administration Shortcuts</h2>
            </div>
            <div class="quick-actions-grid">
                <?php if (current_admin_has_permission($pdo, 'students.import')): ?>
                    <a class="quick-action-card action-blue" href="<?= url('admin/import_students.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-file-import"></i></span>
                        <span>Import Students</span>
                    </a>
                <?php endif; ?>
                <?php if (current_admin_can_access_module($pdo, 'Students')): ?>
                    <a class="quick-action-card action-green" href="<?= url('admin/students.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-users"></i></span>
                        <span>Student Roster</span>
                    </a>
                <?php endif; ?>
                <?php if (current_admin_has_permission($pdo, 'settings.manage_class_levels')): ?>
                    <a class="quick-action-card action-teal" href="<?= url('admin/class_levels.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-plus-circle"></i></span>
                        <span>Add Class Level</span>
                    </a>
                <?php endif; ?>
                <?php if (current_admin_has_permission($pdo, 'reports.view')): ?>
                    <a class="quick-action-card action-purple" href="<?= url('admin/reports.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-chart-pie"></i></span>
                        <span>Reports</span>
                    </a>
                <?php endif; ?>
                <?php if (current_admin_can_access_module($pdo, 'Expenses')): ?>
                    <a class="quick-action-card action-orange" href="<?= url('admin/school_expenses.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-receipt"></i></span>
                        <span>Expenses</span>
                    </a>
                <?php endif; ?>
                <?php if (current_admin_has_permission($pdo, 'settings.manage_users')): ?>
                    <a class="quick-action-card action-amber" href="<?= url('admin/users_permissions.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-shield-halved"></i></span>
                        <span>Users & Permissions</span>
                    </a>
                    <a class="quick-action-card action-blue" href="<?= url('admin/parent_accounts.php') ?>">
                        <span class="quick-action-icon"><i class="fa-solid fa-people-roof"></i></span>
                        <span>Parent Accounts</span>
                    </a>
                <?php endif; ?>
            </div>
        </section>

    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
