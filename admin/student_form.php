<?php
$pageTitle = 'Student Form';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/parent_registration_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

// Module access check
if (!current_admin_has_permission($pdo, 'students.access')) {
    flash('error', 'You do not have permission to access Students.');
    redirect('admin/dashboard.php');
}

if (!parent_registration_schema_ready($pdo)) {
    require_once __DIR__ . '/../includes/admin_header.php';
    ?>
    <div class="page-title"><div><p class="eyebrow">Student Registration</p><h1>Parent registration update required</h1></div></div>
    <div class="alert alert-warning">Apply <code>database/migrations/20261018_parent_registration.sql</code> before adding or editing students so duplicate form submissions can be safely detected.</div>
    <?php
    require_once __DIR__ . '/../includes/layout_end.php';
    exit;
}

$studentId = (int) ($_GET['id'] ?? 0);
$currentContext = current_academic_context($pdo);
$academicYear = $currentContext['academic_year'];
$student = [
    'registration_no' => '',
    'full_name' => '',
    'gender' => '',
    'class_level' => '',
    'student_type' => 'Normal Student',
    'fee_sponsorship_status' => 'Regular Student',
    'parent_name' => '',
    'guardian_phone' => '',
    'guardian_alternate_phone' => '',
    'guardian_email' => '',
];
$errors = [];
$historicalClassEdit = false;
$classLevels = class_level_options();
$genderOptions = gender_options();
$studentTypeOptions = student_type_options();

function student_form_discount_rows(PDO $pdo, int $studentId, ?array $structure, string $academicYear, array $postedDiscountPercentages = [], array $postedDiscountedFees = []): array
{
    $rows = [];

    foreach (term_options() as $term) {
        $originalFee = $structure ? fee_required_amount_for_term($structure, $term) : 0.0;
        $savedDiscount = $studentId > 0 ? fetch_student_fee_discount($pdo, $studentId, $academicYear, $term) : null;
        $discountPercentage = array_key_exists($term, $postedDiscountPercentages)
            ? (string) $postedDiscountPercentages[$term]
            : (string) ($savedDiscount['discount_percentage'] ?? '50');
        $discountedFee = array_key_exists($term, $postedDiscountedFees)
            ? (string) $postedDiscountedFees[$term]
            : (string) ($savedDiscount['discounted_fee'] ?? default_discounted_fee($originalFee, (float) $discountPercentage));

        $rows[$term] = [
            'term' => $term,
            'original_fee' => $originalFee,
            'discount_percentage' => $discountPercentage,
            'discounted_fee' => $discountedFee,
        ];
    }

    return $rows;
}

if ($studentId > 0) {
    $statement = $pdo->prepare("SELECT * FROM students WHERE id = :id");
    $statement->execute(['id' => $studentId]);
    $existingStudent = $statement->fetch();
    if (!$existingStudent) {
        flash('error', 'The student record was not found.');
        redirect('admin/students.php');
    }
    $existingStudent['class_level'] = student_class_for_year(
        $pdo,
        $studentId,
        $academicYear,
        (string) ($existingStudent['class_level'] ?? '')
    );
    $existingStudent['fee_sponsorship_status'] = student_fee_sponsorship_status($pdo, $studentId, $academicYear);
    if (!student_status_is_active((string) ($existingStudent['enrollment_status'] ?? 'Active'))) {
        redirect('admin/student_history.php?id=' . $studentId);
    }
    $student = $existingStudent;
}

if (!isset($_SESSION['student_registration_form_token']) || !is_string($_SESSION['student_registration_form_token'])) {
    $_SESSION['student_registration_form_token'] = bin2hex(random_bytes(32));
}
$submissionToken = (string) $_SESSION['student_registration_form_token'];

