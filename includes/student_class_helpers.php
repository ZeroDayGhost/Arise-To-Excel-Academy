<?php

function ensure_student_class_history_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            class_level TEXT NOT NULL,
            assigned_from_class TEXT NULL,
            assignment_reason TEXT NOT NULL DEFAULT 'manual',
            assigned_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (student_id, academic_year)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_promotions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_year TEXT NOT NULL,
            to_year TEXT NOT NULL,
            source_term TEXT NOT NULL,
            target_term TEXT NOT NULL,
            promoted_by INTEGER NULL,
            counts_json TEXT NOT NULL,
            undone_at TEXT NULL,
            undone_by INTEGER NULL,
            undo_reason TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (from_year, to_year)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_promotion_items (
            promotion_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            previous_class TEXT NOT NULL,
            next_class TEXT NOT NULL,
            previous_status TEXT NOT NULL,
            next_status TEXT NOT NULL,
            source_assignment_id INTEGER NULL,
            target_assignment_id INTEGER NULL,
            PRIMARY KEY (promotion_id, student_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_promotion_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            promotion_id INTEGER NOT NULL,
            actor_id INTEGER NULL,
            action TEXT NOT NULL,
            reason TEXT NULL,
            affected_students INTEGER NOT NULL DEFAULT 0,
            details_json TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            class_level VARCHAR(80) NOT NULL,
            assigned_from_class VARCHAR(80) NULL,
            assignment_reason VARCHAR(30) NOT NULL DEFAULT 'manual',
            assigned_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_class_year (student_id, academic_year),
            KEY idx_class_assignment_year_class (academic_year, class_level),
            CONSTRAINT fk_class_assignment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_class_assignment_actor FOREIGN KEY (assigned_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_promotions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            from_year VARCHAR(4) NOT NULL,
            to_year VARCHAR(4) NOT NULL,
            source_term VARCHAR(20) NOT NULL,
            target_term VARCHAR(20) NOT NULL,
            promoted_by INT UNSIGNED NULL,
            counts_json LONGTEXT NOT NULL,
            undone_at TIMESTAMP NULL DEFAULT NULL,
            undone_by INT UNSIGNED NULL,
            undo_reason TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_class_year_transition (from_year, to_year),
            CONSTRAINT fk_student_promotion_actor FOREIGN KEY (promoted_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_promotion_items (
            promotion_id BIGINT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            previous_class VARCHAR(80) NOT NULL,
            next_class VARCHAR(80) NOT NULL,
            previous_status VARCHAR(20) NOT NULL,
            next_status VARCHAR(20) NOT NULL,
            source_assignment_id BIGINT UNSIGNED NULL,
            target_assignment_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (promotion_id, student_id),
            KEY idx_promotion_item_student (student_id),
            CONSTRAINT fk_promotion_item_promotion FOREIGN KEY (promotion_id) REFERENCES student_class_promotions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_promotion_item_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_class_promotion_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            promotion_id BIGINT UNSIGNED NOT NULL,
            actor_id INT UNSIGNED NULL,
            action VARCHAR(30) NOT NULL,
            reason TEXT NULL,
            affected_students INT UNSIGNED NOT NULL DEFAULT 0,
            details_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_promotion_audit_promotion (promotion_id, created_at),
            CONSTRAINT fk_promotion_audit_promotion FOREIGN KEY (promotion_id) REFERENCES student_class_promotions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_promotion_audit_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB");

        $promotionColumns = $pdo->query("SHOW COLUMNS FROM student_class_promotions")->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'undone_at' => 'ALTER TABLE student_class_promotions ADD COLUMN undone_at TIMESTAMP NULL DEFAULT NULL',
            'undone_by' => 'ALTER TABLE student_class_promotions ADD COLUMN undone_by INT UNSIGNED NULL',
            'undo_reason' => 'ALTER TABLE student_class_promotions ADD COLUMN undo_reason TEXT NULL',
        ] as $column => $alter) {
            if (!in_array($column, $promotionColumns, true)) {
                $pdo->exec($alter);
            }
        }
    }

    if ($driver === 'sqlite') {
        $studentColumns = $pdo->query('PRAGMA table_info(students)')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('admitted_academic_year', array_column($studentColumns, 'name'), true)) {
            $pdo->exec('ALTER TABLE students ADD COLUMN admitted_academic_year TEXT NULL');
        }
    } else {
        $admittedYearColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'admitted_academic_year'")->fetch();
        if (!$admittedYearColumn) {
            $pdo->exec('ALTER TABLE students ADD COLUMN admitted_academic_year VARCHAR(4) NULL AFTER class_level');
        }
    }

    if ($driver === 'mysql') {
        if ($pdo->query("SHOW TABLES LIKE 'academic_student_results'")->fetchColumn()) {
            $pdo->exec(
                "INSERT IGNORE INTO student_class_assignments
                    (student_id, academic_year, class_level, assignment_reason)
                 SELECT results.student_id, results.academic_year, results.class_level, 'academic_snapshot'
                 FROM academic_student_results results
                 JOIN (
                     SELECT student_id, academic_year, MAX(id) AS latest_result_id
                     FROM academic_student_results
                     WHERE academic_year REGEXP '^[0-9]{4}$' AND class_level <> ''
                     GROUP BY student_id, academic_year
                 ) latest ON latest.latest_result_id = results.id"
            );
        }
        $pdo->exec(
            "INSERT IGNORE INTO student_class_promotion_items
                (promotion_id, student_id, previous_class, next_class, previous_status, next_status, source_assignment_id, target_assignment_id)
             SELECT p.id, target.student_id, COALESCE(source.class_level, target.assigned_from_class), target.class_level,
                    'Active', 'Active', source.id, target.id
             FROM student_class_promotions p
             JOIN student_class_assignments target
               ON target.academic_year = p.to_year AND target.assignment_reason IN ('promotion', 'promotion_carryover')
             LEFT JOIN student_class_assignments source
               ON source.student_id = target.student_id AND source.academic_year = p.from_year
             WHERE COALESCE(source.class_level, target.assigned_from_class) IS NOT NULL"
        );
    } else {
        $pdo->exec(
            "INSERT OR IGNORE INTO student_class_promotion_items
                (promotion_id, student_id, previous_class, next_class, previous_status, next_status, source_assignment_id, target_assignment_id)
             SELECT p.id, target.student_id, COALESCE(source.class_level, target.assigned_from_class), target.class_level,
                    'Active', 'Active', source.id, target.id
             FROM student_class_promotions p
             JOIN student_class_assignments target
               ON target.academic_year = p.to_year AND target.assignment_reason IN ('promotion', 'promotion_carryover')
             LEFT JOIN student_class_assignments source
               ON source.student_id = target.student_id AND source.academic_year = p.from_year
             WHERE COALESCE(source.class_level, target.assigned_from_class) IS NOT NULL"
        );
    }

    $insertSourceAssignments = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $pdo->exec(
        "{$insertSourceAssignments} INTO student_class_assignments
            (student_id, academic_year, class_level, assignment_reason)
         SELECT i.student_id, p.from_year, i.previous_class, 'promotion_source'
         FROM student_class_promotion_items i
         JOIN student_class_promotions p ON p.id = i.promotion_id
         WHERE i.previous_class <> ''"
    );
    $pdo->exec(
        'UPDATE student_class_promotion_items
         SET source_assignment_id = (
             SELECT source.id
             FROM student_class_promotions p
             JOIN student_class_assignments source
               ON source.student_id = student_class_promotion_items.student_id
              AND source.academic_year = p.from_year
             WHERE p.id = student_class_promotion_items.promotion_id
             LIMIT 1
         )
         WHERE source_assignment_id IS NULL'
    );

    $ready = true;
}

