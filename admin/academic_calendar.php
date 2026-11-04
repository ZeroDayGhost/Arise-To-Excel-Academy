<?php
$pageTitle = 'Academic Settings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'academic_calendar.manage')) {
    flash('error', 'You do not have permission to access Academic Calendar.');
    redirect('admin/dashboard.php');
}
$canChangeAcademicPeriod = current_admin_has_permission($pdo, 'academic_calendar.change_period');

$terms = term_options();
$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['calendar_id'] ?? 0));
$currentContext = active_academic_context($pdo);
$nextAcademicYear = (string) ((int) $currentContext['academic_year'] + 1);
$promotionPreview = student_class_promotion_preview($pdo, $currentContext['academic_year']);
$nextTransitionStmt = $pdo->prepare('SELECT * FROM student_class_promotions WHERE from_year = :from_year AND to_year = :to_year LIMIT 1');
$nextTransitionStmt->execute(['from_year' => $currentContext['academic_year'], 'to_year' => $nextAcademicYear]);
$nextPromotion = $nextTransitionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$promotionClassRows = [];
if ($nextPromotion) {
    $classRows = $pdo->prepare(
        "SELECT assigned_from_class AS source_class, class_level AS target_class, COUNT(*) AS students_after
         FROM student_class_assignments
         WHERE academic_year = :academic_year AND assignment_reason IN ('promotion', 'promotion_carryover')
         GROUP BY assigned_from_class, class_level
         ORDER BY assigned_from_class, class_level"
    );
    $classRows->execute(['academic_year' => $nextAcademicYear]);
    $promotionClassRows = $classRows->fetchAll(PDO::FETCH_ASSOC);
}
if ($nextPromotion && $nextPromotion['undone_at'] === null) {
    $savedSummary = student_promotion_decode_summary($nextPromotion);
    if ($savedSummary) {
        $promotionPreview = array_merge($promotionPreview, $savedSummary);
    }
}
$latestPromotion = $pdo->query('SELECT * FROM student_class_promotions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
$undoSafety = $latestPromotion && $latestPromotion['undone_at'] === null
    ? student_class_promotion_undo_safety($pdo, (int) $latestPromotion['id'])
    : null;
$actorId = (int) ($_SESSION['admin_id'] ?? 0);
$canUndoPromotion = student_class_promotion_admin_allowed($pdo, $actorId);
$form = [
    'id' => '',
    'academic_year' => $currentContext['academic_year'],
    'term_name' => $currentContext['term'],
    'start_date' => $currentContext['start_date'] ?: date('Y-m-d'),
    'end_date' => $currentContext['end_date'] ?: date('Y-m-d'),
];

function academic_calendar_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM academic_calendar WHERE id = :id");
    $statement->execute(['id' => $id]);
    $calendar = $statement->fetch();

    return $calendar ?: null;
}