$selectedClassLevel = $_POST['class_level'] ?? ($student['class_level'] ?? '');
$selectedStudentType = $_POST['student_type'] ?? ($student['student_type'] ?? 'Normal Student');
$selectedStructure = in_array($selectedClassLevel, $classLevels, true) ? get_fee_structure($pdo, $selectedClassLevel, $academicYear) : null;
$discountRows = student_form_discount_rows(
    $pdo,
    $studentId,
    $selectedStructure,
    $academicYear,
    $_POST['discount_percentage'] ?? [],
    $_POST['discounted_fee'] ?? []
);

// Quick permission flags
$canAddStudents = current_admin_has_permission($pdo, 'students.add');
$canEditStudents = current_admin_has_permission($pdo, 'students.edit');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($studentId === 0) {
        $submissionToken = trim((string) ($_POST['registration_token'] ?? ''));
    }
    $data = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'gender' => trim($_POST['gender'] ?? ''),
        'class_level' => trim($_POST['class_level'] ?? ''),
        'student_type' => trim($_POST['student_type'] ?? ''),
        'fee_sponsorship_status' => trim($_POST['fee_sponsorship_status'] ?? 'Regular Student'),
        'parent_name' => trim($_POST['parent_name'] ?? ''),
        'guardian_phone' => trim($_POST['guardian_phone'] ?? ''),
        'guardian_alternate_phone' => trim($_POST['guardian_alternate_phone'] ?? ''),
        'guardian_email' => trim($_POST['guardian_email'] ?? ''),
    ];

    if ($data['full_name'] === '') {
        $errors[] = 'Student name is required.';
    }
    if (!in_array($data['gender'], $genderOptions, true)) {
        $errors[] = 'Please choose a valid gender.';
    }
    if ($data['class_level'] === '') {
        $errors[] = 'Class level is required.';
    }
    if ($data['class_level'] !== '' && !in_array($data['class_level'], $classLevels, true)) {
        $errors[] = 'Please choose a valid class level.';
    }
    if (!in_array($data['student_type'], $studentTypeOptions, true)) {
        $errors[] = 'Please choose a valid student type.';
    }
    if (!in_array($data['fee_sponsorship_status'], ['Regular Student', 'Sponsored Student'], true)) {
        $errors[] = 'Please choose a valid fee sponsorship status.';
    }
    if ($data['parent_name'] === '') {
        $errors[] = 'Parent name is required.';
    }
    if (mb_strlen($data['parent_name']) > 100) {
        $errors[] = 'Parent name must be 100 characters or fewer.';
    }
    if ($data['guardian_phone'] === '' || parent_registration_normalize_phone($data['guardian_phone']) === null) {
        $errors[] = 'A valid primary parent phone number is required.';
    }
    if ($data['guardian_alternate_phone'] !== '' && (mb_strlen($data['guardian_alternate_phone']) > 40 || parent_registration_normalize_phone($data['guardian_alternate_phone']) === null)) {
        $errors[] = 'Enter a valid alternative parent phone number or leave it blank.';
    }
    if ($data['guardian_email'] !== '' && (mb_strlen($data['guardian_email']) > 190 || filter_var($data['guardian_email'], FILTER_VALIDATE_EMAIL) === false)) {
        $errors[] = 'Enter a valid parent email address or leave it blank.';
    }
    if (!$errors) {
        if ($studentId === 0) {
            $existingIdentity = find_existing_student_identity($pdo, $data['full_name'], $data['guardian_phone']);
            if ($existingIdentity) {
                $errors[] = 'This student already exists as ' . $existingIdentity['registration_no'] . ' (' . $existingIdentity['enrollment_status'] . '). Reactivate that record instead of creating a new admission number.';
            }
        }
    }

    $feeStructure = !$errors ? get_fee_structure($pdo, $data['class_level'], $academicYear) : null;
    if (!$errors && $data['fee_sponsorship_status'] === 'Regular Student' && !$feeStructure) {
        $errors[] = "Set up the {$academicYear} fee structure for {$data['class_level']} before adding this student.";
    }

    $discountInputs = [];
    if (!$errors && $data['fee_sponsorship_status'] === 'Regular Student' && is_teacher_child_type($data['student_type'])) {
        foreach (term_options() as $term) {
            $discountValue = trim((string) ($_POST['discount_percentage'][$term] ?? '50'));
            $discountedValue = trim((string) ($_POST['discounted_fee'][$term] ?? ''));
            $originalFee = fee_required_amount_for_term($feeStructure, $term);

            if (!valid_money_value($discountValue) || (float) $discountValue > 100) {
                $errors[] = "{$term} discount must be between 0 and 100.";
                continue;
            }

            $discountPercentage = (float) $discountValue;
            if ($discountedValue === '') {
                $discountedFee = default_discounted_fee($originalFee, $discountPercentage);
            } elseif (!valid_money_value($discountedValue)) {
                $errors[] = "{$term} discounted fee must be zero or more.";
                continue;
            } else {
                $discountedFee = (float) $discountedValue;
            }

            if ($discountedFee > $originalFee + 0.005) {
                $errors[] = "{$term} discounted fee cannot exceed the normal fee.";
                continue;
            }

            $discountInputs[$term] = [
                'original_fee' => $originalFee,
                'discount_percentage' => $discountPercentage,
                'discounted_fee' => $discountedFee,
            ];
        }
    }

    // enforce per-action permission
    if ($studentId > 0 && !$canEditStudents) {
        flash('error', 'You do not have permission to edit students.');
        redirect('admin/students.php');
    }
    if ($studentId === 0 && !$canAddStudents) {
        flash('error', 'You do not have permission to add students.');
        redirect('admin/students.php');
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($studentId > 0) {
                $historicalClassEdit = student_class_assignment_is_historical($pdo, $studentId, $academicYear);
                $studentFields = ['full_name', 'gender', 'student_type', 'fee_sponsorship_status', 'parent_name', 'guardian_phone', 'guardian_alternate_phone', 'guardian_email'];
                if (!$historicalClassEdit) {
                    $studentFields[] = 'class_level';
                }
                $studentData = array_intersect_key($data, array_flip($studentFields));
                $studentData['id'] = $studentId;
                $classUpdate = $historicalClassEdit ? '' : 'class_level = :class_level, ';
                $statement = $pdo->prepare(
                    "UPDATE students
                     SET full_name = :full_name,
                         gender = :gender,
                         {$classUpdate}
                         student_type = :student_type,
                         fee_sponsorship_status = :fee_sponsorship_status,
                         parent_name = :parent_name,
                         guardian_phone = :guardian_phone,
                         guardian_alternate_phone = :guardian_alternate_phone,
                         guardian_email = :guardian_email
                     WHERE id = :id"
                );
                $statement->execute($studentData);
                $savedStudentId = $studentId;
            } else {
                if (!preg_match('/^[a-f0-9]{64}$/', $submissionToken)) {
                    throw new RuntimeException('Invalid registration request token.');
                }
                $tokenHash = hash('sha256', $submissionToken);
                $processed = $pdo->prepare('SELECT student_id FROM student_registration_tokens WHERE token_hash = :token_hash LIMIT 1');
                $processed->execute(['token_hash' => $tokenHash]);
                $alreadyProcessedId = (int) ($processed->fetchColumn() ?: 0);
                if ($alreadyProcessedId > 0) {
                    $pdo->rollBack();
                    flash('success', 'This registration was already saved. No duplicate student record was created.');
                    redirect('admin/students.php');
                }
                $data['registration_no'] = generate_registration_no($pdo);
                $statement = $pdo->prepare(
                    "INSERT INTO students (registration_no, full_name, gender, class_level, admitted_academic_year, student_type, fee_sponsorship_status, parent_name, guardian_phone, guardian_alternate_phone, guardian_email)
                     VALUES (:registration_no, :full_name, :gender, :class_level, :admitted_academic_year, :student_type, :fee_sponsorship_status, :parent_name, :guardian_phone, :guardian_alternate_phone, :guardian_email)"
                );
                $studentData = array_intersect_key($data, array_flip(['registration_no', 'full_name', 'gender', 'class_level', 'student_type', 'fee_sponsorship_status', 'parent_name', 'guardian_phone', 'guardian_alternate_phone', 'guardian_email']));
                $studentData['admitted_academic_year'] = $academicYear;
                $statement->execute($studentData);
                $savedStudentId = (int) $pdo->lastInsertId();
            }

            student_class_assignment_set(
                $pdo,
                $savedStudentId,
                $academicYear,
                $data['class_level'],
                'manual',
                (int) ($_SESSION['admin_id'] ?? 0)
            );

            $sponsorshipHistory = $pdo->prepare(
                'INSERT INTO student_fee_sponsorship_history (student_id, academic_year, sponsorship_status, changed_by)
                 VALUES (:student_id, :academic_year, :sponsorship_status, :changed_by)
                 ON DUPLICATE KEY UPDATE sponsorship_status = VALUES(sponsorship_status), changed_by = VALUES(changed_by), updated_at = CURRENT_TIMESTAMP'
            );
            $sponsorshipHistory->execute([
                'student_id' => $savedStudentId,
                'academic_year' => $academicYear,
                'sponsorship_status' => $data['fee_sponsorship_status'],
                'changed_by' => (int) ($_SESSION['admin_id'] ?? 0) ?: null,
            ]);

            if (is_teacher_child_type($data['student_type'])) {
                foreach ($discountInputs as $term => $discountInput) {
                    save_student_fee_discount(
                        $pdo,
                        $savedStudentId,
                        $academicYear,
                        $term,
                        $discountInput['original_fee'],
                        $discountInput['discount_percentage'],
                        $discountInput['discounted_fee']
                    );
                }
            } else {
                remove_student_fee_discounts($pdo, $savedStudentId, $academicYear);
            }

            sync_fee_balance_for_student($pdo, $savedStudentId, $data['class_level'], $academicYear, $currentContext['term']);
            if ($studentId === 0) {
                $tokenInsert = $pdo->prepare(
                    'INSERT INTO student_registration_tokens (token_hash, student_id) VALUES (:token_hash, :student_id)'
                );
                $tokenInsert->execute(['token_hash' => $tokenHash, 'student_id' => $savedStudentId]);
            }
            $pdo->commit();
            if ($studentId === 0) {
                $_SESSION['student_registration_form_token'] = bin2hex(random_bytes(32));
            }
            flash('success', $studentId > 0
                ? ($historicalClassEdit
                    ? 'Student details updated for ' . $academicYear . '. The current-year class assignment was left unchanged.'
                    : 'Student details updated successfully.')
                : ($data['fee_sponsorship_status'] === 'Sponsored Student'
                    ? 'Student ' . $data['registration_no'] . ' registered as sponsored. No school fee balance was created.'
                    : 'Student ' . $data['registration_no'] . ' registered. Fee balances were created automatically.'));
            redirect('admin/students.php');
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($studentId === 0 && isset($tokenHash)) {
                $processed = $pdo->prepare('SELECT student_id FROM student_registration_tokens WHERE token_hash = :token_hash LIMIT 1');
                $processed->execute(['token_hash' => $tokenHash]);
                if ((int) ($processed->fetchColumn() ?: 0) > 0) {
                    flash('success', 'This registration was already saved. No duplicate student record was created.');
                    redirect('admin/students.php');
                }
            }
            error_log('Student save failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to save the student. Please try again or contact the system administrator.';
        }
    }

    $student = array_merge($student, $data);
    $discountRows = student_form_discount_rows(
        $pdo,
        $studentId,
        $feeStructure,
        $academicYear,
        $_POST['discount_percentage'] ?? [],
        $_POST['discounted_fee'] ?? []
    );
}