function student_class_sql(string $studentAlias, string $academicYearExpression): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $studentAlias)) {
        throw new InvalidArgumentException('Invalid student table alias.');
    }
    if (!preg_match('/^(?:(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?[a-zA-Z_][a-zA-Z0-9_]*|:[a-zA-Z_][a-zA-Z0-9_]*|\'\d{4}\'|YEAR\((?:[a-zA-Z_][a-zA-Z0-9_]*\.)?[a-zA-Z_][a-zA-Z0-9_]*\))$/', $academicYearExpression)) {
        throw new InvalidArgumentException('Invalid academic year expression.');
    }

    return "COALESCE((SELECT sca.class_level
                     FROM student_class_assignments sca
                     WHERE sca.student_id = {$studentAlias}.id
                       AND sca.academic_year = {$academicYearExpression}
                     LIMIT 1), {$studentAlias}.class_level)";
}

function student_class_sql_for_year(string $studentAlias, string $academicYear): string
{
    if (!preg_match('/^\d{4}$/', $academicYear)) {
        throw new InvalidArgumentException('Invalid academic year.');
    }
    return student_class_sql($studentAlias, "'{$academicYear}'");
}

function student_status_sql_for_year(string $studentAlias, string $academicYear): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $studentAlias) || !preg_match('/^\d{4}$/', $academicYear)) {
        throw new InvalidArgumentException('Invalid student alias or academic year.');
    }

    return "COALESCE((
        SELECT CASE
            WHEN p.undone_at IS NOT NULL THEN i.previous_status
            WHEN p.from_year = '{$academicYear}' THEN i.previous_status
            ELSE i.next_status
        END
        FROM student_class_promotion_items i
        JOIN student_class_promotions p ON p.id = i.promotion_id
        WHERE i.student_id = {$studentAlias}.id
          AND (p.from_year = '{$academicYear}' OR p.to_year = '{$academicYear}')
        ORDER BY CASE WHEN p.from_year = '{$academicYear}' THEN 0 ELSE 1 END, p.id DESC
        LIMIT 1
    ), {$studentAlias}.enrollment_status)";
}

