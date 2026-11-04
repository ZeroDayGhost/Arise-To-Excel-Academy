<?php
$pageTitle = 'Parent Accounts';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parent_portal_helpers.php';
require_once __DIR__ . '/../includes/parent_registration_helpers.php';

if (!current_admin_has_permission($pdo, 'users_permissions.access')) {
    flash('error', 'You do not have permission to manage parent accounts.');
    redirect('admin/dashboard.php');
}

if (!parent_portal_schema_ready($pdo) || !parent_registration_schema_ready($pdo)) {
    require_once __DIR__ . '/../includes/admin_header.php';
    ?>
    <div class="page-title"><div><p class="eyebrow">Family Access</p><h1>Parent Accounts</h1></div></div>
    <div class="alert alert-warning">Apply <code>database/migrations/20261017_parent_portal.sql</code> and <code>database/migrations/20261018_parent_registration.sql</code>, then reload this page.</div>
    <?php
    require_once __DIR__ . '/../includes/layout_end.php';
    exit;
}

$actorId = (int) ($_SESSION['admin_id'] ?? 0);
ensure_permissions_exist($pdo);
$handoffData = null;
$guardianGroups = [];
$guardianRows = $pdo->query(
    "SELECT id, full_name, registration_no, class_level, parent_name, guardian_phone
     FROM students
     WHERE TRIM(COALESCE(parent_name, '')) <> ''
     ORDER BY parent_name, full_name"
)->fetchAll();
foreach ($guardianRows as $guardianRow) {
    $guardianName = trim((string) $guardianRow['parent_name']);
    $guardianPhone = trim((string) ($guardianRow['guardian_phone'] ?? ''));
    $normalizedPhone = parent_registration_normalize_phone($guardianPhone);
    if ($guardianName === '' || $normalizedPhone === null) {
        continue;
    }
    $groupKey = hash('sha256', parent_registration_normalize_name($guardianName) . '|' . $normalizedPhone);
    if (!isset($guardianGroups[$groupKey])) {
        $guardianGroups[$groupKey] = [
            'name' => $guardianName,
            'phone' => $guardianPhone,
            'children' => [],
        ];
    }
    $guardianGroups[$groupKey]['children'][] = [
        'id' => (int) $guardianRow['id'],
        'name' => (string) $guardianRow['full_name'],
        'registration_no' => (string) $guardianRow['registration_no'],
        'class_level' => (string) $guardianRow['class_level'],
    ];
}
$guardianOptions = [];
$guardiansByStudentId = [];
foreach ($guardianGroups as $guardianGroup) {
    $guardianOptions[] = $guardianGroup;
    foreach ($guardianGroup['children'] as $child) {
        $guardiansByStudentId[(int) $child['id']] = $guardianGroup;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'handoff_guardian') {
            $selectedStudentId = (int) ($_POST['student_id'] ?? 0);
            $guardian = $guardiansByStudentId[$selectedStudentId] ?? null;
            if (!$guardian) {
                throw new InvalidArgumentException('Choose a student with registered parent contact details.');
            }
            $submittedStudentIds = $_POST['student_ids'] ?? [];
            if (!is_array($submittedStudentIds)) {
                throw new InvalidArgumentException('Choose at least one child to connect to the parent account.');
            }
            $allowedStudentIds = array_map(static fn(array $child): int => (int) $child['id'], $guardian['children']);
            $selectedStudentIds = [];
            foreach ($submittedStudentIds as $submittedStudentId) {
                if (!is_scalar($submittedStudentId) || !ctype_digit((string) $submittedStudentId)) {
                    throw new InvalidArgumentException('The selected child list is invalid. Choose the parent again.');
                }
                $studentId = (int) $submittedStudentId;
                if (!in_array($studentId, $allowedStudentIds, true)) {
                    throw new InvalidArgumentException('A selected child does not belong to this parent record. Choose the parent again.');
                }
                $selectedStudentIds[$studentId] = $studentId;
            }
            if (!$selectedStudentIds) {
                throw new InvalidArgumentException('Choose at least one child to connect to the parent account.');
            }

            $parentName = (string) $guardian['name'];
            $parentPhone = (string) $guardian['phone'];
            $details = [
                'parent_name' => $parentName,
                'parent_phone' => $parentPhone,
                'alternate_phone' => '',
                'parent_email' => '',
                'parent_address' => '',
            ];
            $phoneCandidates = parent_registration_phone_candidates(
                $pdo,
                (string) parent_registration_normalize_phone($parentPhone)
            );
            $matchedParentId = parent_registration_auto_match($phoneCandidates, $parentName);
            if ($phoneCandidates && $matchedParentId === null) {
                throw new InvalidArgumentException('A parent account already uses this phone number, but the identity cannot be matched safely. Verify the existing account before continuing.');
            }
            if (!$phoneCandidates) {
                foreach (parent_registration_accounts($pdo) as $existingParent) {
                    if (parent_registration_normalize_name((string) $existingParent['name']) === parent_registration_normalize_name($parentName)) {
                        throw new InvalidArgumentException('A parent account with this name already exists. Use the existing account controls to link the children instead of creating a duplicate.');
                    }
                }
            }

            $pdo->beginTransaction();
            $parentResult = parent_registration_resolve(
                $pdo,
                $actorId,
                $details,
                $matchedParentId ?? 0,
                false,
                false,
                $phoneCandidates
            );
            $parentId = (int) $parentResult['parent_user_id'];
            $parentStateQuery = $pdo->prepare(
                'SELECT a.username, a.status, p.activated_at
                 FROM admin a JOIN parent_profiles p ON p.parent_user_id = a.id
                 WHERE a.id = :id LIMIT 1'
            );
            $parentStateQuery->execute(['id' => $parentId]);
            $parentState = $parentStateQuery->fetch();
            if (!$parentState) {
                throw new InvalidArgumentException('The parent account could not be prepared. Contact the school administrator.');
            }
            if ($parentState['status'] !== 'Active' && !empty($parentState['activated_at'])) {
                throw new InvalidArgumentException('This parent account was already activated and then disabled. Re-enable it from the account list before linking children.');
            }

            $activationCode = $parentResult['activation_token'];
            if (!$parentResult['created'] && $parentState['status'] !== 'Active') {
                $activationCode = parent_registration_issue_activation($pdo, $actorId, $parentId);
            }
            foreach ($selectedStudentIds as $studentId) {
                $existingLink = $pdo->prepare(
                    'SELECT id FROM parent_student_links
                     WHERE parent_user_id = :parent_id AND student_id = :student_id AND is_active = 1 LIMIT 1'
                );
                $existingLink->execute(['parent_id' => $parentId, 'student_id' => $studentId]);
                if (!$existingLink->fetchColumn()) {
                    parent_portal_link_child($pdo, $actorId, $parentId, $studentId, 'Parent/Guardian', false, true);
                }
            }
            $pdo->commit();

            if ($parentState['status'] !== 'Active' && is_string($activationCode) && $activationCode !== '') {
                $handoffData = [
                    'username' => (string) $parentState['username'],
                    'activation_code' => $activationCode,
                    'parent_name' => $parentName,
                ];
            } else {
                flash('success', 'The children are linked to this active parent account. No new password setup is needed.');
            }
        } elseif ($action === 'link_student') {
            $parentId = (int) ($_POST['parent_user_id'] ?? 0);
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $relationshipType = trim((string) ($_POST['relationship_type'] ?? ''));
            if (mb_strlen($relationshipType) > 50) {
                throw new InvalidArgumentException('Relationship must be 50 characters or fewer.');
            }
            parent_portal_link_child($pdo, $actorId, $parentId, $studentId, $relationshipType !== '' ? $relationshipType : null);
            flash('success', 'Child linked to the parent account.');
        } elseif ($action === 'unlink_student') {
            $linkId = (int) ($_POST['link_id'] ?? 0);
            parent_portal_unlink_child($pdo, $actorId, $linkId);
            flash('success', 'The parent-child link has been revoked. Access is removed immediately.');
        } elseif ($action === 'delete_student_link') {
            $linkId = (int) ($_POST['link_id'] ?? 0);
            parent_portal_delete_link($pdo, $actorId, $linkId);
            flash('success', 'The parent-child link was deleted. The parent account and student were not changed.');
        } elseif ($action === 'set_account_status') {
            $parentId = (int) ($_POST['parent_user_id'] ?? 0);
            $status = ($_POST['status'] ?? '') === 'Active' ? 'Active' : 'Inactive';
            if (!parent_portal_is_parent_account($pdo, $parentId, false)) {
                throw new InvalidArgumentException('The selected account is not assigned only the Parent role.');
            }
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE admin SET status = :status WHERE id = :id');
            $update->execute(['status' => $status, 'id' => $parentId]);
            parent_portal_audit($pdo, $actorId, $parentId, null, $status === 'Active' ? 'account_enabled' : 'account_disabled');
            $pdo->commit();
            flash('success', $status === 'Active' ? 'Parent account activated.' : 'Parent account disabled.');
        } elseif ($action === 'reset_password') {
            $parentId = (int) ($_POST['parent_user_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            if (!parent_portal_is_parent_account($pdo, $parentId, false)) {
                throw new InvalidArgumentException('The selected account is not assigned only the Parent role.');
            }
            if (strlen($password) < 12 || $password !== $confirm) {
                throw new InvalidArgumentException('Use a password of at least 12 characters and enter it identically in both fields.');
            }
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE admin SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $parentId]);
            parent_portal_audit($pdo, $actorId, $parentId, null, 'password_reset');
            $pdo->commit();
            flash('success', 'Parent password reset successfully.');
        } elseif ($action === 'handoff_activation') {
            $parentId = (int) ($_POST['parent_user_id'] ?? 0);
            $pdo->beginTransaction();
            $activationCode = parent_registration_issue_activation($pdo, $actorId, $parentId);
            $account = $pdo->prepare('SELECT username, name FROM admin WHERE id = :id LIMIT 1');
            $account->execute(['id' => $parentId]);
            $parentAccount = $account->fetch();
            if (!$parentAccount) {
                throw new InvalidArgumentException('The selected parent account could not be found.');
            }
            $pdo->commit();
            $handoffData = [
                'username' => (string) $parentAccount['username'],
                'activation_code' => $activationCode,
                'parent_name' => (string) $parentAccount['name'],
            ];
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Parent account management failed with code ' . (string) $exception->getCode());
        flash('error', 'The requested change could not be completed. Check the account details and try again.');
    }
    if ($handoffData !== null) {
        parent_registration_start_handoff(
            (string) $handoffData['username'],
            (string) $handoffData['activation_code'],
            (string) $handoffData['parent_name']
        );
        redirect('parent/activate.php?handoff=1');
    }
    redirect('admin/parent_accounts.php');
}