function academic_calendar_duplicate_exists(PDO $pdo, string $academicYear, string $termName, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM academic_calendar
            WHERE academic_year = :academic_year
              AND term_name = :term_name";
    $params = [
        'academic_year' => $academicYear,
        'term_name' => $termName,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

function academic_calendar_adjacent_context(PDO $pdo, string $academicYear, string $termName, int $direction): ?array
{
    $rows = $pdo->query(
        "SELECT academic_year, term_name
         FROM academic_calendar
         ORDER BY academic_year ASC, FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')"
    )->fetchAll();

    foreach ($rows as $index => $row) {
        if ($row['academic_year'] === $academicYear && $row['term_name'] === $termName) {
            $targetIndex = $index + $direction;
            if (isset($rows[$targetIndex])) {
                return [
                    'academic_year' => $rows[$targetIndex]['academic_year'],
                    'term' => $rows[$targetIndex]['term_name'],
                ];
            }

            return null;
        }
    }

    return null;
}

function academic_calendar_replacement_context(PDO $pdo, string $academicYear, string $termName): ?array
{
    $terms = term_options();
    $deletedOrder = array_search($termName, $terms, true);
    $deletedOrder = $deletedOrder === false ? 0 : $deletedOrder;

    $statement = $pdo->prepare(
        "SELECT academic_year, term_name
         FROM academic_calendar
         WHERE academic_year = :academic_year
         ORDER BY FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')"
    );
    $statement->execute(['academic_year' => $academicYear]);
    $sameYearRows = $statement->fetchAll();

    foreach ($sameYearRows as $row) {
        $rowOrder = array_search($row['term_name'], $terms, true);
        if ($rowOrder !== false && $rowOrder > $deletedOrder) {
            return ['academic_year' => $row['academic_year'], 'term' => $row['term_name']];
        }
    }

    for ($index = count($sameYearRows) - 1; $index >= 0; $index--) {
        $row = $sameYearRows[$index];
        $rowOrder = array_search($row['term_name'], $terms, true);
        if ($rowOrder !== false && $rowOrder < $deletedOrder) {
            return ['academic_year' => $row['academic_year'], 'term' => $row['term_name']];
        }
    }

    $fallback = $pdo->query(
        "SELECT academic_year, term_name
         FROM academic_calendar
         ORDER BY academic_year DESC, FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')
         LIMIT 1"
    )->fetch();

    return $fallback ? ['academic_year' => $fallback['academic_year'], 'term' => $fallback['term_name']] : null;
}

function purge_academic_term(PDO $pdo, string $academicYear, string $termName, int $calendarId): array
{
    $counts = [];
    // Keep permanent financial records if a term definition is removed.
    $paymentCount = $pdo->prepare('SELECT COUNT(*) FROM fees WHERE year = :academic_year AND term = :term');
    $paymentCount->execute(['academic_year' => $academicYear, 'term' => $termName]);
    $counts['fee payments retained'] = (int) $paymentCount->fetchColumn();
    $balanceCount = $pdo->prepare('SELECT COUNT(*) FROM fee_balances WHERE academic_year = :academic_year AND term = :term');
    $balanceCount->execute(['academic_year' => $academicYear, 'term' => $termName]);
    $counts['fee balances retained'] = (int) $balanceCount->fetchColumn();

    $deleteStatements = [
        'feeding records' => "DELETE FROM feeding_subscriptions WHERE academic_year = :academic_year AND term = :term",
        'transport records' => "DELETE FROM transport_accounts WHERE academic_year = :academic_year AND term = :term",
    ];

    foreach ($deleteStatements as $label => $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute([
            'academic_year' => $academicYear,
            'term' => $termName,
        ]);
        $counts[$label] = $statement->rowCount();
    }

    $deleteCalendar = $pdo->prepare(
        "DELETE FROM academic_calendar
         WHERE id = :id
           AND academic_year = :academic_year
           AND term_name = :term"
    );
    $deleteCalendar->execute([
        'id' => $calendarId,
        'academic_year' => $academicYear,
        'term' => $termName,
    ]);
    $counts['calendar records'] = $deleteCalendar->rowCount();

    return $counts;
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editing = academic_calendar_by_id($pdo, $editingId);
    if ($editing) {
        $form = array_merge($form, $editing);
    } else {
        flash('error', 'Academic calendar record was not found.');
        redirect('admin/academic_calendar.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if (in_array($action, ['set_active', 'promote_switch_year'], true) && !$canChangeAcademicPeriod) {
        rbac_audit_log($pdo, (int) ($_SESSION['admin_id'] ?? 0), 'academic_calendar.change_period', 'Academic Calendar', false, 'DENY', ['action' => $action]);
        flash('error', 'You do not have permission to change the active academic period.');
        redirect('admin/academic_calendar.php');
    }

    if ($action === 'set_active') {
        $activeYear = trim($_POST['active_academic_year'] ?? '');
        $activeTerm = trim($_POST['active_term'] ?? '');

        if (!preg_match('/^\d{4}$/', $activeYear)) {
            $errors[] = 'Choose a valid four-digit academic year.';
        }
        if (!in_array($activeTerm, $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }
        if (!$errors) {
            $periodExists = $pdo->prepare('SELECT COUNT(*) FROM academic_calendar WHERE academic_year = :year AND term_name = :term');
            $periodExists->execute(['year' => $activeYear, 'term' => $activeTerm]);
            if ((int) $periodExists->fetchColumn() === 0) {
                $errors[] = 'Choose a term that exists in the academic calendar.';
            }
        }
        if (!$errors) {
            try {
                if ((int) $activeYear < (int) $currentContext['academic_year']) {
                    set_current_academic_context($pdo, $activeYear, $activeTerm);
                    clear_academic_view_context();
                    rbac_audit_log($pdo, $actorId, 'academic_calendar.set_active', 'Academic Calendar', true, 'WARN', [
                        'previous_period' => $currentContext['academic_year'] . ' ' . $currentContext['term'],
                        'active_period' => $activeYear . ' ' . $activeTerm,
                        'promotion_triggered' => false,
                    ]);
                    flash('success', "Active period set to {$activeYear} {$activeTerm}. Student class assignments and promotion history were not changed.");
                } elseif ((int) $activeYear > (int) $currentContext['academic_year']) {
                    if ((int) $activeYear !== (int) $currentContext['academic_year'] + 1) {
                        throw new InvalidArgumentException('Academic years can only be activated one year at a time.');
                    }
                    $summary = student_complete_year_switch($pdo, $currentContext['academic_year'], $activeYear, $actorId);
                    clear_academic_view_context();
                    flash('success', "Promotion was already complete; the active year is now {$summary['active_year']} Term 1. No students were promoted again.");
                } else {
                    set_current_academic_context($pdo, $activeYear, $activeTerm);
                    clear_academic_view_context();
                    flash('success', "Active term set to {$activeYear} {$activeTerm}.");
                }
                redirect('admin/academic_calendar.php');
            } catch (Throwable $exception) {
                error_log('Academic context update failed with code ' . (string) $exception->getCode());
                $errors[] = 'Unable to update the active academic period. Please try again.';
            }
        }
    } elseif ($action === 'set_view') {
        $viewYear = trim((string) ($_POST['active_academic_year'] ?? ''));
        $viewTerm = trim((string) ($_POST['active_term'] ?? ''));
        try {
            set_academic_view_context($pdo, $viewYear, $viewTerm);
            flash('success', "Viewing {$viewYear} {$viewTerm}. Historical records are read-only.");
            redirect('admin/academic_calendar.php');
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    } elseif ($action === 'promote_switch_year') {
        $fromYear = trim((string) ($_POST['from_academic_year'] ?? ''));
        $toYear = trim((string) ($_POST['to_academic_year'] ?? ''));
        if ($fromYear !== $currentContext['academic_year'] || $toYear !== $nextAcademicYear) {
            $errors[] = 'The academic year changed. Reload the page and review the promotion summary.';
        } else {
            try {
                $summary = student_promote_and_switch_year($pdo, $fromYear, $toYear, (int) ($_SESSION['admin_id'] ?? 0));
                clear_academic_view_context();
                if (!empty($summary['resumed'])) {
                    flash('success', "Promotion from {$fromYear} to {$toYear} was already complete. The active year is now {$toYear} Term 1; students were not promoted again.");
                } else {
                    flash('success', "Promoted {$summary['promoted']} student(s) from {$fromYear} to {$toYear}; {$summary['total']} active record(s) were reviewed. Term 1 is now active.");
                }
                redirect('admin/academic_calendar.php');
            } catch (Throwable $exception) {
                error_log('Student promotion failed with code ' . (string) $exception->getCode());
                $errors[] = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Promotion failed. No class changes were saved; please try again.';
            }
        }
    } elseif ($action === 'undo_promotion') {
        try {
            $affected = student_class_promotion_undo(
                $pdo,
                (int) ($_POST['promotion_id'] ?? 0),
                $actorId,
                (string) ($_POST['undo_reason'] ?? '')
            );
            clear_academic_view_context();
            flash('success', "Promotion undone. {$affected} student(s) were restored to their previous classes.");
            redirect('admin/academic_calendar.php');
        } catch (Throwable $exception) {
            $errors[] = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The promotion could not be undone. No changes were saved.';
        }
    } elseif ($action === 'generate_year') {
        $newYear = trim($_POST['new_academic_year'] ?? '');

        if (!preg_match('/^\d{4}$/', $newYear)) {
            $errors[] = 'Enter a valid four-digit academic year.';
        }

        if (!$errors) {
            ensure_academic_calendar_for_year($pdo, $newYear);
            flash('success', "Default term calendar created for {$newYear}.");
            redirect('admin/academic_calendar.php?year=' . urlencode($newYear));
        }
    } else {
        $calendarId = (int) ($_POST['calendar_id'] ?? 0);
        $form = [
            'id' => $calendarId,
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'term_name' => trim($_POST['term_name'] ?? ''),
            'start_date' => trim($_POST['start_date'] ?? ''),
            'end_date' => trim($_POST['end_date'] ?? ''),
        ];

        if (!preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year must be four digits.';
        }
        if (!in_array($form['term_name'], $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }
        if (!valid_date_value($form['start_date'])) {
            $errors[] = 'Start date must be valid.';
        }
        if (!valid_date_value($form['end_date'])) {
            $errors[] = 'End date must be valid.';
        }
        if (!$errors && $form['start_date'] > $form['end_date']) {
            $errors[] = 'Start date must be before or equal to end date.';
        }
        if (!$errors && academic_calendar_duplicate_exists($pdo, $form['academic_year'], $form['term_name'], $action === 'update' ? $calendarId : 0)) {
            $errors[] = 'This academic year and term already exists.';
        }
        if ($action === 'update' && $calendarId <= 0) {
            $errors[] = 'Choose a calendar record to update.';
        }
        if (!in_array($action, ['save', 'update'], true)) {
            $errors[] = 'Choose a valid action.';
        }

        if (!$errors) {
            $params = [
                'academic_year' => $form['academic_year'],
                'term_name' => $form['term_name'],
                'start_date' => $form['start_date'],
                'end_date' => $form['end_date'],
            ];

            if ($action === 'update') {
                $params['id'] = $calendarId;
                $statement = $pdo->prepare(
                    "UPDATE academic_calendar
                     SET academic_year = :academic_year,
                         term_name = :term_name,
                         start_date = :start_date,
                         end_date = :end_date
                     WHERE id = :id"
                );
                $statement->execute($params);
                flash('success', 'Academic calendar updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO academic_calendar (academic_year, term_name, start_date, end_date)
                     VALUES (:academic_year, :term_name, :start_date, :end_date)"
                );
                $statement->execute($params);
                flash('success', 'Academic calendar record saved successfully.');
            }

            redirect('admin/academic_calendar.php');
        }
    }
}

$filterYear = preg_match('/^\d{4}$/', ($_GET['year'] ?? '')) ? $_GET['year'] : '';
$params = [];
$sql = "SELECT * FROM academic_calendar";
if ($filterYear !== '') {
    $sql .= " WHERE academic_year = :academic_year";
    $params['academic_year'] = $filterYear;
}
$sql .= " ORDER BY academic_year DESC, FIELD(term_name, 'Term 1', 'Term 2', 'Term 3'), start_date ASC";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$calendarRows = $statement->fetchAll();
$years = $pdo->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC")->fetchAll();
$yearOptions = array_column($years, 'academic_year');
if (!in_array($currentContext['academic_year'], $yearOptions, true)) {
    $yearOptions[] = $currentContext['academic_year'];
    rsort($yearOptions);
}
$previousContext = academic_calendar_adjacent_context($pdo, $currentContext['academic_year'], $currentContext['term'], -1);
$nextContext = academic_calendar_adjacent_context($pdo, $currentContext['academic_year'], $currentContext['term'], 1);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">System Settings</p>
        <h1>Academic Calendar</h1>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/dashboard.php') ?>">Dashboard</a>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-4 col-lg-5">
        <section class="panel">
            <h2>Active Term</h2>
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="mini-stat">
                        <span>Academic Year</span>
                        <strong><?= h($currentContext['academic_year']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="mini-stat">
                        <span>Current Term</span>
                        <strong><?= h($currentContext['term']) ?></strong>
                    </div>
                </div>
                <div class="col-12">
                    <div class="mini-stat">
                        <span>Computer Date</span>
                        <strong><?= h($currentContext['today']) ?></strong>
                    </div>
                </div>
            </div>
            <div class="action-row mt-3">
                <?php if ($previousContext): ?>
                    <form method="post">
                        <input type="hidden" name="active_academic_year" value="<?= h($previousContext['academic_year']) ?>">
                        <input type="hidden" name="active_term" value="<?= h($previousContext['term']) ?>">
                        <button class="btn btn-sm btn-outline-primary" name="action" value="set_view" type="submit">View Previous Term</button>
                    </form>
                <?php endif; ?>
                <?php if ($nextContext): ?>
                    <form method="post">
                        <input type="hidden" name="active_academic_year" value="<?= h($nextContext['academic_year']) ?>">
                        <input type="hidden" name="active_term" value="<?= h($nextContext['term']) ?>">
                        <?php if ($nextContext['academic_year'] === $currentContext['academic_year']): ?>
                            <button class="btn btn-sm btn-outline-primary" name="action" value="set_view" type="submit">View Next Term</button>
                        <?php else: ?>
                            <span class="small text-muted">Use the promotion control below to start the next year.</span>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($canChangeAcademicPeriod): ?>
            <form class="row g-3 mt-1" method="post">
                <input type="hidden" name="active_academic_year" value="<?= h($currentContext['academic_year']) ?>">
                <div class="col-12">
                    <label class="form-label" for="active_term">Set Active Term · <?= h($currentContext['academic_year']) ?></label>
                    <select class="form-select" id="active_term" name="active_term" required>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= h($term) ?>" <?= $currentContext['term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100" name="action" value="set_active" type="submit">Set Active Term</button>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="panel mt-4">
            <div class="panel-heading">
                <div>
                    <h2>Promote Students &amp; Switch Year</h2>
                </div>
            </div>
            <?php if ($nextPromotion && $nextPromotion['undone_at'] !== null): ?>
                <div class="alert alert-warning">The <?= h($currentContext['academic_year']) ?> to <?= h($nextAcademicYear) ?> promotion was undone. It cannot be run a second time for the same transition.</div>
            <?php elseif ($promotionPreview['before']): ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Current class</th><th>Students before</th><th>Next-year class</th><th>Students after</th></tr></thead>
                        <tbody>
                        <?php if ($nextPromotion && $promotionClassRows): ?>
                            <?php foreach ($promotionClassRows as $row): ?>
                                <tr>
                                    <td><?= h($row['source_class'] ?? 'Unassigned') ?></td>
                                    <td><?= (int) ($promotionPreview['before'][$row['source_class'] ?? 'Unassigned'] ?? 0) ?></td>
                                    <td><?= h($row['target_class']) ?></td>
                                    <td><?= (int) $row['students_after'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ((int) ($promotionPreview['already_assigned'] ?? 0) > 0): ?>
                                <tr><td>Destination-year admissions</td><td><?= (int) $promotionPreview['already_assigned'] ?></td><td>Assigned class retained</td><td><?= (int) $promotionPreview['already_assigned'] ?></td></tr>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach (student_class_promotion_targets() as $sourceClass => $targetClass): ?>
                                <tr>
                                    <td><?= h($sourceClass) ?></td>
                                    <td><?= (int) ($promotionPreview['before'][$sourceClass] ?? 0) ?></td>
                                    <td><?= h($targetClass) ?></td>
                                    <td><?= (int) ($promotionPreview['after'][$targetClass] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($promotionPreview['before'] as $sourceClass => $count): ?>
                                <?php if (!array_key_exists($sourceClass, student_class_promotion_targets())): ?>
                                    <tr><td><?= h($sourceClass) ?></td><td><?= (int) $count ?></td><td><?= h($sourceClass) ?> (unchanged)</td><td><?= (int) ($promotionPreview['after'][$sourceClass] ?? 0) ?></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted">There are no active students to promote. The year will still switch, and Playgroup will be ready for new admissions.</p>
            <?php endif; ?>
            <p class="small text-muted">Active students: <?= (int) $promotionPreview['total'] ?>. Students already assigned in the destination year are left in their assigned classes.</p>
            <?php if ($canChangeAcademicPeriod): ?>
            <?php if ($nextPromotion && $nextPromotion['undone_at'] === null): ?>
                <form method="post" onsubmit="return confirm('The promotion is already complete. Set <?= h($nextAcademicYear) ?> Term 1 as active without changing any student class again?');">
                    <input type="hidden" name="action" value="promote_switch_year">
                    <input type="hidden" name="from_academic_year" value="<?= h($currentContext['academic_year']) ?>">
                    <input type="hidden" name="to_academic_year" value="<?= h($nextAcademicYear) ?>">
                    <button class="btn btn-primary w-100" type="submit">Complete Switch to <?= h($nextAcademicYear) ?></button>
                </form>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('Promote eligible active students from <?= h($currentContext['academic_year']) ?> to <?= h($nextAcademicYear) ?> and activate Term 1? Existing destination-year admissions will keep their assigned classes.');">
                    <input type="hidden" name="action" value="promote_switch_year">
                    <input type="hidden" name="from_academic_year" value="<?= h($currentContext['academic_year']) ?>">
                    <input type="hidden" name="to_academic_year" value="<?= h($nextAcademicYear) ?>">
                    <button class="btn btn-primary w-100" type="submit">Promote Students &amp; Switch Year</button>
                </form>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel mt-4">
            <h2>Create Academic Year</h2>
            <form class="row g-3" method="post">
                <div class="col-12">
                    <label class="form-label" for="new_academic_year">Academic Year</label>
                    <input class="form-control" id="new_academic_year" name="new_academic_year" maxlength="4" inputmode="numeric" value="<?= h($currentContext['academic_year']) ?>" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-primary w-100" name="action" value="generate_year" type="submit">Create Default Terms</button>
                </div>
            </form>
        </section>

        <section class="panel mt-4">
            <h2><?= (int) $form['id'] > 0 ? 'Edit Term Range' : 'New Term Range' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="calendar_id" value="<?= (int) $form['id'] ?>">
                <div class="col-sm-6">
                    <label class="form-label" for="academic_year">Academic Year</label>
                    <input class="form-control" id="academic_year" name="academic_year" maxlength="4" inputmode="numeric" value="<?= h((string) $form['academic_year']) ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="term_name">Term</label>
                    <select class="form-select" id="term_name" name="term_name" required>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= h($term) ?>" <?= $form['term_name'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="start_date">Start Date</label>
                    <input class="form-control" type="date" id="start_date" name="start_date" value="<?= h((string) $form['start_date']) ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="end_date">End Date</label>
                    <input class="form-control" type="date" id="end_date" name="end_date" value="<?= h((string) $form['end_date']) ?>" required>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <button class="btn btn-primary" name="action" value="save" type="submit">Save</button>
                        <button class="btn btn-outline-primary" name="action" value="update" type="submit" <?= (int) $form['id'] > 0 ? '' : 'disabled' ?>>Update</button>
                    </div>
                </div>
            </form>
        </section>
    </div>

    <div class="col-xl-8 col-lg-7">
        <section class="panel">
            <div class="panel-heading">
                <h2>Term Ranges</h2>
            </div>
            <form class="row g-3 mb-4" method="get">
                <div class="col-sm-8">
                    <select class="form-select" name="year">
                        <option value="">All academic years</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= h($year['academic_year']) ?>" <?= $filterYear === $year['academic_year'] ? 'selected' : '' ?>><?= h($year['academic_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr><th>Year</th><th>Term</th><th>Start</th><th>End</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calendarRows as $row): ?>
                            <?php
                            $isActiveRow = $row['academic_year'] === $currentContext['academic_year'] && $row['term_name'] === $currentContext['term'];
                            $currentTermOrder = array_search($currentContext['term'], $terms, true);
                            $rowTermOrder = array_search($row['term_name'], $terms, true);
                            $isPreviousPeriod = (int) $row['academic_year'] < (int) $currentContext['academic_year']
                                || ((int) $row['academic_year'] === (int) $currentContext['academic_year']
                                    && $currentTermOrder !== false && $rowTermOrder !== false && $rowTermOrder < $currentTermOrder);
                            ?>
                            <tr class="<?= $isActiveRow ? 'table-primary' : '' ?>">
                                <td><?= h($row['academic_year']) ?></td>
                                <td><?= h($row['term_name']) ?></td>
                                <td><?= h($row['start_date']) ?></td>
                                <td><?= h($row['end_date']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <?php if ($isActiveRow): ?>
                                            <span class="badge bg-primary">Active</span>
                                        <?php elseif ($isPreviousPeriod): ?>
                                            <?php if ($canChangeAcademicPeriod): ?>
                                                <form method="post">
                                                    <input type="hidden" name="active_academic_year" value="<?= h($row['academic_year']) ?>">
                                                    <input type="hidden" name="active_term" value="<?= h($row['term_name']) ?>">
                                                    <button class="btn btn-sm btn-outline-primary" name="action" value="set_active" type="submit">Set Active</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="active_academic_year" value="<?= h($row['academic_year']) ?>">
                                                <input type="hidden" name="active_term" value="<?= h($row['term_name']) ?>">
                                                <button class="btn btn-sm btn-outline-primary" name="action" value="set_view" type="submit">View</button>
                                            </form>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/academic_calendar.php?edit=' . $row['id']) ?>">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$calendarRows): ?><tr><td colspan="5">No calendar records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