function student_class_for_year(PDO $pdo, int $studentId, string $academicYear, ?string $fallback = null): ?string
{
    ensure_student_class_history_schema($pdo);
    if ($studentId <= 0 || !preg_match('/^\d{4}$/', $academicYear)) {
        return $fallback;
    }

    $statement = $pdo->prepare(
        'SELECT class_level FROM student_class_assignments WHERE student_id = :student_id AND academic_year = :academic_year LIMIT 1'
    );
    $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear]);
    $classLevel = $statement->fetchColumn();
    return $classLevel === false ? $fallback : (string) $classLevel;
}

function student_class_assignment_is_historical(PDO $pdo, int $studentId, string $academicYear): bool
{
    ensure_student_class_history_schema($pdo);
    if ($studentId <= 0 || !preg_match('/^\\d{4}$/', $academicYear)) {
        return false;
    }

    $statement = $pdo->prepare('SELECT MAX(academic_year) FROM student_class_assignments WHERE student_id = :student_id');
    $statement->execute(['student_id' => $studentId]);
    $latestAssignedYear = $statement->fetchColumn();
    return $latestAssignedYear !== false
        && $latestAssignedYear !== null
        && (int) $academicYear < (int) $latestAssignedYear;
}

function student_class_assignment_set(
    PDO $pdo,
    int $studentId,
    string $academicYear,
    string $classLevel,
    string $reason = 'manual',
    ?int $actorId = null,
    ?string $fromClass = null
): void {
    ensure_student_class_history_schema($pdo);
    if ($studentId <= 0 || !preg_match('/^\d{4}$/', $academicYear) || trim($classLevel) === '') {
        throw new InvalidArgumentException('A valid student, academic year, and class are required.');
    }

    $upsert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'ON CONFLICT(student_id, academic_year) DO UPDATE SET class_level = excluded.class_level, assigned_from_class = excluded.assigned_from_class, assignment_reason = excluded.assignment_reason, assigned_by = excluded.assigned_by'
        : 'ON DUPLICATE KEY UPDATE class_level = VALUES(class_level), assigned_from_class = VALUES(assigned_from_class), assignment_reason = VALUES(assignment_reason), assigned_by = VALUES(assigned_by)';
    $statement = $pdo->prepare(
        "INSERT INTO student_class_assignments
            (student_id, academic_year, class_level, assigned_from_class, assignment_reason, assigned_by)
         VALUES (:student_id, :academic_year, :class_level, :from_class, :reason, :actor_id) {$upsert}"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'class_level' => trim($classLevel),
        'from_class' => $fromClass,
        'reason' => $reason,
        'actor_id' => $actorId,
    ]);
}

function student_class_promotion_targets(): array
{
    return [
        'Playgroup' => 'PP1',
        'PP1' => 'PP2',
        'PP2' => 'Grade 1',
        'Grade 1' => 'Grade 2',
        'Grade 2' => 'Grade 3',
        'Grade 3' => 'Grade 4',
        'Grade 4' => 'Grade 5',
        'Grade 5' => 'Graduated',
        'Grade 6' => 'Grade 6',
    ];
}

function student_class_promotion_target(string $classLevel): string
{
    $targets = student_class_promotion_targets();
    foreach ($targets as $source => $target) {
        if (strcasecmp(trim($source), trim($classLevel)) === 0) {
            return $target;
        }
    }
    return trim($classLevel);
}

function student_class_promotion_preview(PDO $pdo, string $fromYear): array
{
    ensure_student_class_history_schema($pdo);
    if (!preg_match('/^\d{4}$/', $fromYear)) {
        throw new InvalidArgumentException('Choose a valid source academic year.');
    }

    $toYear = (string) ((int) $fromYear + 1);
    $statement = $pdo->prepare(
        "SELECT s.id, COALESCE(a.class_level, s.class_level) AS class_level,
                target.id AS target_assignment_id, target.assignment_reason AS target_reason,
                target.class_level AS target_assignment_class,
                s.enrollment_status, s.admitted_academic_year
         FROM students s
         LEFT JOIN student_class_assignments a
           ON a.student_id = s.id AND a.academic_year = :academic_year
         LEFT JOIN student_class_assignments target
           ON target.student_id = s.id AND target.academic_year = :to_year
         WHERE s.enrollment_status = 'Active'
         ORDER BY s.class_level, s.full_name, s.id"
    );
    $statement->execute(['academic_year' => $fromYear, 'to_year' => $toYear]);
    $before = [];
    $after = [];
    $total = 0;
    $alreadyAssigned = 0;
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $student) {
        $source = trim((string) ($student['class_level'] ?? '')) ?: 'Unassigned';
        $isNewAdmission = (string) ($student['admitted_academic_year'] ?? '') === $fromYear;
        $target = $isNewAdmission
            ? $source
            : ((int) ($student['target_assignment_id'] ?? 0) > 0
            ? (trim((string) ($student['target_assignment_class'] ?? '')) ?: $source)
            : ($source === 'Unassigned' ? $source : student_class_promotion_target($source)));
        if ($isNewAdmission || ((int) ($student['target_assignment_id'] ?? 0) > 0 && $student['target_reason'] !== 'promotion')) {
            $alreadyAssigned++;
        }
        $before[$source] = ($before[$source] ?? 0) + 1;
        $after[$target] = ($after[$target] ?? 0) + 1;
        $total++;
    }
    ksort($before, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($after, SORT_NATURAL | SORT_FLAG_CASE);

    return ['before' => $before, 'after' => $after, 'total' => $total, 'already_assigned' => $alreadyAssigned];
}