$parents = $pdo->query(
    "SELECT a.id, a.name, a.username, a.status, MAX(pp.activated_at) AS activated_at,
            MAX(pp.parent_user_id) AS has_profile,
            GROUP_CONCAT(DISTINCT COALESCE(r.role_name, r.name) ORDER BY COALESCE(r.role_name, r.name) SEPARATOR ', ') AS role_names,
            GROUP_CONCAT(DISTINCT CASE WHEN pl.is_active = 1 THEN s.full_name END ORDER BY s.full_name SEPARATOR ', ') AS linked_child_names,
            COUNT(DISTINCT CASE WHEN pl.is_active = 1 THEN pl.id END) AS linked_children
     FROM admin a
     JOIN user_roles ur ON ur.user_id = a.id
     JOIN roles r ON r.id = ur.role_id
     LEFT JOIN parent_student_links pl ON pl.parent_user_id = a.id
     LEFT JOIN students s ON s.id = pl.student_id
     LEFT JOIN parent_profiles pp ON pp.parent_user_id = a.id
     WHERE EXISTS (
         SELECT 1 FROM user_roles pur JOIN roles pr ON pr.id = pur.role_id
         WHERE pur.user_id = a.id AND COALESCE(pr.role_name, pr.name) = 'Parent'
     )
     GROUP BY a.id, a.name, a.username, a.status
     ORDER BY a.name"
)->fetchAll();
$linkedChildren = $pdo->query(
    "SELECT pl.id AS link_id, pl.parent_user_id, pl.relationship_type, pl.is_primary, a.name AS parent_name, a.username,
            s.id AS student_id, s.full_name, s.registration_no, s.class_level, pl.linked_at
     FROM parent_student_links pl
     JOIN admin a ON a.id = pl.parent_user_id
     JOIN students s ON s.id = pl.student_id
     WHERE pl.is_active = 1
     ORDER BY a.name, s.full_name"
)->fetchAll();
$students = $pdo->query("SELECT id, registration_no, full_name, class_level FROM students WHERE enrollment_status = 'Active' ORDER BY full_name")->fetchAll();
$auditRows = $pdo->query(
    "SELECT l.action, l.parent_user_id, l.student_id, l.created_at, a.name AS actor_name,
            p.name AS parent_name, s.full_name AS student_name
     FROM parent_link_audit l
     LEFT JOIN admin a ON a.id = l.actor_admin_id
     LEFT JOIN admin p ON p.id = l.parent_user_id
     LEFT JOIN students s ON s.id = l.student_id
     ORDER BY l.id DESC LIMIT 20"
)->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Family Access</p><h1>Parent Accounts</h1><p class="mb-0 text-muted">Create secure sign-ins, link guardians to students, and revoke access when needed.</p></div>
</div>

