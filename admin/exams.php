<?php
$pageTitle = 'Exams';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.manage_exams')) {
    flash('error', 'You do not have permission to access Exams.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
$currentContext = current_academic_context($pdo);
$academicYear = preg_match('/^\d{4}$/', $_GET['academic_year'] ?? ($_POST['academic_year'] ?? '')) ? ($_GET['academic_year'] ?? $_POST['academic_year']) : $currentContext['academic_year'];
$term = in_array($_GET['term'] ?? ($_POST['term'] ?? ''), term_options(), true) ? ($_GET['term'] ?? $_POST['term']) : $currentContext['term'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_exam') {
    $examId = (int) ($_POST['exam_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['Open', 'Closed', 'Published'], true) ? $_POST['status'] : 'Open';
    $maxMarks = is_numeric($_POST['max_marks'] ?? '') ? (float) $_POST['max_marks'] : 100.0;
    $maxMarks = max(1, min(100, $maxMarks));

    $statement = $pdo->prepare(
        "UPDATE academic_exams
         SET status = :status,
             max_marks = :max_marks,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $statement->execute(['status' => $status, 'max_marks' => $maxMarks, 'id' => $examId]);
    flash('success', 'Exam updated successfully.');
    redirect('admin/exams.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term));
}

foreach (term_options() as $termOption) {
    ensure_academic_exams_for_period($pdo, $academicYear, $termOption);
}
$exams = academic_exams_for_period($pdo, $academicYear, $term);

$examStats = [];
$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM academic_marks
     WHERE exam_id = :exam_id"
);
foreach ($exams as $exam) {
    $countStatement->execute(['exam_id' => $exam['id']]);
    $examStats[(int) $exam['id']] = (int) $countStatement->fetchColumn();
}

$yearMatrix = [];
foreach (term_options() as $termOption) {
    $yearMatrix[$termOption] = academic_exams_for_period($pdo, $academicYear, $termOption);
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Exams</h1>
        <p class="mb-0 text-muted">Opening, Midterm, and Closing exams are created for every academic year and term.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('admin/marks_entry.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>">
        <i class="fa-solid fa-pen-to-square me-2"></i>Enter Marks
    </a>
</div>

<?php render_academic_module_nav(); ?>

<section class="panel mb-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Academic Year</label>
            <input class="form-control" name="academic_year" value="<?= h($academicYear) ?>" pattern="\d{4}" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Term</label>
            <select class="form-select" name="term" required>
                <?php foreach (term_options() as $termOption): ?>
                    <option value="<?= h($termOption) ?>" <?= $term === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-primary w-100" type="submit">Load Exams</button>
        </div>
    </form>
</section>

<div class="row g-4 mb-4">
    <?php foreach ($exams as $exam): ?>
        <div class="col-lg-4">
            <section class="panel h-100">
                <div class="panel-heading">
                    <div>
                        <h2><?= h($exam['exam_name']) ?></h2>
                        <p class="panel-subtitle"><?= h($academicYear) ?> <?= h($term) ?></p>
                    </div>
                    <span class="status-pill <?= $exam['status'] === 'Published' ? 'status-success' : ($exam['status'] === 'Closed' ? 'status-warning' : 'status-info') ?>"><?= h($exam['status']) ?></span>
                </div>
                <div class="academic-exam-stat">
                    <strong><?= h((string) ($examStats[(int) $exam['id']] ?? 0)) ?></strong>
                    <span>marks entries</span>
                </div>
                <form method="post" class="row g-3 mt-1">
                    <input type="hidden" name="action" value="update_exam">
                    <input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                    <input type="hidden" name="academic_year" value="<?= h($academicYear) ?>">
                    <input type="hidden" name="term" value="<?= h($term) ?>">
                    <div class="col-sm-6">
                        <label class="form-label">Max Marks</label>
                        <input class="form-control" type="number" min="1" max="100" step="0.01" name="max_marks" value="<?= h(number_format((float) $exam['max_marks'], 2, '.', '')) ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach (['Open', 'Closed', 'Published'] as $status): ?>
                                <option value="<?= h($status) ?>" <?= $exam['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary w-100" type="submit">Update Exam</button>
                    </div>
                </form>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2><?= h($academicYear) ?> Exam Structure</h2>
            <p class="panel-subtitle">Every term carries the same fixed exam sequence.</p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Term</th>
                    <th>Opening Exam</th>
                    <th>Midterm Exam</th>
                    <th>Closing Exam</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($yearMatrix as $termName => $rows): ?>
                    <tr>
                        <td><strong><?= h($termName) ?></strong></td>
                        <?php foreach ($rows as $row): ?>
                            <td>
                                <span class="status-pill <?= $row['status'] === 'Published' ? 'status-success' : ($row['status'] === 'Closed' ? 'status-warning' : 'status-info') ?>">
                                    <?= h($row['status']) ?>
                                </span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