function student_promotion_decode_summary(array $promotion): array
{
    $summary = json_decode((string) ($promotion['counts_json'] ?? ''), true);
    return is_array($summary) ? $summary : [];
}

function student_complete_year_switch_in_transaction(PDO $pdo, array $promotion, string $activeYear, string $actorId): array
{
    $fromYear = (string) $promotion['from_year'];
    $toYear = (string) $promotion['to_year'];
    if (!in_array($activeYear, [$fromYear, $toYear], true)) {
        throw new RuntimeException("The active year is {$activeYear}. This promotion can only complete the {$fromYear} to {$toYear} transition.");
    }

    $itemCount = $pdo->prepare('SELECT COUNT(*) FROM student_class_promotion_items WHERE promotion_id = :promotion_id');
    $itemCount->execute(['promotion_id' => $promotion['id']]);
    $items = (int) $itemCount->fetchColumn();
    $summary = student_promotion_decode_summary($promotion);
    $expected = (int) ($summary['affected'] ?? $summary['promoted'] ?? $summary['total'] ?? 0);
    if ($expected > 0 && $items < $expected) {
        throw new RuntimeException('The saved promotion history is incomplete, so the year switch was not changed. Contact the system administrator to review the class-history records.');
    }

    if ($activeYear === $fromYear) {
        set_current_academic_context($pdo, $toYear, 'Term 1');
        $audit = $pdo->prepare(
            "INSERT INTO student_class_promotion_audit (promotion_id, actor_id, action, affected_students, details_json)
             VALUES (:promotion_id, :actor_id, 'year_switch_completed', :affected, :details)"
        );
        $audit->execute([
            'promotion_id' => $promotion['id'],
            'actor_id' => $actorId !== '' ? (int) $actorId : null,
            'affected' => $items,
            'details' => json_encode(['from_year' => $fromYear, 'to_year' => $toYear], JSON_THROW_ON_ERROR),
        ]);
    }

    $summary['promoted'] = (int) ($summary['promoted'] ?? $items);
    $summary['total'] = (int) ($summary['total'] ?? $items);
    $summary['resumed'] = $activeYear === $fromYear;
    $summary['active_year'] = $toYear;
    return $summary;
}

function student_complete_year_switch(PDO $pdo, string $fromYear, string $toYear, int $actorId): array
{
    ensure_student_class_history_schema($pdo);
    if ($actorId <= 0 || !preg_match('/^\d{4}$/', $fromYear) || !preg_match('/^\d{4}$/', $toYear) || (int) $toYear !== (int) $fromYear + 1) {
        throw new InvalidArgumentException('Choose a valid adjacent academic-year transition and signed-in administrator.');
    }

    ensure_academic_settings_table($pdo);
    ensure_academic_calendar_for_year($pdo, $toYear);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $activeYearStmt = $pdo->prepare('SELECT setting_value FROM academic_settings WHERE setting_key = :setting_key LIMIT 1' . $lockClause);
        $activeYearStmt->execute(['setting_key' => 'active_academic_year']);
        $activeYear = (string) ($activeYearStmt->fetchColumn() ?: '');
        $promotionStmt = $pdo->prepare('SELECT * FROM student_class_promotions WHERE from_year = :from_year AND to_year = :to_year LIMIT 1' . $lockClause);
        $promotionStmt->execute(['from_year' => $fromYear, 'to_year' => $toYear]);
        $promotion = $promotionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$promotion || $promotion['undone_at'] !== null) {
            throw new RuntimeException("No completed, reversible promotion was found for {$fromYear} to {$toYear}.");
        }
        $summary = student_complete_year_switch_in_transaction($pdo, $promotion, $activeYear, (string) $actorId);
        $pdo->commit();
        return $summary;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function student_class_promotion_period_dependencies(PDO $pdo, string $academicYear): array
{
    $dependencies = [];
    $tables = [
        'fees' => 'year',
        'fee_balances' => 'academic_year',
        'payment_allocations' => 'academic_year',
        'activity_payments' => 'academic_year',
        'activities' => 'academic_year',
        'feeding_subscriptions' => 'academic_year',
        'transport_accounts' => 'academic_year',
        'academic_marks' => 'academic_year',
        'academic_student_results' => 'academic_year',
        'academic_subject_results' => 'academic_year',
        'academic_report_cards' => 'academic_year',
        'student_fee_discounts' => 'academic_year',
    ];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach ($tables as $table => $column) {
        if ($driver === 'mysql') {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
            $exists->execute(['table_name' => $table]);
            if ((int) $exists->fetchColumn() === 0) {
                continue;
            }
            $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($column, $columns, true)) {
                continue;
            }
        } else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table_name");
            $exists->execute(['table_name' => $table]);
            if ((int) $exists->fetchColumn() === 0) {
                continue;
            }
            $columns = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
            if (!in_array($column, array_column($columns, 'name'), true)) {
                continue;
            }
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :academic_year");
        $count->execute(['academic_year' => $academicYear]);
        $rows = (int) $count->fetchColumn();
        if ($rows > 0) {
            $dependencies[$table] = $rows;
        }
    }
    return $dependencies;
}