<section class="panel parent-setup-panel mb-4">
    <div class="panel-heading"><h2>Set Up Parent Access</h2></div>
    <?php if (!$guardianOptions): ?>
        <p class="text-muted mb-0">No registered parent records with a valid phone number are available yet. Add the parent contact details when registering a student, then return here.</p>
    <?php else: ?>
        <form method="post" class="row g-3" id="parent-handoff-form">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="handoff_guardian">
            <input type="hidden" name="student_id" id="setup-student-id" value="">
            <div class="col-lg-5">
                <label class="form-label" for="student-search">Student name</label>
                <div class="position-relative" id="student-picker">
                    <input class="form-control" id="student-search" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="student-options" placeholder="Choose or search a student" autocomplete="off" required>
                    <div class="parent-picker-menu" id="student-options" role="listbox" hidden></div>
                </div>
            </div>
            <div class="col-lg-7">
                <label class="form-label" for="guardian-children">Parent name</label>
                <div id="guardian-children" class="border rounded p-2" aria-live="polite">
                    <span class="text-muted">Choose a student to show the registered parent.</span>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                <button class="btn btn-primary" type="submit">Set up parent account</button>
            </div>
        </form>
        <style>
            .panel.parent-setup-panel { position: relative; z-index: 5; overflow: visible; }
            .parent-picker-menu { position: absolute; z-index: 20; top: 100%; left: 0; right: 0; max-height: 18rem; overflow-y: auto; background: #fff; border: 1px solid #ced4da; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,.12); }
            .parent-picker-option { display: block; width: 100%; padding: .65rem .75rem; border: 0; border-bottom: 1px solid #edf0f2; background: #fff; color: inherit; text-align: left; }
            .parent-picker-option:hover, .parent-picker-option:focus { background: #f1f5f7; }
        </style>
        <script>
            (() => {
                const guardians = <?= json_encode($guardianOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
                const form = document.getElementById('parent-handoff-form');
                const picker = document.getElementById('student-picker');
                const input = document.getElementById('student-search');
                const selectedStudent = document.getElementById('setup-student-id');
                const options = document.getElementById('student-options');
                const children = document.getElementById('guardian-children');
                const students = guardians.flatMap((guardian) => guardian.children.map((child) => ({ ...child, guardian })));

                function closeOptions() {
                    options.hidden = true;
                    input.setAttribute('aria-expanded', 'false');
                }

                function showOptions() {
                    const query = input.value.trim().toLocaleLowerCase();
                    options.replaceChildren();
                    const matches = students.filter((student) =>
                        `${student.name} ${student.registration_no} ${student.class_level} ${student.guardian.name} ${student.guardian.phone}`
                            .toLocaleLowerCase().includes(query)
                    );
                    if (!matches.length) {
                        const empty = document.createElement('div');
                        empty.className = 'p-2 text-muted small';
                        empty.textContent = 'No matching student found.';
                        options.append(empty);
                    }
                    matches.forEach((student) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'parent-picker-option';
                        button.setAttribute('role', 'option');
                        button.textContent = `${student.name} - ${student.registration_no} - ${student.class_level}`;
                        button.addEventListener('click', () => chooseStudent(student));
                        options.append(button);
                    });
                    options.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }

                function chooseStudent(student) {
                    const guardian = student.guardian;
                    selectedStudent.value = student.id;
                    input.value = `${student.name} - ${student.registration_no} - ${student.class_level}`;
                    input.setCustomValidity('');
                    children.replaceChildren();
                    const parentName = document.createElement('strong');
                    parentName.className = 'd-block';
                    parentName.textContent = guardian.name;
                    const parentPhone = document.createElement('span');
                    parentPhone.className = 'text-muted small d-block mb-2';
                    parentPhone.textContent = guardian.phone;
                    const childrenLabel = document.createElement('span');
                    childrenLabel.className = 'small fw-semibold d-block mb-1';
                    childrenLabel.textContent = 'Children to include';
                    children.append(parentName, parentPhone, childrenLabel);
                    guardian.children.forEach((child) => {
                        const label = document.createElement('label');
                        label.className = 'form-check d-flex align-items-center gap-2 py-1';
                        const checkbox = document.createElement('input');
                        checkbox.className = 'form-check-input m-0';
                        checkbox.type = 'checkbox';
                        checkbox.name = 'student_ids[]';
                        checkbox.value = child.id;
                        checkbox.checked = true;
                        const text = document.createElement('span');
                        text.textContent = `${child.name} - ${child.registration_no} - ${child.class_level}`;
                        label.append(checkbox, text);
                        children.append(label);
                    });
                    closeOptions();
                }

                input.addEventListener('focus', showOptions);
                input.addEventListener('click', showOptions);
                input.addEventListener('input', () => {
                    selectedStudent.value = '';
                    input.setCustomValidity('');
                    children.innerHTML = '<span class="text-muted">Choose a student to show the registered parent.</span>';
                    showOptions();
                });
                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') closeOptions();
                    if (event.key === 'ArrowDown' && !options.hidden) {
                        event.preventDefault();
                        options.querySelector('button')?.focus();
                    }
                });
                document.addEventListener('click', (event) => {
                    if (!picker.contains(event.target)) closeOptions();
                });
                form.addEventListener('submit', (event) => {
                    if (!selectedStudent.value) {
                        event.preventDefault();
                        input.setCustomValidity('Select a student from the list.');
                        input.reportValidity();
                    } else if (!children.querySelector('input[name="student_ids[]"]:checked')) {
                        event.preventDefault();
                        window.alert('Select at least one child for this parent.');
                    }
                });
            })();
        </script>
    <?php endif; ?>
</section>

<section class="panel mb-4">
    <div class="panel-heading"><h2>Link a Child</h2></div>
    <?php if (!$parents || !$students): ?>
        <p class="text-muted mb-0">Create a Parent account and make sure students are registered before adding a link.</p>
    <?php else: ?>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="link_student">
            <div class="col-md-4"><label class="form-label" for="link-parent">Parent account</label><select class="form-select" id="link-parent" name="parent_user_id" required><option value="">Select parent</option><?php foreach ($parents as $parent): ?><?php if ($parent['role_names'] === 'Parent'): ?><option value="<?= (int) $parent['id'] ?>"><?= h($parent['name'] . ' - ' . $parent['username']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="link-student">Student</label><select class="form-select" id="link-student" name="student_id" required><option value="">Select child</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= h($student['full_name'] . ' - ' . $student['registration_no'] . ' - ' . $student['class_level']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="link-relationship">Relationship</label><input class="form-control" id="link-relationship" name="relationship_type" maxlength="50" placeholder="Father"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Link child</button></div>
        </form>
    <?php endif; ?>
</section>

<section class="panel mb-4">
    <div class="panel-heading"><h2>Parent Accounts &amp; Linked Children</h2></div>
    <?php if ($parents): ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Parent</th><th>Username</th><th>Linked child/children</th><th>Status</th><th>Children</th><th>Account actions</th></tr></thead>
                <tbody>
                <?php foreach ($parents as $parent): ?>
                    <tr>
                        <td><?= h($parent['name']) ?></td><td><?= h($parent['username']) ?></td>
                        <td><?= h($parent['linked_child_names'] ?: 'No child linked') ?></td>
                        <td><span class="badge <?= $parent['status'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h($parent['status'] === 'Active' ? 'Active' : (!empty($parent['has_profile']) && empty($parent['activated_at']) ? 'Pending activation' : 'Inactive')) ?></span></td>
                        <td><?= (int) $parent['linked_children'] ?></td>
                        <td>
                            <?php if ($parent['role_names'] === 'Parent'): ?>
                                <?php if ($parent['status'] !== 'Active' && !empty($parent['has_profile']) && empty($parent['activated_at'])): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="handoff_activation"><input type="hidden" name="parent_user_id" value="<?= (int) $parent['id'] ?>">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Set parent password</button>
                                    </form>
                                <?php else: ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set_account_status"><input type="hidden" name="parent_user_id" value="<?= (int) $parent['id'] ?>"><input type="hidden" name="status" value="<?= $parent['status'] === 'Active' ? 'Inactive' : 'Active' ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $parent['status'] === 'Active' ? 'Disable' : 'Activate' ?></button>
                                </form>
                                <?php endif; ?>
                                <details class="d-inline-block ms-1"><summary class="btn btn-sm btn-outline-primary">Reset password</summary>
                                    <form method="post" class="mt-2 p-2 border bg-white">
                                        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="parent_user_id" value="<?= (int) $parent['id'] ?>">
                                        <input class="form-control form-control-sm mb-2" name="password" type="password" minlength="12" autocomplete="new-password" placeholder="New password" required>
                                        <input class="form-control form-control-sm mb-2" name="confirm_password" type="password" minlength="12" autocomplete="new-password" placeholder="Confirm new password" required>
                                        <button class="btn btn-sm btn-primary" type="submit">Save password</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <span class="text-muted small">Correct role assignment in Users &amp; Permissions.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="text-muted mb-0">No accounts have the Parent role yet.</p>
    <?php endif; ?>
</section>

<section class="panel mb-4">
    <div class="panel-heading"><h2>Active Parent-Child Links</h2></div>
    <?php if ($linkedChildren): ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Parent</th><th>Username</th><th>Relationship</th><th>Child</th><th>Admission number</th><th>Class</th><th>Linked</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($linkedChildren as $link): ?>
                    <tr>
                        <td><?= h($link['parent_name']) ?><?= (int) $link['is_primary'] === 1 ? ' (Primary)' : '' ?></td><td><?= h($link['username']) ?></td><td><?= h($link['relationship_type'] ?: 'Guardian') ?></td><td><?= h($link['full_name']) ?></td><td><?= h($link['registration_no']) ?></td><td><?= h($link['class_level']) ?></td><td><?= h($link['linked_at']) ?></td>
                        <td class="text-nowrap">
                            <form method="post" class="d-inline" onsubmit="return confirm('Revoke this parent-child link? Access will stop immediately.');"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="unlink_student"><input type="hidden" name="link_id" value="<?= (int) $link['link_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Unlink</button></form>
                            <form method="post" class="d-inline ms-1" onsubmit="return confirm('Permanently delete this parent-child link? The parent account and student will not be deleted.');"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_student_link"><input type="hidden" name="link_id" value="<?= (int) $link['link_id'] ?>"><button class="btn btn-sm btn-danger" type="submit"><i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Delete</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="text-muted mb-0">No active parent-child links have been recorded.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-heading"><h2>Recent Relationship and Account Changes</h2></div>
    <?php if ($auditRows): ?>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Time</th><th>Action</th><th>Parent</th><th>Child</th><th>Changed by</th></tr></thead><tbody>
        <?php foreach ($auditRows as $audit): ?>
            <tr><td><?= h($audit['created_at']) ?></td><td><?= h(str_replace('_', ' ', ucfirst($audit['action']))) ?></td><td><?= h(($audit['parent_name'] ?: 'Account ID ' . $audit['parent_user_id'])) ?></td><td><?= h($audit['student_name'] ?: ($audit['student_id'] ? 'Student ID ' . $audit['student_id'] : '-')) ?></td><td><?= h($audit['actor_name'] ?: 'Former administrator') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?>
        <p class="text-muted mb-0">No changes have been recorded yet.</p>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