$feeStructureMap = [];
$structureRows = $pdo->prepare("SELECT * FROM fee_structures WHERE academic_year = :academic_year");
$structureRows->execute(['academic_year' => $academicYear]);
foreach ($structureRows->fetchAll() as $structureRow) {
    $feeStructureMap[$structureRow['class_level']] = [
        'Term 1' => (float) $structureRow['term1_total'],
        'Term 2' => (float) $structureRow['term2_total'],
        'Term 3' => (float) $structureRow['term3_total'],
    ];
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Student Registration</p>
        <h1><?= $studentId > 0 ? 'Edit Student' : 'Add Student' ?></h1>
    </div>
    <div class="action-row">
        <?php if ($studentId === 0 && current_admin_has_permission($pdo, 'students.import')): ?>
            <a class="btn btn-outline-primary" href="#import-students">Import Students Excel</a>
        <?php elseif ($studentId === 0): ?>
            <button class="btn btn-outline-primary" disabled>Import Students Excel</button>
        <?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Back to Students</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel student-registration-panel">
    <div class="panel-heading">
        <h2>Student Details</h2>
        <span class="text-muted">Balances use <?= h($academicYear . ' ' . $currentContext['term']) ?></span>
    </div>
    <form class="row g-3" method="post">
        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
        <?php if ($studentId === 0): ?><input type="hidden" name="registration_token" value="<?= h($submissionToken) ?>"><?php endif; ?>
        <div class="col-md-4">
            <label class="form-label" for="registration_no">Registration Number</label>
            <input class="form-control" id="registration_no" value="<?= h((string) ($student['registration_no'] ?: 'Assigned automatically after saving')) ?>" readonly>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="full_name">Student Name</label>
            <input class="form-control" id="full_name" name="full_name" value="<?= h((string) $student['full_name']) ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-select" id="gender" name="gender" required>
                <option value="">Choose gender...</option>
                <?php foreach ($genderOptions as $gender): ?>
                    <option value="<?= h($gender) ?>" <?= ((string) ($student['gender'] ?? '') === $gender) ? 'selected' : '' ?>><?= h($gender) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="class_level">Class Level</label>
            <div class="input-group">
                <select class="form-select" id="class_level" name="class_level" required>
                    <option value="">Choose class...</option>
                    <?php foreach ($classLevels as $classLevel): ?>
                        <option value="<?= h($classLevel) ?>" <?= ((string) $student['class_level'] === $classLevel) ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="btn btn-outline-secondary" href="<?= url('admin/class_levels.php') ?>">New</a>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="student_type">Student Type</label>
            <select class="form-select" id="student_type" name="student_type" required>
                <?php foreach ($studentTypeOptions as $studentType): ?>
                    <option value="<?= h($studentType) ?>" <?= ((string) ($student['student_type'] ?? 'Normal Student') === $studentType) ? 'selected' : '' ?>><?= h($studentType) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="fee_sponsorship_status">Fee Sponsorship Status</label>
            <select class="form-select" id="fee_sponsorship_status" name="fee_sponsorship_status" required>
                <?php foreach (['Regular Student', 'Sponsored Student'] as $sponsorshipStatus): ?>
                    <option value="<?= h($sponsorshipStatus) ?>" <?= ((string) ($student['fee_sponsorship_status'] ?? 'Regular Student') === $sponsorshipStatus) ? 'selected' : '' ?>><?= h($sponsorshipStatus) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Sponsorship applies from <?= h($academicYear) ?> onward; earlier year records remain unchanged.</small>
        </div>
        <div class="col-12"><hr class="my-1"><h3 class="h5 mb-0">Parent or Guardian</h3></div>
        <div class="col-md-6">
            <label class="form-label" for="parent_name">Full name</label>
            <input class="form-control" id="parent_name" name="parent_name" maxlength="100" value="<?= h((string) ($student['parent_name'] ?? '')) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="guardian_phone">Primary phone</label>
            <input class="form-control" id="guardian_phone" name="guardian_phone" maxlength="40" autocomplete="tel" value="<?= h((string) ($student['guardian_phone'] ?? '')) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="guardian_alternate_phone">Alternative phone <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="guardian_alternate_phone" name="guardian_alternate_phone" maxlength="40" autocomplete="tel-national" value="<?= h((string) ($student['guardian_alternate_phone'] ?? '')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="guardian_email">Email address <span class="text-muted">(optional)</span></label>
            <input class="form-control" type="email" id="guardian_email" name="guardian_email" maxlength="190" autocomplete="email" value="<?= h((string) ($student['guardian_email'] ?? '')) ?>">
        </div>
        <div class="col-12" id="teacher_child_fee_panel">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th>Original Fee</th>
                            <th>Discount %</th>
                            <th>Discounted Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discountRows as $term => $discountRow): ?>
                            <tr>
                                <td><?= h($term) ?></td>
                                <td>
                                    <input class="form-control original-fee-input" value="<?= h(number_format((float) $discountRow['original_fee'], 2, '.', '')) ?>" data-term="<?= h($term) ?>" readonly>
                                </td>
                                <td>
                                    <input class="form-control discount-percentage-input" type="number" min="0" max="100" step="0.01" name="discount_percentage[<?= h($term) ?>]" value="<?= h((string) $discountRow['discount_percentage']) ?>" data-term="<?= h($term) ?>">
                                </td>
                                <td>
                                    <input class="form-control discounted-fee-input" type="number" min="0" step="0.01" name="discounted_fee[<?= h($term) ?>]" value="<?= h((string) $discountRow['discounted_fee']) ?>" data-term="<?= h($term) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Save Student</button>
        </div>
    </form>
</section>
<script>
window.studentFeeStructures = <?= json_encode($feeStructureMap) ?>;
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('student_type');
    const classSelect = document.getElementById('class_level');
    const panel = document.getElementById('teacher_child_fee_panel');
    const normalType = 'Normal Student';
    const teacherType = 'Teacher Child';

    function formatMoneyValue(value) {
        return Number(value || 0).toFixed(2);
    }

    function refreshDiscountRows(recalculateDiscounted) {
        const fees = window.studentFeeStructures[classSelect.value] || {};
        document.querySelectorAll('.original-fee-input').forEach(function (input) {
            const term = input.dataset.term;
            const originalFee = Number(fees[term] || input.value || 0);
            input.value = formatMoneyValue(originalFee);

            if (recalculateDiscounted) {
                const discountInput = document.querySelector('.discount-percentage-input[data-term="' + term + '"]');
                const discountedInput = document.querySelector('.discounted-fee-input[data-term="' + term + '"]');
                const discount = Number(discountInput ? discountInput.value : 50);
                if (discountedInput) {
                    discountedInput.value = formatMoneyValue(Math.max(originalFee * ((100 - discount) / 100), 0));
                }
            }
        });
    }

    function refreshPanel() {
        const isTeacherChild = typeSelect.value === teacherType;
        panel.style.display = isTeacherChild ? '' : 'none';
        panel.querySelectorAll('input').forEach(function (input) {
            input.disabled = !isTeacherChild;
        });
        if (isTeacherChild) {
            refreshDiscountRows(false);
        }
    }

    typeSelect.addEventListener('change', function () {
        refreshPanel();
        if (typeSelect.value === teacherType) {
            refreshDiscountRows(true);
        }
    });
    classSelect.addEventListener('change', function () {
        refreshDiscountRows(true);
    });
    document.querySelectorAll('.discount-percentage-input').forEach(function (input) {
        input.addEventListener('change', function () {
            refreshDiscountRows(true);
        });
    });
    if (!typeSelect.value) {
        typeSelect.value = normalType;
    }
    refreshPanel();
});
</script>
<?php if ($studentId === 0): ?>
    <section class="panel mt-4" id="import-students">
        <div class="panel-heading">
            <h2>Import Students Excel</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/import_students.php?download_template=1') ?>">Download Sample Template</a>
        </div>
        <form class="row g-3 align-items-end" method="post" action="<?= url('admin/import_students.php') ?>" enctype="multipart/form-data">
            <div class="col-lg-8">
                <label class="form-label" for="students_file">Excel File (.xlsx)</label>
                <input class="form-control" type="file" id="students_file" name="students_file" accept=".xlsx" required>
            </div>
            <div class="col-lg-4">
                <button class="btn btn-primary w-100" type="submit">Import Students</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