function student_class_promotion_undo_safety(PDO $pdo, int $promotionId): array
{
    ensure_student_class_history_schema($pdo);
    $statement = $pdo->prepare('SELECT * FROM student_class_promotions WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $promotionId]);
    $promotion = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$promotion) {
        return ['allowed' => false, 'promotion' => null, 'students' => 0, 'reasons' => ['Promotion record not found.'], 'dependencies' => []];
    }
    if ($promotion['undone_at'] !== null) {
        return ['allowed' => false, 'promotion' => $promotion, 'students' => 0, 'reasons' => ['This promotion has already been undone.'], 'dependencies' => []];
    }

    $itemCount = $pdo->prepare('SELECT COUNT(*) FROM student_class_promotion_items WHERE promotion_id = :promotion_id');
    $itemCount->execute(['promotion_id' => $promotionId]);
    $students = (int) $itemCount->fetchColumn();
    $reasons = [];
    if ($students === 0) {
        $reasons[] = 'No per-student promotion snapshots are available to restore safely.';
    }

    $changed = $pdo->prepare(
        "SELECT COUNT(*)
         FROM student_class_promotion_items i
         LEFT JOIN students s ON s.id = i.student_id
         LEFT JOIN student_class_assignments target ON target.id = i.target_assignment_id
         LEFT JOIN student_class_assignments source ON source.id = i.source_assignment_id
         WHERE i.promotion_id = :promotion_id
           AND (s.id IS NULL OR target.id IS NULL OR source.id IS NULL
                OR s.class_level <> i.next_class OR s.enrollment_status <> i.next_status
                OR target.class_level <> i.next_class OR target.assignment_reason NOT IN ('promotion', 'promotion_carryover')
                OR source.class_level <> i.previous_class)"
    );
    $changed->execute(['promotion_id' => $promotionId]);
    if ((int) $changed->fetchColumn() > 0) {
        $reasons[] = 'One or more student or class-history records changed after promotion.';
    }

    $dependencies = student_class_promotion_period_dependencies($pdo, (string) $promotion['to_year']);
    if ($dependencies) {
        $labels = [];
        foreach ($dependencies as $table => $count) {
            $labels[] = str_replace('_', ' ', $table) . ': ' . $count;
        }
        $reasons[] = 'The destination year already has dependent records (' . implode(', ', $labels) . '). Undo is blocked to protect them.';
    }

    $activeYear = (string) (academic_setting_value($pdo, 'active_academic_year') ?? '');
    if (!in_array($activeYear, [(string) $promotion['from_year'], (string) $promotion['to_year']], true)) {
        $reasons[] = 'A later academic year is active; this older promotion cannot be undone.';
    }

    return [
        'allowed' => $reasons === [],
        'promotion' => $promotion,
        'students' => $students,
        'reasons' => $reasons,
        'dependencies' => $dependencies,
    ];
}

function student_class_promotion_admin_allowed(PDO $pdo, int $actorId): bool
{
    if ($actorId <= 0 || !function_exists('get_user_role_assignments')) {
        return false;
    }
    foreach (get_user_role_assignments($pdo, $actorId) as $role) {
        if (in_array(mb_strtolower(trim((string) $role)), ['admin', 'administrator', 'system administrator', 'director'], true)) {
            return true;
        }
    }
    return false;
}

