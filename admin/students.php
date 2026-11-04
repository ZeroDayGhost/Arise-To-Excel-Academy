<?php
$pageTitle = 'Students';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'students.access')) {
    flash('error', 'You do not have permission to access Students.');
    redirect('admin/dashboard.php');
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$currentAdminRole = $adminId ? get_user_role_template_name($pdo, $adminId) : '';
$currentAdminClass = $adminId ? current_admin_class_level($pdo) : null;
$canViewStudents = current_admin_has_permission($pdo, 'students.view');
$canAddStudents = current_admin_has_permission($pdo, 'students.add');
$canEditStudents = current_admin_has_permission($pdo, 'students.edit');
$canTransferStudents = current_admin_has_permission($pdo, 'students.transfer');
$canManageHistoricalFees = current_admin_has_permission($pdo, 'students.manage_historical_fees');
$canImportStudents = current_admin_has_permission($pdo, 'students.import');
$canExportStudents = current_admin_has_permission($pdo, 'students.export');
$canPayFeeBalance = current_admin_has_permission($pdo, 'students.pay_balance') && current_admin_has_permission($pdo, 'fees.record');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    flash('error', 'Student records are retained to protect admission numbers and school history. Use the status manager instead.');
    redirect('admin/students.php');
}

$classLevels = class_level_options();
$currentContext = current_academic_context($pdo);
sync_current_term_fee_balances($pdo);
$search = trim($_GET['search'] ?? '');
$filterClass = in_array(($_GET['class_level'] ?? ''), $classLevels, true) ? $_GET['class_level'] : '';
$genderOptions = ['Male', 'Female'];
$filterGender = in_array(($_GET['gender'] ?? ''), $genderOptions, true) ? $_GET['gender'] : '';
$statusOptions = student_enrollment_statuses();
$filterStatus = (string) ($_GET['status'] ?? 'Active');
if ($filterStatus !== 'All' && !in_array($filterStatus, $statusOptions, true)) {
    $filterStatus = 'Active';
}
// If admin is Teacher, restrict to their class only
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $filterClass = $currentAdminClass;
}
$params = [
    'balance_academic_year' => $currentContext['academic_year'],
    'balance_term' => $currentContext['term'],
];
$classLevelSql = student_class_sql_for_year('students', (string) $currentContext['academic_year']);
$where = [];

if ($filterStatus !== 'All') {
    $where[] = 'students.enrollment_status = :enrollment_status';
    $params['enrollment_status'] = $filterStatus;
}

if ($search !== '') {
    $where[] = "(students.registration_no LIKE :search_registration OR students.full_name LIKE :search_name OR {$classLevelSql} LIKE :search_class OR students.parent_name LIKE :search_parent)";
    $searchLike = '%' . $search . '%';
    $params['search_registration'] = $searchLike;
    $params['search_name'] = $searchLike;
    $params['search_class'] = $searchLike;
    $params['search_parent'] = $searchLike;
}
if ($filterClass !== '') {
    $where[] = "{$classLevelSql} = :class_level";
    $params['class_level'] = $filterClass;
}
if ($filterGender !== '') {
    $where[] = 'students.gender = :gender';
    $params['gender'] = $filterGender;
}

$sql = "SELECT
            students.*,
            {$classLevelSql} AS assignment_class_level,
            COALESCE(current_balances.required_amount, 0) AS required_amount,
            COALESCE(current_balances.paid_amount, 0) AS paid_amount,
            COALESCE(current_balances.balance, 0) AS balance
        FROM students
        LEFT JOIN fee_balances AS current_balances
          ON current_balances.student_id = students.id
         AND current_balances.academic_year = :balance_academic_year
         AND current_balances.term = :balance_term";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY {$classLevelSql} ASC, students.full_name ASC";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$students = $statement->fetchAll();
$studentsByClass = [];
foreach ($students as $student) {
    $student['class_level'] = $student['assignment_class_level'] ?? $student['class_level'];
    $student['fee_sponsorship_status'] = student_fee_sponsorship_status(
        $pdo,
        (int) $student['id'],
        (string) $currentContext['academic_year']
    );
    if ($student['fee_sponsorship_status'] === 'Sponsored Student') {
        $student['required_amount'] = 0.0;
        $student['balance'] = 0.0;
    }
    $studentsByClass[$student['class_level'] ?: 'Unassigned'][] = $student;
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Student Registration</p>
        <h1>Students</h1>
    </div>
    <div class="action-row">
        <?php if ($canExportStudents): ?>
            <a class="btn btn-outline-primary" href="<?= url('admin/export.php?type=students&status=' . rawurlencode($filterStatus)) ?>">Export Students Excel</a>
        <?php else: ?>
            <button class="btn btn-outline-primary" disabled>Export Students Excel</button>
        <?php endif; ?>

        <?php if ($canImportStudents): ?>
            <a class="btn btn-outline-primary" href="<?= url('admin/import_students.php') ?>">Import Students Excel</a>
        <?php else: ?>
            <button class="btn btn-outline-primary" disabled>Import Students Excel</button>
        <?php endif; ?>

        <?php if ($canAddStudents): ?>
            <a class="btn btn-primary" href="<?= url('admin/student_form.php') ?>">Add Student</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>
<section class="panel">
    <form class="row g-3 mb-4" action="<?= url('admin/students.php') ?>" method="get">
        <div class="col-lg-4 col-md-6">
            <label class="form-label" for="search">Search</label>
            <input id="search" class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search by registration number, student, parent, or class">
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label" for="class_level">Class Level</label>
            <select class="form-select" id="class_level" name="class_level">
                <option value="">All classes</option>
                <?php foreach ($classLevels as $classLevel): ?>
                    <option value="<?= h($classLevel) ?>" <?= $filterClass === $classLevel ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-select" id="gender" name="gender">
                <option value="">All genders</option>
                <?php foreach ($genderOptions as $genderOption): ?>
                    <option value="<?= h($genderOption) ?>" <?= $filterGender === $genderOption ? 'selected' : '' ?>><?= h($genderOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label" for="enrollment_status">Enrollment Status</label>
            <select class="form-select" id="enrollment_status" name="status">
                <option value="Active" <?= $filterStatus === 'Active' ? 'selected' : '' ?>>Active</option>
                <?php foreach (array_slice($statusOptions, 1) as $statusOption): ?>
                    <option value="<?= h($statusOption) ?>" <?= $filterStatus === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                <?php endforeach; ?>
                <option value="All" <?= $filterStatus === 'All' ? 'selected' : '' ?>>All statuses</option>
            </select>
        </div>
        <div class="col-lg-2 col-md-3 d-flex align-items-end">
            <button class="btn btn-outline-primary w-100" type="submit">Search</button>
        </div>
    </form>
</section>
<?php foreach ($studentsByClass as $classLevel => $classStudents): ?>
    <section class="panel mt-4">
        <div class="panel-heading">
            <h2><?= h($classLevel) ?></h2>
            <strong><?= count($classStudents) ?> student(s)</strong>
        </div>
            <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>Parent Name</th>
                        <th>Parent Phone</th>
                        <th>Status</th>
                        <th>Academic Year</th>
                        <th>Term</th>
                        <th>Required Amount</th>
                        <th>Paid Amount</th>
                        <th>Balance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classStudents as $student): ?>
                        <tr>
                            <td><?= h($student['registration_no']) ?></td>
                            <td>
                                <?= h($student['full_name']) ?>
                                <?php if ($student['fee_sponsorship_status'] === 'Sponsored Student'): ?>
                                    <br><span class="badge text-bg-info">Sponsored</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($student['gender']) ?></td>
                            <td><?= h($student['parent_name']) ?></td>
                            <td><?= h($student['guardian_phone']) ?></td>
                            <td><span class="badge text-bg-<?= $student['enrollment_status'] === 'Active' ? 'success' : 'secondary' ?>"><?= h($student['enrollment_status']) ?></span></td>
                            <td><?= h($currentContext['academic_year']) ?></td>
                            <td><?= h($currentContext['term']) ?></td>
                            <td><?= money((float) $student['required_amount']) ?></td>
                            <td><?= money((float) $student['paid_amount']) ?></td>
                            <td><?= money((float) $student['balance']) ?></td>
                            <td>
                                <div class="action-row">
                                    <?php if ($canViewStudents): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/student_history.php?id=' . $student['id']) ?>">History</a>
                                    <?php endif; ?>
                                    <?php if ($student['enrollment_status'] === 'Active' && $canEditStudents): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/student_form.php?id=' . $student['id']) ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($student['enrollment_status'] === 'Active' && (float) $student['balance'] > 0.005 && $canPayFeeBalance): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= url('admin/fees.php?student_id=' . $student['id']) ?>">Pay</a>
                                    <?php endif; ?>
                                    <?php if ($student['enrollment_status'] !== 'Active'): ?>
                                        <?php if ($canManageHistoricalFees && (float) $student['balance'] > 0.005): ?>
                                            <a class="btn btn-sm btn-outline-warning" href="<?= url('admin/fees.php?student_id=' . $student['id'] . '&historical=1') ?>">Pay Balance</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($canTransferStudents): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/student_status.php?id=' . $student['id']) ?>">Manage Status</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
<?php if (!$studentsByClass): ?>
    <section class="panel mt-4">No students found.</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