function student_class_promotion_undo(PDO $pdo, int $promotionId, int $actorId, string $reason): int
{
    ensure_student_class_history_schema($pdo);
    $reason = trim($reason);
    if (!student_class_promotion_admin_allowed($pdo, $actorId)) {
        throw new RuntimeException('Only an administrator or Director can undo a student promotion.');
    }
    if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Enter an undo reason of at least 10 characters.');
    }

    ensure_academic_settings_table($pdo);
    $sourceYearStmt = $pdo->prepare('SELECT from_year FROM student_class_promotions WHERE id = :id LIMIT 1');
    $sourceYearStmt->execute(['id' => $promotionId]);
    $sourceYear = $sourceYearStmt->fetchColumn();
    if ($sourceYear !== false) {
        ensure_academic_calendar_for_year($pdo, (string) $sourceYear);
    }

    $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM student_class_promotions WHERE id = :id LIMIT 1' . $lockClause);
        $lock->execute(['id' => $promotionId]);
        $promotion = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$promotion) {
            throw new RuntimeException('The promotion record could not be found.');
        }
        $safety = student_class_promotion_undo_safety($pdo, $promotionId);
        if (!$safety['allowed']) {
            throw new RuntimeException(implode(' ', $safety['reasons']));
        }

        $itemsStmt = $pdo->prepare('SELECT * FROM student_class_promotion_items WHERE promotion_id = :promotion_id ORDER BY student_id' . $lockClause);
        $itemsStmt->execute(['promotion_id' => $promotionId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $restoreStudent = $pdo->prepare('UPDATE students SET class_level = :class_level, enrollment_status = :status WHERE id = :student_id');
        $restoreTarget = $pdo->prepare("UPDATE student_class_assignments SET class_level = :class_level, assignment_reason = 'promotion_undone', assigned_by = :actor_id WHERE id = :id");
        foreach ($items as $item) {
            $restoreStudent->execute([
                'class_level' => $item['previous_class'],
                'status' => $item['previous_status'],
                'student_id' => $item['student_id'],
            ]);
            $restoreTarget->execute([
                'class_level' => $item['previous_class'],
                'actor_id' => $actorId,
                'id' => $item['target_assignment_id'],
            ]);
        }

        $sourceTerm = (string) $promotion['source_term'];
        $activeYear = (string) (academic_setting_value($pdo, 'active_academic_year') ?? '');
        if ($activeYear === (string) $promotion['to_year']) {
            set_current_academic_context($pdo, (string) $promotion['from_year'], $sourceTerm);
        }
        $mark = $pdo->prepare('UPDATE student_class_promotions SET undone_at = CURRENT_TIMESTAMP, undone_by = :actor_id, undo_reason = :reason WHERE id = :id AND undone_at IS NULL');
        $mark->execute(['actor_id' => $actorId, 'reason' => $reason, 'id' => $promotionId]);
        if ($mark->rowCount() !== 1) {
            throw new RuntimeException('This promotion has already been undone.');
        }
        $audit = $pdo->prepare(
            "INSERT INTO student_class_promotion_audit (promotion_id, actor_id, action, reason, affected_students, details_json)
             VALUES (:promotion_id, :actor_id, 'promotion_undone', :reason, :affected, :details)"
        );
        $audit->execute([
            'promotion_id' => $promotionId,
            'actor_id' => $actorId,
            'reason' => $reason,
            'affected' => count($items),
            'details' => json_encode(['from_year' => $promotion['from_year'], 'to_year' => $promotion['to_year']], JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
        if (function_exists('rbac_audit_log')) {
            rbac_audit_log($pdo, $actorId, 'academic.promotion_undo', 'Academic Calendar', true, 'WARN', [
                'promotion_id' => $promotionId,
                'from_year' => $promotion['from_year'],
                'to_year' => $promotion['to_year'],
                'affected_students' => count($items),
                'reason' => $reason,
            ]);
        }
        return count($items);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function student_promote_and_switch_year(
    PDO $pdo,
    string $fromYear,
    string $toYear,
    int $actorId
): array {
    ensure_student_class_history_schema($pdo);
    if (!preg_match('/^\d{4}$/', $fromYear) || !preg_match('/^\d{4}$/', $toYear) || (int) $toYear !== (int) $fromYear + 1) {
        throw new InvalidArgumentException('Students can only be promoted to the immediately following academic year.');
    }
    if ($actorId <= 0) {
        throw new InvalidArgumentException('A signed-in administrator is required to promote students.');
    }

    ensure_academic_calendar_for_year($pdo, $toYear);
    ensure_academic_settings_table($pdo);
    $targetTermExists = $pdo->prepare(
        "SELECT COUNT(*) FROM academic_calendar
         WHERE academic_year = :academic_year AND term_name = 'Term 1'"
    );
    $targetTermExists->execute(['academic_year' => $toYear]);
    if ((int) $targetTermExists->fetchColumn() === 0) {
        throw new RuntimeException("Add Term 1 for {$toYear} to the academic calendar before promoting students.");
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $activeYearStmt = $pdo->prepare(
            'SELECT setting_value FROM academic_settings WHERE setting_key = :setting_key LIMIT 1' . $lockClause
        );
        $activeYearStmt->execute(['setting_key' => 'active_academic_year']);
        $activeYear = $activeYearStmt->fetchColumn();
        if ($activeYear === false) {
            $activeYear = $fromYear;
        }
        if (!in_array((string) $activeYear, [$fromYear, $toYear], true)) {
            throw new RuntimeException('The active academic year changed. Reload the page before promoting students.');
        }

        $activeTermStmt = $pdo->prepare(
            'SELECT setting_value FROM academic_settings WHERE setting_key = :setting_key LIMIT 1' . $lockClause
        );
        $activeTermStmt->execute(['setting_key' => 'active_term']);
        $sourceTerm = $activeTermStmt->fetchColumn();

        $existing = $pdo->prepare('SELECT * FROM student_class_promotions WHERE from_year = :from_year AND to_year = :to_year LIMIT 1' . $lockClause);
        $existing->execute(['from_year' => $fromYear, 'to_year' => $toYear]);
        $existingPromotion = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingPromotion) {
            if ($existingPromotion['undone_at'] !== null) {
                throw new RuntimeException("The {$fromYear} to {$toYear} promotion was undone. It cannot be repeated for the same academic-year transition.");
            }
            $summary = student_complete_year_switch_in_transaction($pdo, $existingPromotion, (string) $activeYear, (string) $actorId);
            $pdo->commit();
            return $summary;
        }
        if ((string) $activeYear !== $fromYear) {
            throw new RuntimeException("The active year is already {$toYear}, but no completed promotion was found. No students were changed.");
        }

        $studentsStmt = $pdo->prepare(
            "SELECT s.id, COALESCE(a.class_level, s.class_level) AS source_class,
                    s.class_level AS global_class, s.enrollment_status, s.admitted_academic_year,
                    a.id AS source_assignment_id, target.id AS target_assignment_id,
                    target.assignment_reason AS target_reason,
                    target.class_level AS target_assignment_class
             FROM students s
             LEFT JOIN student_class_assignments a
               ON a.student_id = s.id AND a.academic_year = :from_year
             LEFT JOIN student_class_assignments target
               ON target.student_id = s.id AND target.academic_year = :to_year
             WHERE s.enrollment_status = 'Active'
             ORDER BY s.id" . $lockClause
        );
        $studentsStmt->execute(['from_year' => $fromYear, 'to_year' => $toYear]);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
        $sourceUpsert = $driver === 'sqlite'
            ? 'ON CONFLICT(student_id, academic_year) DO NOTHING'
            : 'ON DUPLICATE KEY UPDATE student_id = VALUES(student_id)';
        $setSource = $pdo->prepare(
            "INSERT INTO student_class_assignments
                (student_id, academic_year, class_level, assignment_reason, assigned_by)
             VALUES (:student_id, :academic_year, :class_level, 'promotion_source', :actor_id) {$sourceUpsert}"
        );
        $targetUpsert = $driver === 'sqlite'
            ? 'ON CONFLICT(student_id, academic_year) DO NOTHING'
            : '';
        $setTarget = $pdo->prepare(
            "INSERT INTO student_class_assignments
                (student_id, academic_year, class_level, assigned_from_class, assignment_reason, assigned_by)
             VALUES (:student_id, :academic_year, :class_level, :from_class, 'promotion', :actor_id) {$targetUpsert}"
        );
        $setCarryover = $pdo->prepare(
            "INSERT INTO student_class_assignments
                (student_id, academic_year, class_level, assigned_from_class, assignment_reason, assigned_by)
             VALUES (:student_id, :academic_year, :class_level, :from_class, 'promotion_carryover', :actor_id) {$targetUpsert}"
        );
        $updateStudent = $pdo->prepare('UPDATE students SET class_level = :class_level, enrollment_status = :status WHERE id = :student_id');
        $insertItem = $pdo->prepare(
            'INSERT INTO student_class_promotion_items
                (promotion_id, student_id, previous_class, next_class, previous_status, next_status, source_assignment_id, target_assignment_id)
             VALUES (:promotion_id, :student_id, :previous_class, :next_class, :previous_status, :next_status, :source_assignment_id, :target_assignment_id)'
        );
        $before = [];
        $after = [];
        $changed = 0;
        $graduated = 0;
        $alreadyAssigned = 0;
        $itemsAffected = 0;
        $promotionId = 0;

        $promotionInsert = $pdo->prepare(
            'INSERT INTO student_class_promotions (from_year, to_year, source_term, target_term, promoted_by, counts_json)
             VALUES (:from_year, :to_year, :source_term, :target_term, :promoted_by, :counts_json)'
        );
        $promotionInsert->execute([
            'from_year' => $fromYear,
            'to_year' => $toYear,
            'source_term' => $sourceTerm === false ? 'Term 3' : (string) $sourceTerm,
            'target_term' => 'Term 1',
            'promoted_by' => $actorId,
            'counts_json' => '{}',
        ]);
        $promotionId = (int) $pdo->lastInsertId();

        foreach ($students as $student) {
            $source = trim((string) ($student['source_class'] ?? '')) ?: 'Unassigned';
            $studentId = (int) $student['id'];
            $before[$source] = ($before[$source] ?? 0) + 1;

            // A destination-year assignment marks a new admission or a previously assigned student.
            // Preserve it instead of promoting that student again.
            if ((int) ($student['target_assignment_id'] ?? 0) > 0) {
                $alreadyAssigned++;
                $assignedClass = trim((string) ($student['target_assignment_class'] ?? ''));
                $after[$assignedClass !== '' ? $assignedClass : $source] = ($after[$assignedClass !== '' ? $assignedClass : $source] ?? 0) + 1;
                continue;
            }

            if ((string) ($student['admitted_academic_year'] ?? '') === $fromYear) {
                $alreadyAssigned++;
                $after[$source] = ($after[$source] ?? 0) + 1;
                if ($source !== 'Unassigned') {
                    $setSource->execute([
                        'student_id' => $studentId,
                        'academic_year' => $fromYear,
                        'class_level' => $source,
                        'actor_id' => $actorId,
                    ]);
                    $setCarryover->execute([
                        'student_id' => $studentId,
                        'academic_year' => $toYear,
                        'class_level' => $source,
                        'from_class' => $source,
                        'actor_id' => $actorId,
                    ]);
                    $sourceLookup = $pdo->prepare('SELECT id FROM student_class_assignments WHERE student_id = :student_id AND academic_year = :academic_year LIMIT 1');
                    $sourceLookup->execute(['student_id' => $studentId, 'academic_year' => $fromYear]);
                    $targetLookup = $pdo->prepare('SELECT id FROM student_class_assignments WHERE student_id = :student_id AND academic_year = :academic_year LIMIT 1');
                    $targetLookup->execute(['student_id' => $studentId, 'academic_year' => $toYear]);
                    $insertItem->execute([
                        'promotion_id' => $promotionId,
                        'student_id' => $studentId,
                        'previous_class' => $source,
                        'next_class' => $source,
                        'previous_status' => (string) $student['enrollment_status'],
                        'next_status' => (string) $student['enrollment_status'],
                        'source_assignment_id' => (int) $sourceLookup->fetchColumn(),
                        'target_assignment_id' => (int) $targetLookup->fetchColumn(),
                    ]);
                    $itemsAffected++;
                }
                continue;
            }

            if ($source === 'Unassigned') {
                $after[$source] = ($after[$source] ?? 0) + 1;
                continue;
            }

            $promotionTarget = student_class_promotion_target($source);
            $targetStatus = $promotionTarget === 'Graduated' ? 'Graduated' : (string) $student['enrollment_status'];
            $targetClass = $promotionTarget === 'Graduated' ? $source : $promotionTarget;
            $after[$promotionTarget] = ($after[$promotionTarget] ?? 0) + 1;

            if ($source !== 'Unassigned') {
                $setSource->execute([
                    'student_id' => $studentId,
                    'academic_year' => $fromYear,
                    'class_level' => $source,
                    'actor_id' => $actorId,
                ]);
                $setTarget->execute([
                    'student_id' => $studentId,
                    'academic_year' => $toYear,
                    'class_level' => $targetClass,
                    'from_class' => $source,
                    'actor_id' => $actorId,
                ]);
                $sourceId = (int) ($student['source_assignment_id'] ?? 0);
                if ($sourceId <= 0) {
                    $sourceLookup = $pdo->prepare('SELECT id FROM student_class_assignments WHERE student_id = :student_id AND academic_year = :academic_year LIMIT 1');
                    $sourceLookup->execute(['student_id' => $studentId, 'academic_year' => $fromYear]);
                    $sourceId = (int) $sourceLookup->fetchColumn();
                }
                $targetLookup = $pdo->prepare('SELECT id FROM student_class_assignments WHERE student_id = :student_id AND academic_year = :academic_year LIMIT 1');
                $targetLookup->execute(['student_id' => $studentId, 'academic_year' => $toYear]);
                $targetId = (int) $targetLookup->fetchColumn();
                $updateStudent->execute(['class_level' => $targetClass, 'status' => $targetStatus, 'student_id' => $studentId]);
                $insertItem->execute([
                    'promotion_id' => $promotionId,
                    'student_id' => $studentId,
                    'previous_class' => $source,
                    'next_class' => $targetClass,
                    'previous_status' => (string) $student['enrollment_status'],
                    'next_status' => $targetStatus,
                    'source_assignment_id' => $sourceId > 0 ? $sourceId : null,
                    'target_assignment_id' => $targetId > 0 ? $targetId : null,
                ]);
                $itemsAffected++;
                if ($targetStatus === 'Graduated') {
                    $graduated++;
                }
                if (strcasecmp($source, $targetClass) !== 0 || $targetStatus !== (string) $student['enrollment_status']) {
                    $changed++;
                }
            }
        }

        $summary = [
            'before' => $before,
            'after' => $after,
            'total' => count($students),
            'promoted' => $changed,
            'affected' => $itemsAffected,
            'graduated' => $graduated,
            'already_assigned' => $alreadyAssigned,
        ];
        $saveSummary = $pdo->prepare('UPDATE student_class_promotions SET counts_json = :counts_json WHERE id = :id');
        $saveSummary->execute(['counts_json' => json_encode($summary, JSON_THROW_ON_ERROR), 'id' => $promotionId]);

        $settingUpsert = $driver === 'sqlite'
            ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        $saveSetting = $pdo->prepare(
            "INSERT INTO academic_settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value) {$settingUpsert}"
        );
        $saveSetting->execute(['setting_key' => 'active_academic_year', 'setting_value' => $toYear]);
        $saveSetting->execute(['setting_key' => 'active_term', 'setting_value' => 'Term 1']);
        $audit = $pdo->prepare(
            "INSERT INTO student_class_promotion_audit (promotion_id, actor_id, action, affected_students, details_json)
             VALUES (:promotion_id, :actor_id, 'promotion_completed', :affected, :details)"
        );
        $audit->execute([
            'promotion_id' => $promotionId,
            'actor_id' => $actorId,
            'affected' => $itemsAffected,
            'details' => json_encode(['from_year' => $fromYear, 'to_year' => $toYear, 'graduated' => $graduated], JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
        return $summary;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
