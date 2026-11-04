<?php
require_once __DIR__ . '/fee_helpers.php';

function academic_exam_types(): array
{
    return ['Opening', 'Midterm', 'Closing'];
}

function academic_exam_type_labels(): array
{
    return [
        'Opening' => 'Opening Exam',
        'Midterm' => 'Midterm Exam',
        'Closing' => 'Closing Exam',
    ];
}

function academic_default_grade_boundaries(): array
{
    return [
        90 => '8',
        75 => '7',
        58 => '6',
        41 => '5',
        31 => '4',
        21 => '3',
        11 => '2',
        0 => '1',
    ];
}

function academic_grade_boundaries(?PDO $pdo = null): array
{
    if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if (!$pdo instanceof PDO) {
        return academic_default_grade_boundaries();
    }

    try {
        ensure_academic_grading_table($pdo);
        $rows = $pdo->query(
            "SELECT min_score, grade
             FROM academic_grading_scales
             ORDER BY min_score DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return academic_default_grade_boundaries();
        }

        $boundaries = [];
        foreach ($rows as $row) {
            $boundaries[(int) round((float) $row['min_score'])] = (string) $row['grade'];
        }

        return $boundaries ?: academic_default_grade_boundaries();
    } catch (Throwable $exception) {
        return academic_default_grade_boundaries();
    }
}

function academic_grade_scale_rows(?PDO $pdo = null): array
{
    if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if (!$pdo instanceof PDO) {
        return [
            ['min_score' => 90.0, 'max_score' => 100.0, 'grade' => '8', 'remark' => 'Exceeding Expectations'],
            ['min_score' => 75.0, 'max_score' => 89.0, 'grade' => '7', 'remark' => 'Exceeding Expectations'],
            ['min_score' => 58.0, 'max_score' => 74.0, 'grade' => '6', 'remark' => 'Meeting Expectations'],
            ['min_score' => 41.0, 'max_score' => 57.0, 'grade' => '5', 'remark' => 'Meeting Expectations'],
            ['min_score' => 31.0, 'max_score' => 40.0, 'grade' => '4', 'remark' => 'Approaching Expectations'],
            ['min_score' => 21.0, 'max_score' => 30.0, 'grade' => '3', 'remark' => 'Approaching Expectations'],
            ['min_score' => 11.0, 'max_score' => 20.0, 'grade' => '2', 'remark' => 'Below Expectations'],
            ['min_score' => 0.0, 'max_score' => 10.0, 'grade' => '1', 'remark' => 'Below Expectations'],
        ];
    }

    try {
        ensure_academic_grading_table($pdo);
        $rows = $pdo->query(
            "SELECT min_score, max_score, grade, remark
             FROM academic_grading_scales
             ORDER BY display_order ASC, min_score DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return academic_grade_scale_rows(null);
        }

        return $rows;
    } catch (Throwable $exception) {
        return academic_grade_scale_rows(null);
    }
}

function academic_grade_for_score(float $score, ?PDO $pdo = null): string
{
    foreach (academic_grade_scale_rows($pdo) as $row) {
        if ($score >= (float) $row['min_score']) {
            return (string) $row['grade'];
        }
    }

    $rows = academic_grade_scale_rows($pdo);
    $lastRow = end($rows);
    return $lastRow ? (string) $lastRow['grade'] : '1';
}

function academic_grade_expectation_label(string $grade): string
{
    $grade = strtoupper(trim($grade));
    
    if ($grade === '8' || $grade === '7') {
        return 'Exceeding Expectations';
    }
    if ($grade === '6' || $grade === '5') {
        return 'Meeting Expectations';
    }
    if ($grade === '4' || $grade === '3') {
        return 'Approaching Expectations';
    }
    if ($grade === '2' || $grade === '1') {
        return 'Below Expectations';
    }

    return 'Below Expectations';
}

function academic_grade_expectation_ranges(): array
{
    $ranges = [];
    foreach (academic_grade_scale_rows() as $row) {
        $ranges[$row['grade']] = sprintf('%s - %s%%', (int) $row['min_score'], (int) $row['max_score']);
    }
    return $ranges;
}

function academic_percentage_to_points(float $score, ?PDO $pdo = null): int
{
    $grade = academic_grade_for_score($score, $pdo);
    return (int) $grade ?: 1;
}

function academic_point_to_performance_level(int $points): string
{
    if ($points >= 7) {
        return 'Exceeding Expectations';
    }
    if ($points >= 5) {
        return 'Meeting Expectations';
    }
    if ($points >= 3) {
        return 'Approaching Expectations';
    }
    return 'Below Expectations';
}

function academic_mean_points_to_performance_level(float $meanPoints): string
{
    return academic_point_to_performance_level((int) max(1, min(8, round($meanPoints))));
}

function academic_grade_for_mean_points(float $meanPoints): string
{
    return (string) max(1, min(8, (int) round($meanPoints)));
}

function academic_subject_comment_for_points(int $points): string
{
    if ($points >= 7) {
        return 'Excellent performance; maintain the standard.';
    }
    if ($points >= 5) {
        return 'Meets expected standards; keep improving.';
    }
    if ($points >= 3) {
        return 'Progressing; more effort is needed.';
    }
    return 'Immediate support required.';
}

function academic_grade_point_descriptors(?PDO $pdo = null): array
{
    $rows = academic_grade_scale_rows($pdo);
    $descriptors = [];

    foreach ($rows as $row) {
        $descriptors[] = [
            'grade' => (string) $row['grade'],
            'label' => academic_grade_expectation_label($row['grade']),
            'points' => (int) $row['grade'],
            'range' => sprintf('%d-%d', (int) $row['min_score'], (int) $row['max_score']),
            'remark' => (string) ($row['remark'] ?? ''),
        ];
    }

    return $descriptors;
}

function calculatePerformanceFromPercentage(float $percentage, ?PDO $pdo = null): array
{
    $grade = academic_grade_for_score($percentage, $pdo);
    $points = (int) $grade ?: 1;
    $label = academic_grade_expectation_label($grade);
    
    return [
        'percentage' => round($percentage, 2),
        'grade' => $grade,
        'points' => $points,
        'performance_level' => $label,
    ];
}

function academic_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table"
    );
    $statement->execute(['table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function academic_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (function_exists('db_has_column')) {
        return db_has_column($pdo, $table, $column);
    }

    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column"
    );
    $statement->execute(['table' => $table, 'column' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function academic_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND INDEX_NAME = :index_name"
    );
    $statement->execute(['table' => $table, 'index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function academic_constraint_exists(PDO $pdo, string $constraint): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = :constraint_name"
    );
    $statement->execute(['constraint_name' => $constraint]);
    return (int) $statement->fetchColumn() > 0;
}

function academic_try_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $ignored) {
    }
}

function academic_ensure_class_levels_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS class_levels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_class_levels_name (name)
        ) ENGINE=InnoDB"
    );

    $defaults = ['Playgroup', 'PP1', 'PP2', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
    $insert = $pdo->prepare("INSERT IGNORE INTO class_levels (name, status) VALUES (:name, 'Active')");
    foreach ($defaults as $level) {
        $insert->execute(['name' => $level]);
    }
}

function ensure_playgroup_exists(PDO $pdo): void
{
    academic_ensure_class_levels_table($pdo);
    
    $check = $pdo->prepare("SELECT COUNT(*) FROM class_levels WHERE name = 'Playgroup'");
    $check->execute();
    
    if ((int) $check->fetchColumn() === 0) {
        $insert = $pdo->prepare("INSERT INTO class_levels (name, status) VALUES ('Playgroup', 'Active')");
        $insert->execute();
    }
}

function ensure_academic_subjects_table(PDO $pdo): void
{
    academic_ensure_class_levels_table($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_subjects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(20) NULL,
            teacher_name VARCHAR(100) NULL,
            class_id INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_subject_name (name),
            KEY idx_academic_subjects_status (status),
            CONSTRAINT fk_academic_subjects_class
              FOREIGN KEY (class_id) REFERENCES class_levels(id)
              ON DELETE SET NULL
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    if (!academic_column_exists($pdo, 'academic_subjects', 'class_id')) {
        academic_try_exec($pdo, "ALTER TABLE academic_subjects ADD COLUMN class_id INT UNSIGNED NULL AFTER code");
    }
    if (!academic_column_exists($pdo, 'academic_subjects', 'teacher_name')) {
        academic_try_exec($pdo, "ALTER TABLE academic_subjects ADD COLUMN teacher_name VARCHAR(100) NULL AFTER code");
    }
    if (!academic_column_exists($pdo, 'academic_subjects', 'status')) {
        academic_try_exec($pdo, "ALTER TABLE academic_subjects ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER class_id");
    }
    if (!academic_constraint_exists($pdo, 'fk_academic_subjects_class')) {
        academic_try_exec(
            $pdo,
            "ALTER TABLE academic_subjects
             ADD CONSTRAINT fk_academic_subjects_class
             FOREIGN KEY (class_id) REFERENCES class_levels(id)
             ON DELETE SET NULL ON UPDATE CASCADE"
        );
    }
}

function ensure_academic_class_subjects_table(PDO $pdo): void
{
    ensure_academic_subjects_table($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_class_subjects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            class_level_id INT UNSIGNED NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_class_subject (class_level_id, subject_id),
            KEY idx_academic_class_subjects_subject (subject_id),
            CONSTRAINT fk_academic_class_subjects_class
              FOREIGN KEY (class_level_id) REFERENCES class_levels(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE,
            CONSTRAINT fk_academic_class_subjects_subject
              FOREIGN KEY (subject_id) REFERENCES academic_subjects(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "INSERT IGNORE INTO academic_class_subjects (class_level_id, subject_id)
         SELECT class_id, id
         FROM academic_subjects
         WHERE class_id IS NOT NULL"
    );
}

function ensure_academic_exams_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_exams (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            exam_type VARCHAR(20) NOT NULL,
            exam_name VARCHAR(80) NOT NULL,
            max_marks DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            status VARCHAR(20) NOT NULL DEFAULT 'Open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_exam_period_type (academic_year, term, exam_type),
            KEY idx_academic_exams_period (academic_year, term)
        ) ENGINE=InnoDB"
    );
}

function ensure_academic_grading_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_grading_scales (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            min_score DECIMAL(5,2) NOT NULL,
            max_score DECIMAL(5,2) NOT NULL,
            grade VARCHAR(5) NOT NULL,
            remark VARCHAR(120) NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_grade_name (grade),
            KEY idx_academic_grading_range (min_score, max_score)
        ) ENGINE=InnoDB"
    );

    $count = (int) $pdo->query("SELECT COUNT(*) FROM academic_grading_scales")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaults = [
        [90, 100, '8', 'Exceeding Expectations'],
        [75, 89, '7', 'Exceeding Expectations'],
        [58, 74, '6', 'Meeting Expectations'],
        [41, 57, '5', 'Meeting Expectations'],
        [31, 40, '4', 'Approaching Expectations'],
        [21, 30, '3', 'Approaching Expectations'],
        [11, 20, '2', 'Below Expectations'],
        [0, 10, '1', 'Below Expectations'],
    ];
    $insert = $pdo->prepare(
        "INSERT INTO academic_grading_scales (min_score, max_score, grade, remark, display_order)
         VALUES (:min_score, :max_score, :grade, :remark, :display_order)"
    );
    foreach ($defaults as $index => $row) {
        $insert->execute([
            'min_score' => $row[0],
            'max_score' => $row[1],
            'grade' => $row[2],
            'remark' => $row[3],
            'display_order' => $index + 1,
        ]);
    }
}

function ensure_academic_marks_table(PDO $pdo): void
{
    ensure_academic_subjects_table($pdo);
    ensure_academic_exams_table($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_marks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            exam_id INT UNSIGNED NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            exam_type VARCHAR(20) NOT NULL,
            marks DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            remarks VARCHAR(255) NULL,
            recorded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_mark_entry (student_id, subject_id, academic_year, term, exam_type),
            KEY idx_academic_marks_exam (exam_id),
            KEY idx_academic_marks_period (academic_year, term),
            CONSTRAINT fk_academic_marks_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE,
            CONSTRAINT fk_academic_marks_subject
              FOREIGN KEY (subject_id) REFERENCES academic_subjects(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE,
            CONSTRAINT fk_academic_marks_exam
              FOREIGN KEY (exam_id) REFERENCES academic_exams(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    if (!academic_column_exists($pdo, 'academic_marks', 'exam_id')) {
        academic_try_exec($pdo, "ALTER TABLE academic_marks ADD COLUMN exam_id INT UNSIGNED NULL AFTER subject_id");
    }
    if (!academic_column_exists($pdo, 'academic_marks', 'recorded_by')) {
        academic_try_exec($pdo, "ALTER TABLE academic_marks ADD COLUMN recorded_by INT UNSIGNED NULL AFTER remarks");
    }
    if (!academic_index_exists($pdo, 'academic_marks', 'idx_academic_marks_exam')) {
        academic_try_exec($pdo, "ALTER TABLE academic_marks ADD KEY idx_academic_marks_exam (exam_id)");
    }
    if (!academic_index_exists($pdo, 'academic_marks', 'idx_academic_marks_period')) {
        academic_try_exec($pdo, "ALTER TABLE academic_marks ADD KEY idx_academic_marks_period (academic_year, term)");
    }
    if (!academic_index_exists($pdo, 'academic_marks', 'uniq_academic_mark_student_subject_exam')) {
        academic_try_exec(
            $pdo,
            "ALTER TABLE academic_marks
             ADD UNIQUE KEY uniq_academic_mark_student_subject_exam (student_id, subject_id, exam_id)"
        );
    }
    if (!academic_constraint_exists($pdo, 'fk_academic_marks_exam')) {
        academic_try_exec(
            $pdo,
            "ALTER TABLE academic_marks
             ADD CONSTRAINT fk_academic_marks_exam
             FOREIGN KEY (exam_id) REFERENCES academic_exams(id)
             ON DELETE CASCADE ON UPDATE CASCADE"
        );
    }
}

function ensure_academic_results_tables(PDO $pdo): void
{
    ensure_academic_marks_table($pdo);
    ensure_academic_grading_table($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_student_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            class_level VARCHAR(30) NOT NULL,
            student_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            grade VARCHAR(5) NOT NULL DEFAULT '1',
            class_position INT UNSIGNED NULL,
            subject_count INT UNSIGNED NOT NULL DEFAULT 0,
            marks_count INT UNSIGNED NOT NULL DEFAULT 0,
            calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_student_result (student_id, academic_year, term),
            KEY idx_academic_student_results_period_class (academic_year, term, class_level),
            CONSTRAINT fk_academic_student_results_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_subject_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            class_level VARCHAR(30) NOT NULL,
            opening_marks DECIMAL(5,2) NULL,
            midterm_marks DECIMAL(5,2) NULL,
            closing_marks DECIMAL(5,2) NULL,
            subject_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            grade VARCHAR(5) NOT NULL DEFAULT '1',
            subject_position INT UNSIGNED NULL,
            marks_count INT UNSIGNED NOT NULL DEFAULT 0,
            calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_subject_result (student_id, subject_id, academic_year, term),
            KEY idx_academic_subject_results_period_subject (academic_year, term, subject_id),
            KEY idx_academic_subject_results_class_subject (academic_year, term, class_level, subject_id),
            CONSTRAINT fk_academic_subject_results_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE,
            CONSTRAINT fk_academic_subject_results_subject
              FOREIGN KEY (subject_id) REFERENCES academic_subjects(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    foreach ([
        'class_level' => "ALTER TABLE academic_subject_results ADD COLUMN class_level VARCHAR(30) NOT NULL DEFAULT '' AFTER term",
        'marks_count' => "ALTER TABLE academic_subject_results ADD COLUMN marks_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER subject_position",
    ] as $column => $sql) {
        if (!academic_column_exists($pdo, 'academic_subject_results', $column)) {
            academic_try_exec($pdo, $sql);
        }
    }
}

function ensure_academic_report_cards_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_report_cards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            teacher_comment TEXT NULL,
            head_teacher_comment TEXT NULL,
            principal_comment TEXT NULL,
            generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_report_card (student_id, academic_year, term),
            CONSTRAINT fk_academic_report_card_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    if (!academic_column_exists($pdo, 'academic_report_cards', 'head_teacher_comment')) {
        academic_try_exec($pdo, "ALTER TABLE academic_report_cards ADD COLUMN head_teacher_comment TEXT NULL AFTER teacher_comment");
        if (academic_column_exists($pdo, 'academic_report_cards', 'principal_comment')) {
            academic_try_exec(
                $pdo,
                "UPDATE academic_report_cards
                 SET head_teacher_comment = principal_comment
                 WHERE (head_teacher_comment IS NULL OR head_teacher_comment = '')
                   AND principal_comment IS NOT NULL"
            );
        }
    }
    if (!academic_column_exists($pdo, 'academic_report_cards', 'updated_at')) {
        academic_try_exec(
            $pdo,
            "ALTER TABLE academic_report_cards
             ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER generated_at"
        );
    }
}

function ensure_academic_module_schema(PDO $pdo): void
{
    ensure_academic_class_subjects_table($pdo);
    ensure_academic_exams_table($pdo);
    ensure_academic_grading_table($pdo);
    ensure_academic_marks_table($pdo);
    ensure_academic_results_tables($pdo);
    ensure_academic_report_cards_table($pdo);
    ensure_academic_settings_table($pdo);
    academic_migrate_marks_to_exams($pdo);
}

function academic_validate_period(string $academicYear, string $term): void
{
    if (!preg_match('/^\d{4}$/', $academicYear)) {
        throw new InvalidArgumentException('Academic year must be four digits.');
    }
    if (!in_array($term, term_options(), true)) {
        throw new InvalidArgumentException('Choose a valid term.');
    }
}

function academic_exam_label(string $examType): string
{
    $labels = academic_exam_type_labels();
    return $labels[$examType] ?? $examType;
}

function ensure_academic_exams_for_period(PDO $pdo, string $academicYear, string $term): array
{
    ensure_academic_exams_table($pdo);
    academic_validate_period($academicYear, $term);

    $insert = $pdo->prepare(
        "INSERT INTO academic_exams (academic_year, term, exam_type, exam_name, max_marks, status)
         VALUES (:academic_year, :term, :exam_type, :exam_name, 100.00, 'Open')
         ON DUPLICATE KEY UPDATE
             exam_name = VALUES(exam_name),
             id = LAST_INSERT_ID(id)"
    );
    foreach (academic_exam_types() as $examType) {
        $insert->execute([
            'academic_year' => $academicYear,
            'term' => $term,
            'exam_type' => $examType,
            'exam_name' => academic_exam_label($examType),
        ]);
    }

    return academic_exams_for_period($pdo, $academicYear, $term);
}

function academic_exams_for_period(PDO $pdo, string $academicYear, string $term): array
{
    ensure_academic_exams_table($pdo);
    academic_validate_period($academicYear, $term);

    $statement = $pdo->prepare(
        "SELECT *
         FROM academic_exams
         WHERE academic_year = :academic_year
           AND term = :term
         ORDER BY FIELD(exam_type, 'Opening', 'Midterm', 'Closing'), id ASC"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < count(academic_exam_types())) {
        return ensure_academic_exams_for_period($pdo, $academicYear, $term);
    }

    return $rows;
}

function academic_exam_for_period(PDO $pdo, string $academicYear, string $term, string $examType): array
{
    if (!in_array($examType, academic_exam_types(), true)) {
        throw new InvalidArgumentException('Invalid exam type.');
    }

    ensure_academic_exams_for_period($pdo, $academicYear, $term);
    $statement = $pdo->prepare(
        "SELECT *
         FROM academic_exams
         WHERE academic_year = :academic_year
           AND term = :term
           AND exam_type = :exam_type
         LIMIT 1"
    );
    $statement->execute([
        'academic_year' => $academicYear,
        'term' => $term,
        'exam_type' => $examType,
    ]);
    $exam = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        throw new RuntimeException('Academic exam could not be created.');
    }

    return $exam;
}

function academic_migrate_marks_to_exams(PDO $pdo): void
{
    if (!academic_table_exists($pdo, 'academic_marks') || !academic_column_exists($pdo, 'academic_marks', 'exam_id')) {
        return;
    }

    $rows = $pdo->query(
        "SELECT DISTINCT academic_year, term, exam_type
         FROM academic_marks
         WHERE academic_year REGEXP '^[0-9]{4}$'
           AND term IN ('Term 1', 'Term 2', 'Term 3')
           AND exam_type IN ('Opening', 'Midterm', 'Closing')"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        ensure_academic_exams_for_period($pdo, $row['academic_year'], $row['term']);
    }

    academic_try_exec(
        $pdo,
        "UPDATE academic_marks m
         JOIN academic_exams e
           ON e.academic_year = m.academic_year
          AND e.term = m.term
          AND e.exam_type = m.exam_type
         SET m.exam_id = e.id
         WHERE m.exam_id IS NULL OR m.exam_id = 0"
    );
}

function get_academic_class_levels(PDO $pdo): array
{
    academic_ensure_class_levels_table($pdo);
    $statement = $pdo->query("SELECT id, name FROM class_levels WHERE status = 'Active' ORDER BY name ASC");
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_class_level_id_by_name(PDO $pdo, string $classLevel): ?int
{
    academic_ensure_class_levels_table($pdo);
    $statement = $pdo->prepare("SELECT id FROM class_levels WHERE name = :name LIMIT 1");
    $statement->execute(['name' => $classLevel]);
    $id = $statement->fetchColumn();
    return $id === false ? null : (int) $id;
}

function academic_get_student(PDO $pdo, int $studentId): ?array
{
    $statement = $pdo->prepare("SELECT * FROM students WHERE id = :id LIMIT 1");
    $statement->execute(['id' => $studentId]);
    $student = $statement->fetch(PDO::FETCH_ASSOC);
    return $student ?: null;
}

function get_academic_subjects(PDO $pdo): array
{
    ensure_academic_class_subjects_table($pdo);
    $statement = $pdo->query(
        "SELECT
            s.id,
            s.name,
            s.code,
            s.teacher_name,
            s.class_id,
            s.status,
            GROUP_CONCAT(cl.name ORDER BY cl.name SEPARATOR ', ') AS class_names,
            GROUP_CONCAT(cl.id ORDER BY cl.name SEPARATOR ',') AS class_ids
         FROM academic_subjects s
         LEFT JOIN academic_class_subjects acs ON acs.subject_id = s.id
         LEFT JOIN class_levels cl ON cl.id = acs.class_level_id
         GROUP BY s.id, s.name, s.code, s.teacher_name, s.class_id, s.status
         ORDER BY s.name ASC"
    );
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function get_academic_subjects_simple(PDO $pdo): array
{
    ensure_academic_subjects_table($pdo);
    $statement = $pdo->query("SELECT id, name, code, teacher_name, class_id, status FROM academic_subjects WHERE status = 'Active' ORDER BY name ASC");
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function get_academic_subject_by_id(PDO $pdo, int $subjectId): ?array
{
    ensure_academic_class_subjects_table($pdo);
    $statement = $pdo->prepare(
        "SELECT
            s.id,
            s.name,
            s.code,
            s.teacher_name,
            s.class_id,
            s.status,
            s.created_at,
            s.updated_at,
            GROUP_CONCAT(cl.name ORDER BY cl.name SEPARATOR ', ') AS class_names,
            GROUP_CONCAT(cl.id ORDER BY cl.name SEPARATOR ',') AS class_ids
         FROM academic_subjects s
         LEFT JOIN academic_class_subjects acs ON acs.subject_id = s.id
         LEFT JOIN class_levels cl ON cl.id = acs.class_level_id
         WHERE s.id = :id
         GROUP BY s.id, s.name, s.code, s.teacher_name, s.class_id, s.status, s.created_at, s.updated_at
         LIMIT 1"
    );
    $statement->execute(['id' => $subjectId]);
    $subject = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$subject) {
        return null;
    }

    $subject['class_id_list'] = $subject['class_ids'] !== null && $subject['class_ids'] !== ''
        ? array_map('intval', explode(',', $subject['class_ids']))
        : [];

    return $subject;
}

function academic_subject_exists(PDO $pdo, string $name, int $ignoreId = 0, ?int $classId = null): bool
{
    ensure_academic_subjects_table($pdo);
    $sql = "SELECT COUNT(*) FROM academic_subjects WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))";
    $params = ['name' => $name];
    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn() > 0;
}

function academic_subject_code_exists_in_class(PDO $pdo, string $code, int $classId, int $ignoreId = 0): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }

    ensure_academic_class_subjects_table($pdo);
    $sql = "SELECT COUNT(*)
            FROM academic_subjects s
            JOIN academic_class_subjects acs ON acs.subject_id = s.id
            WHERE acs.class_level_id = :class_id
              AND LOWER(TRIM(s.code)) = LOWER(TRIM(:code))";
    $params = ['class_id' => $classId, 'code' => $code];
    if ($ignoreId > 0) {
        $sql .= " AND s.id <> :id";
        $params['id'] = $ignoreId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn() > 0;
}

function sync_academic_subject_class_assignments(PDO $pdo, int $subjectId, array $classIds): void
{
    ensure_academic_class_subjects_table($pdo);
    $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds), fn($id) => $id > 0)));

    $pdo->prepare("DELETE FROM academic_class_subjects WHERE subject_id = :subject_id")->execute(['subject_id' => $subjectId]);
    if (!$classIds) {
        $pdo->prepare("UPDATE academic_subjects SET class_id = NULL WHERE id = :id")->execute(['id' => $subjectId]);
        return;
    }

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO academic_class_subjects (class_level_id, subject_id)
         VALUES (:class_level_id, :subject_id)"
    );
    foreach ($classIds as $classId) {
        $insert->execute(['class_level_id' => $classId, 'subject_id' => $subjectId]);
    }

    $pdo->prepare("UPDATE academic_subjects SET class_id = :class_id WHERE id = :id")
        ->execute(['class_id' => $classIds[0], 'id' => $subjectId]);
}

function save_academic_subject(PDO $pdo, string $name, ?string $code = null, int $id = 0, ?int $classId = null, array $classIds = [], ?string $teacherName = null): int
{
    ensure_academic_class_subjects_table($pdo);
    $name = trim($name);
    $code = trim((string) $code);
    $teacherName = trim((string) ($teacherName ?: ''));
    if ($name === '') {
        throw new InvalidArgumentException('Subject name is required.');
    }

    if ($classId !== null && $classId > 0 && !$classIds) {
        $classIds = [$classId];
    }
    $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds), fn($value) => $value > 0)));
    if (!$classIds) {
        throw new InvalidArgumentException('Assign the subject to at least one class.');
    }

    if ($id > 0) {
        $statement = $pdo->prepare(
            "UPDATE academic_subjects
             SET name = :name,
                 code = :code,
                 teacher_name = :teacher_name,
                 status = 'Active',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute(['name' => $name, 'code' => $code !== '' ? $code : null, 'teacher_name' => $teacherName !== '' ? $teacherName : null, 'id' => $id]);
        sync_academic_subject_class_assignments($pdo, $id, $classIds);
        return $id;
    }

    $statement = $pdo->prepare(
        "INSERT INTO academic_subjects (name, code, teacher_name, class_id, status)
         VALUES (:name, :code, :teacher_name, :class_id, 'Active')"
    );
    $statement->execute([
        'name' => $name,
        'code' => $code !== '' ? $code : null,
        'teacher_name' => $teacherName !== '' ? $teacherName : null,
        'class_id' => $classIds[0],
    ]);
    $newId = (int) $pdo->lastInsertId();
    sync_academic_subject_class_assignments($pdo, $newId, $classIds);

    return $newId;
}

function delete_academic_subject(PDO $pdo, int $id): bool
{
    ensure_academic_subjects_table($pdo);
    $statement = $pdo->prepare("DELETE FROM academic_subjects WHERE id = :id");
    return $statement->execute(['id' => $id]);
}

function academic_subjects_for_class(PDO $pdo, string $classLevel): array
{
    ensure_academic_class_subjects_table($pdo);
    $classId = academic_class_level_id_by_name($pdo, $classLevel);
    if (!$classId) {
        return [];
    }

    $statement = $pdo->prepare(
        "SELECT DISTINCT s.id, s.name, s.code, s.status
         FROM academic_subjects s
         JOIN academic_class_subjects acs ON acs.subject_id = s.id
         WHERE acs.class_level_id = :class_level_id
           AND s.status = 'Active'
         ORDER BY s.name ASC"
    );
    $statement->execute(['class_level_id' => $classId]);
    $subjects = $statement->fetchAll(PDO::FETCH_ASSOC);

    if ($subjects) {
        return $subjects;
    }

    $legacy = $pdo->prepare(
        "SELECT id, name, code, status
         FROM academic_subjects
         WHERE class_id = :class_level_id
           AND status = 'Active'
         ORDER BY name ASC"
    );
    $legacy->execute(['class_level_id' => $classId]);
    return $legacy->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_students_by_class(PDO $pdo, string $classLevel): array
{
    $statement = $pdo->prepare(
        "SELECT id, registration_no, full_name, class_level
         FROM students
         WHERE class_level = :class_level
           AND enrollment_status = 'Active'
         ORDER BY full_name ASC"
    );
    $statement->execute(['class_level' => $classLevel]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_subject_assigned_to_class(PDO $pdo, int $subjectId, string $classLevel): bool
{
    $classId = academic_class_level_id_by_name($pdo, $classLevel);
    if (!$classId) {
        return false;
    }

    ensure_academic_class_subjects_table($pdo);
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_class_subjects
         WHERE class_level_id = :class_level_id
           AND subject_id = :subject_id"
    );
    $statement->execute(['class_level_id' => $classId, 'subject_id' => $subjectId]);
    if ((int) $statement->fetchColumn() > 0) {
        return true;
    }

    $legacy = $pdo->prepare("SELECT COUNT(*) FROM academic_subjects WHERE id = :id AND class_id = :class_id");
    $legacy->execute(['id' => $subjectId, 'class_id' => $classId]);
    return (int) $legacy->fetchColumn() > 0;
}

function get_academic_mark(PDO $pdo, int $studentId, int $subjectId, string $academicYear, string $term, string $examType): ?array
{
    ensure_academic_marks_table($pdo);
    $exam = academic_exam_for_period($pdo, $academicYear, $term, $examType);
    $statement = $pdo->prepare(
        "SELECT *
         FROM academic_marks
         WHERE student_id = :student_id
           AND subject_id = :subject_id
           AND exam_id = :exam_id
         LIMIT 1"
    );
    $statement->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
        'exam_id' => $exam['id'],
    ]);
    $mark = $statement->fetch(PDO::FETCH_ASSOC);
    return $mark ?: null;
}

function fetch_academic_marks_for_student(PDO $pdo, int $studentId, string $academicYear, string $term): array
{
    ensure_academic_marks_table($pdo);
    ensure_academic_exams_for_period($pdo, $academicYear, $term);
    $statement = $pdo->prepare(
        "SELECT m.*, e.exam_name, e.max_marks
         FROM academic_marks m
         JOIN academic_exams e ON e.id = m.exam_id
         WHERE m.student_id = :student_id
           AND e.academic_year = :academic_year
           AND e.term = :term"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_academic_marks_for_term(PDO $pdo, string $academicYear, string $term, ?int $subjectId = null, ?string $classLevel = null, ?string $examType = null): array
{
    ensure_academic_marks_table($pdo);
    ensure_academic_exams_for_period($pdo, $academicYear, $term);

    $sql = "SELECT m.*, e.exam_name, e.max_marks, st.full_name, st.registration_no, st.class_level
            FROM academic_marks m
            JOIN academic_exams e ON e.id = m.exam_id
            JOIN students st ON st.id = m.student_id
            WHERE e.academic_year = :academic_year
              AND e.term = :term";
    $params = ['academic_year' => $academicYear, 'term' => $term];

    if ($subjectId !== null) {
        $sql .= " AND m.subject_id = :subject_id";
        $params['subject_id'] = $subjectId;
    }
    if ($classLevel !== null) {
        $sql .= " AND st.class_level = :class_level";
        $params['class_level'] = $classLevel;
    }
    if ($examType !== null) {
        $sql .= " AND e.exam_type = :exam_type";
        $params['exam_type'] = $examType;
    }

    $sql .= " ORDER BY st.full_name ASC, m.subject_id ASC, FIELD(e.exam_type, 'Opening', 'Midterm', 'Closing')";
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function save_academic_mark(PDO $pdo, int $studentId, int $subjectId, string $academicYear, string $term, string $examType, float $marks, ?string $remarks = null): int
{
    ensure_academic_module_schema($pdo);
    academic_validate_period($academicYear, $term);
    if (!in_array($examType, academic_exam_types(), true)) {
        throw new InvalidArgumentException('Invalid exam type.');
    }

    $marks = round($marks, 2);
    if ($marks < 0 || $marks > 100) {
        throw new InvalidArgumentException('Marks must be between 0 and 100.');
    }

    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        throw new InvalidArgumentException('Student not found.');
    }
    if (!academic_subject_assigned_to_class($pdo, $subjectId, $student['class_level'])) {
        throw new InvalidArgumentException('This subject is not assigned to the selected student class.');
    }

    $exam = academic_exam_for_period($pdo, $academicYear, $term, $examType);
    $recordedBy = (int) ($_SESSION['admin_id'] ?? 0);
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }

    try {
        $statement = $pdo->prepare(
            "INSERT INTO academic_marks
                (student_id, subject_id, exam_id, academic_year, term, exam_type, marks, remarks, recorded_by)
             VALUES
                (:student_id, :subject_id, :exam_id, :academic_year, :term, :exam_type, :marks, :remarks, :recorded_by)
             ON DUPLICATE KEY UPDATE
                marks = VALUES(marks),
                remarks = VALUES(remarks),
                recorded_by = VALUES(recorded_by),
                academic_year = VALUES(academic_year),
                term = VALUES(term),
                exam_type = VALUES(exam_type),
                updated_at = CURRENT_TIMESTAMP,
                id = LAST_INSERT_ID(id)"
        );
        $statement->execute([
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'exam_id' => $exam['id'],
            'academic_year' => $academicYear,
            'term' => $term,
            'exam_type' => $examType,
            'marks' => $marks,
            'remarks' => $remarks ?: null,
            'recorded_by' => $recordedBy > 0 ? $recordedBy : null,
        ]);
        $markId = (int) $pdo->lastInsertId();
        academic_recalculate_class_results($pdo, $student['class_level'], $academicYear, $term);

        if ($started) {
            $pdo->commit();
        }

        return $markId;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function academic_student_subject_calculations(PDO $pdo, array $student, string $academicYear, string $term): array
{
    ensure_academic_exams_for_period($pdo, $academicYear, $term);
    $subjects = academic_subjects_for_class($pdo, $student['class_level']);
    if (!$subjects) {
        $subjects = get_academic_subjects_simple($pdo);
    }

    $marks = fetch_academic_marks_for_student($pdo, (int) $student['id'], $academicYear, $term);
    $marksBySubject = [];
    foreach ($marks as $mark) {
        $marksBySubject[(int) $mark['subject_id']][$mark['exam_type']] = $mark;
    }

    $rows = [];
    foreach ($subjects as $subject) {
        $subjectId = (int) $subject['id'];
        $opening = isset($marksBySubject[$subjectId]['Opening']) ? (float) $marksBySubject[$subjectId]['Opening']['marks'] : null;
        $midterm = isset($marksBySubject[$subjectId]['Midterm']) ? (float) $marksBySubject[$subjectId]['Midterm']['marks'] : null;
        $closing = isset($marksBySubject[$subjectId]['Closing']) ? (float) $marksBySubject[$subjectId]['Closing']['marks'] : null;
        $marksCount = 0;
        $maxPossible = 0.0;
        foreach (['Opening' => $opening, 'Midterm' => $midterm, 'Closing' => $closing] as $examType => $value) {
            if ($value !== null) {
                $marksCount++;
                $examMax = (float) ($marksBySubject[$subjectId][$examType]['max_marks'] ?? 100);
                $maxPossible += $examMax > 0 ? $examMax : 100.0;
            }
        }

        $total = round(($opening ?? 0.0) + ($midterm ?? 0.0) + ($closing ?? 0.0), 2);
        $scorePercent = $maxPossible > 0 ? round(($total / $maxPossible) * 100, 2) : 0.0;
        $points = $marksCount > 0 ? academic_percentage_to_points($scorePercent, $pdo) : 0;

        $rows[] = [
            'subject_id' => $subjectId,
            'subject_name' => $subject['name'],
            'opening' => $opening,
            'midterm' => $midterm,
            'closing' => $closing,
            'subject_total' => $total,
            'max_marks' => round($maxPossible, 2),
            'average' => $scorePercent,
            'grade' => (string) $points,
            'points' => $points,
            'performance_level' => $points > 0 ? academic_point_to_performance_level($points) : '-',
            'comment' => $points > 0 ? academic_subject_comment_for_points($points) : 'Marks not entered.',
            'marks_count' => $marksCount,
            'remarks' => $marksBySubject[$subjectId]['Closing']['remarks'] ?? null,
        ];
    }

    return $rows;
}

function academic_recalculate_student_results(PDO $pdo, int $studentId, string $academicYear, string $term): ?array
{
    ensure_academic_results_tables($pdo);
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        return null;
    }

    $subjectRows = academic_student_subject_calculations($pdo, $student, $academicYear, $term);
    $delete = $pdo->prepare(
        "DELETE FROM academic_subject_results
         WHERE student_id = :student_id
           AND academic_year = :academic_year
           AND term = :term"
    );
    $delete->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);

    $insertSubject = $pdo->prepare(
        "INSERT INTO academic_subject_results
            (student_id, subject_id, academic_year, term, class_level, opening_marks, midterm_marks, closing_marks, subject_total, average, grade, marks_count)
         VALUES
            (:student_id, :subject_id, :academic_year, :term, :class_level, :opening_marks, :midterm_marks, :closing_marks, :subject_total, :average, :grade, :marks_count)"
    );

    $studentTotal = 0.0;
    $totalPoints = 0;
    $marksCount = 0;
    $subjectsWithMarks = 0;
    foreach ($subjectRows as $row) {
        $studentTotal += (float) $row['subject_total'];
        if ((int) $row['marks_count'] > 0) {
            $totalPoints += (int) $row['points'];
            $subjectsWithMarks++;
        }
        $marksCount += (int) $row['marks_count'];
        $insertSubject->execute([
            'student_id' => $studentId,
            'subject_id' => $row['subject_id'],
            'academic_year' => $academicYear,
            'term' => $term,
            'class_level' => $student['class_level'],
            'opening_marks' => $row['opening'],
            'midterm_marks' => $row['midterm'],
            'closing_marks' => $row['closing'],
            'subject_total' => $row['subject_total'],
            'average' => $row['average'],
            'grade' => $row['grade'],
            'marks_count' => $row['marks_count'],
        ]);
    }

    $subjectCount = $subjectsWithMarks;
    $meanPoints = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0.0;
    $grade = academic_grade_for_mean_points($meanPoints);

    $upsert = $pdo->prepare(
        "INSERT INTO academic_student_results
            (student_id, academic_year, term, class_level, student_total, average, grade, subject_count, marks_count)
         VALUES
            (:student_id, :academic_year, :term, :class_level, :student_total, :average, :grade, :subject_count, :marks_count)
         ON DUPLICATE KEY UPDATE
            class_level = VALUES(class_level),
            student_total = VALUES(student_total),
            average = VALUES(average),
            grade = VALUES(grade),
            subject_count = VALUES(subject_count),
            marks_count = VALUES(marks_count),
            calculated_at = CURRENT_TIMESTAMP"
    );
    $upsert->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
        'class_level' => $student['class_level'],
        'student_total' => round($studentTotal, 2),
        'average' => $meanPoints,
        'grade' => $grade,
        'subject_count' => $subjectCount,
        'marks_count' => $marksCount,
    ]);

    return [
        'student_id' => $studentId,
        'student_total' => round($studentTotal, 2),
        'average' => $meanPoints,
        'mean_points' => $meanPoints,
        'total_points' => $totalPoints,
        'grade' => $grade,
        'subject_count' => $subjectCount,
        'marks_count' => $marksCount,
    ];
}

function academic_update_positions(PDO $pdo, string $classLevel, string $academicYear, string $term): void
{
    $statement = $pdo->prepare(
        "SELECT id, average, student_total
         FROM academic_student_results
         WHERE academic_year = :academic_year
           AND term = :term
           AND class_level = :class_level
         ORDER BY average DESC, student_total DESC, student_id ASC"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term, 'class_level' => $classLevel]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare("UPDATE academic_student_results SET class_position = :position WHERE id = :id");
    $position = 0;
    $rank = 0;
    $previousScore = null;
    foreach ($rows as $row) {
        $position++;
        $score = (float) $row['average'];
        if ($previousScore === null || abs($score - $previousScore) > 0.0001) {
            $rank = $position;
        }
        $update->execute(['position' => $rank, 'id' => $row['id']]);
        $previousScore = $score;
    }

    $subjects = academic_subjects_for_class($pdo, $classLevel);
    $subjectUpdate = $pdo->prepare("UPDATE academic_subject_results SET subject_position = :position WHERE id = :id");
    foreach ($subjects as $subject) {
        $subjectRows = $pdo->prepare(
            "SELECT id, subject_total, grade, average
             FROM academic_subject_results
             WHERE academic_year = :academic_year
               AND term = :term
               AND class_level = :class_level
               AND subject_id = :subject_id
             ORDER BY CAST(grade AS UNSIGNED) DESC, subject_total DESC, average DESC, student_id ASC"
        );
        $subjectRows->execute([
            'academic_year' => $academicYear,
            'term' => $term,
            'class_level' => $classLevel,
            'subject_id' => $subject['id'],
        ]);

        $position = 0;
        $rank = 0;
        $previousScore = null;
        foreach ($subjectRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $position++;
            $score = ((int) $row['grade'] * 1000000) + (float) $row['subject_total'];
            if ($previousScore === null || abs($score - $previousScore) > 0.0001) {
                $rank = $position;
            }
            $subjectUpdate->execute(['position' => $rank, 'id' => $row['id']]);
            $previousScore = $score;
        }
    }
}

function academic_recalculate_class_results(PDO $pdo, string $classLevel, string $academicYear, string $term): array
{
    ensure_academic_results_tables($pdo);
    $students = fetch_students_by_class($pdo, $classLevel);
    foreach ($students as $student) {
        academic_recalculate_student_results($pdo, (int) $student['id'], $academicYear, $term);
    }

    academic_update_positions($pdo, $classLevel, $academicYear, $term);
    return academic_class_term_rankings($pdo, $classLevel, $academicYear, $term, null, false);
}

function academic_recalculate_term_results(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): array
{
    ensure_academic_results_tables($pdo);
    $classes = $classLevel !== null && $classLevel !== '' ? [$classLevel] : class_level_options();
    $summary = [];
    foreach ($classes as $level) {
        $summary[$level] = academic_recalculate_class_results($pdo, $level, $academicYear, $term);
    }
    return $summary;
}

function academic_result_rows_count(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): int
{
    ensure_academic_results_tables($pdo);
    $sql = "SELECT COUNT(*)
            FROM academic_student_results
            WHERE academic_year = :academic_year
              AND term = :term";
    $params = ['academic_year' => $academicYear, 'term' => $term];
    if ($classLevel !== null && $classLevel !== '') {
        $sql .= " AND class_level = :class_level";
        $params['class_level'] = $classLevel;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function academic_marks_count_for_period(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): int
{
    ensure_academic_marks_table($pdo);
    $sql = "SELECT COUNT(*)
            FROM academic_marks m
            JOIN students st ON st.id = m.student_id
            WHERE m.academic_year = :academic_year
              AND m.term = :term";
    $params = ['academic_year' => $academicYear, 'term' => $term];
    if ($classLevel !== null && $classLevel !== '') {
        $sql .= " AND st.class_level = :class_level";
        $params['class_level'] = $classLevel;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function academic_ensure_results_available(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): void
{
    if (academic_result_rows_count($pdo, $academicYear, $term, $classLevel) > 0) {
        return;
    }
    if (academic_marks_count_for_period($pdo, $academicYear, $term, $classLevel) === 0) {
        return;
    }

    if ($classLevel !== null && $classLevel !== '') {
        academic_recalculate_class_results($pdo, $classLevel, $academicYear, $term);
        return;
    }

    academic_recalculate_term_results($pdo, $academicYear, $term);
}

function academic_student_marks_summary(PDO $pdo, int $studentId, string $academicYear, string $term, ?array $subjectsCache = null): array
{
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        return [];
    }

    academic_ensure_results_available($pdo, $academicYear, $term, $student['class_level']);
    $statement = $pdo->prepare(
        "SELECT sr.*, sub.name AS subject_name, sub.teacher_name
         FROM academic_subject_results sr
         JOIN academic_subjects sub ON sub.id = sr.subject_id
         WHERE sr.student_id = :student_id
           AND sr.academic_year = :academic_year
           AND sr.term = :term
         ORDER BY sub.name ASC"
    );
    $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);

    $summary = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary[] = [
            'subject_id' => (int) $row['subject_id'],
            'subject_name' => $row['subject_name'],
            'teacher_name' => $row['teacher_name'],
            'opening' => $row['opening_marks'] !== null ? (float) $row['opening_marks'] : null,
            'midterm' => $row['midterm_marks'] !== null ? (float) $row['midterm_marks'] : null,
            'closing' => $row['closing_marks'] !== null ? (float) $row['closing_marks'] : null,
            'subject_total' => (float) $row['subject_total'],
            'average' => (float) $row['average'],
            'grade' => $row['grade'],
            'points' => (int) $row['grade'],
            'performance_level' => academic_grade_expectation_label($row['grade']),
            'comment' => academic_subject_comment_for_points((int) $row['grade']),
            'subject_position' => $row['subject_position'],
            'marks_count' => (int) $row['marks_count'],
        ];
    }

    return $summary;
}

function academic_student_result(PDO $pdo, int $studentId, string $academicYear, string $term): ?array
{
    ensure_academic_results_tables($pdo);
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        return null;
    }

    academic_ensure_results_available($pdo, $academicYear, $term, $student['class_level']);
    $statement = $pdo->prepare(
        "SELECT *
         FROM academic_student_results
         WHERE student_id = :student_id
           AND academic_year = :academic_year
           AND term = :term
         LIMIT 1"
    );
    $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
    $result = $statement->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $storedGrade = strtoupper(trim((string) ($result['grade'] ?? '')));
        $calculatedGrade = academic_grade_for_mean_points((float) ($result['average'] ?? 0.0));
        if ($calculatedGrade !== $storedGrade) {
            academic_recalculate_student_results($pdo, $studentId, $academicYear, $term);
            $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
            $result = $statement->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$result) {
        return null;
    }

    $pointsStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CAST(grade AS UNSIGNED)), 0)
         FROM academic_subject_results
         WHERE student_id = :student_id
           AND academic_year = :academic_year
           AND term = :term
           AND marks_count > 0"
    );
    $pointsStmt->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
    $result['total_points'] = (int) $pointsStmt->fetchColumn();
    $result['mean_points'] = (float) $result['average'];
    $result['performance_level'] = academic_mean_points_to_performance_level((float) $result['average']);

    return $result;
}

function academic_student_term_average(PDO $pdo, int $studentId, string $academicYear, string $term): float
{
    $result = academic_student_result($pdo, $studentId, $academicYear, $term);
    return $result ? round((float) $result['average'], 2) : 0.0;
}

function academic_class_term_rankings(PDO $pdo, string $classLevel, string $academicYear, string $term, ?array $subjectsCache = null, bool $recalculate = false): array
{
    ensure_academic_results_tables($pdo);
    if ($recalculate) {
        academic_recalculate_class_results($pdo, $classLevel, $academicYear, $term);
    } else {
        academic_ensure_results_available($pdo, $academicYear, $term, $classLevel);
    }

    $statement = $pdo->prepare(
        "SELECT
            r.student_id,
            st.registration_no,
            st.full_name,
            r.class_level,
            r.student_total,
            r.average,
            r.average AS mean_points,
            r.grade,
            (
                SELECT COALESCE(SUM(CAST(sr.grade AS UNSIGNED)), 0)
                FROM academic_subject_results sr
                WHERE sr.student_id = r.student_id
                  AND sr.academic_year = r.academic_year
                  AND sr.term = r.term
                  AND sr.marks_count > 0
            ) AS total_points,
            r.class_position AS rank,
            r.subject_count,
            r.marks_count
         FROM academic_student_results r
         JOIN students st ON st.id = r.student_id
         WHERE r.academic_year = :academic_year
           AND r.term = :term
           AND r.class_level = :class_level
         ORDER BY r.class_position ASC, r.average DESC, r.student_total DESC, st.full_name ASC"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term, 'class_level' => $classLevel]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_subject_term_rankings(PDO $pdo, string $academicYear, string $term): array
{
    ensure_academic_results_tables($pdo);
    academic_ensure_results_available($pdo, $academicYear, $term);

    $statement = $pdo->prepare(
        "SELECT
            sub.id AS subject_id,
            sub.name AS subject_name,
            SUM(sr.subject_total) AS total_marks,
            SUM(CAST(sr.grade AS UNSIGNED)) AS total_points,
            ROUND(AVG(CAST(sr.grade AS UNSIGNED)), 2) AS mean_points,
            ROUND(AVG(CAST(sr.grade AS UNSIGNED)), 2) AS average_score,
            ROUND(AVG(sr.average), 2) AS score_percent,
            COUNT(DISTINCT sr.student_id) AS student_count
         FROM academic_subject_results sr
         JOIN academic_subjects sub ON sub.id = sr.subject_id
         WHERE sr.academic_year = :academic_year
           AND sr.term = :term
           AND sr.marks_count > 0
         GROUP BY sub.id, sub.name
         ORDER BY mean_points DESC, total_marks DESC, sub.name ASC"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_best_students_by_subject(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): array
{
    ensure_academic_results_tables($pdo);
    academic_ensure_results_available($pdo, $academicYear, $term, $classLevel);

    $sql = "SELECT
                sr.subject_id,
                sub.name AS subject_name,
                sr.student_id,
                st.registration_no,
                st.full_name,
                sr.class_level,
                sr.average,
                sr.subject_total,
                sr.grade,
                sr.subject_position
            FROM academic_subject_results sr
            JOIN academic_subjects sub ON sub.id = sr.subject_id
            JOIN students st ON st.id = sr.student_id
            WHERE sr.academic_year = :academic_year
              AND sr.term = :term
              AND sr.subject_position = 1";
    $params = ['academic_year' => $academicYear, 'term' => $term];
    if ($classLevel !== null && $classLevel !== '') {
        $sql .= " AND sr.class_level = :class_level";
        $params['class_level'] = $classLevel;
    }
    $sql .= " ORDER BY sub.name ASC, CAST(sr.grade AS UNSIGNED) DESC, sr.subject_total DESC, st.full_name ASC";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_grade_distribution(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): array
{
    ensure_academic_results_tables($pdo);
    academic_ensure_results_available($pdo, $academicYear, $term, $classLevel);

    $grades = [];
    foreach (academic_grade_boundaries($pdo) as $grade) {
        $grades[(string) $grade] = 0;
    }
    if (!$grades) {
        $grades = ['8' => 0, '7' => 0, '6' => 0, '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
    }

    $sql = "SELECT grade, COUNT(*) AS total
            FROM academic_student_results
            WHERE academic_year = :academic_year
              AND term = :term";
    $params = ['academic_year' => $academicYear, 'term' => $term];
    if ($classLevel !== null && $classLevel !== '') {
        $sql .= " AND class_level = :class_level";
        $params['class_level'] = $classLevel;
    }
    $sql .= " GROUP BY grade";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grades[$row['grade']] = (int) $row['total'];
    }

    return $grades;
}

function academic_class_summary_for_term(PDO $pdo, string $academicYear, string $term): array
{
    ensure_academic_results_tables($pdo);
    academic_ensure_results_available($pdo, $academicYear, $term);

    $statement = $pdo->prepare(
        "SELECT
            r.class_level,
            ROUND(AVG(r.average), 2) AS mean_points,
            ROUND(AVG(r.average), 2) AS average,
            SUM(r.student_total) AS total_marks,
            COUNT(*) AS student_count,
            (
                SELECT st2.full_name
                FROM academic_student_results r2
                JOIN students st2 ON st2.id = r2.student_id
                WHERE r2.academic_year = r.academic_year
                  AND r2.term = r.term
                  AND r2.class_level = r.class_level
                ORDER BY r2.average DESC, r2.student_total DESC, st2.full_name ASC
                LIMIT 1
            ) AS top_student
         FROM academic_student_results r
         WHERE r.academic_year = :academic_year
           AND r.term = :term
           AND r.marks_count > 0
         GROUP BY r.academic_year, r.term, r.class_level
         ORDER BY mean_points DESC, total_marks DESC, r.class_level ASC"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_school_mean_score(PDO $pdo, string $academicYear, string $term): float
{
    ensure_academic_results_tables($pdo);
    academic_ensure_results_available($pdo, $academicYear, $term);
    $statement = $pdo->prepare(
        "SELECT AVG(average)
         FROM academic_student_results
         WHERE academic_year = :academic_year
           AND term = :term
           AND marks_count > 0"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return round((float) $statement->fetchColumn(), 2);
}

function academic_report_cards_generated(PDO $pdo, string $academicYear, string $term): int
{
    ensure_academic_report_cards_table($pdo);
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_report_cards
         WHERE academic_year = :academic_year
           AND term = :term"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return (int) $statement->fetchColumn();
}

function academic_exam_marks_completed(PDO $pdo, string $academicYear, string $term): int
{
    ensure_academic_marks_table($pdo);
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_marks
         WHERE academic_year = :academic_year
           AND term = :term"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return (int) $statement->fetchColumn();
}

function academic_results_published(PDO $pdo, string $academicYear, string $term): int
{
    ensure_academic_results_tables($pdo);
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_student_results
         WHERE academic_year = :academic_year
           AND term = :term
           AND marks_count > 0"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return (int) $statement->fetchColumn();
}

function academic_exams_created_count(PDO $pdo, string $academicYear, string $term): int
{
    ensure_academic_exams_for_period($pdo, $academicYear, $term);
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_exams
         WHERE academic_year = :academic_year
           AND term = :term"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return (int) $statement->fetchColumn();
}

function academic_class_analytics(PDO $pdo, string $academicYear, string $term): array
{
    $gradeDistribution = academic_grade_distribution($pdo, $academicYear, $term);
    $subjectRankings = academic_subject_term_rankings($pdo, $academicYear, $term);
    $classSummary = academic_class_summary_for_term($pdo, $academicYear, $term);

    return [
        'grade_distribution' => $gradeDistribution,
        'subjects_offered' => count(get_academic_subjects_simple($pdo)),
        'best_subject' => $subjectRankings[0]['subject_name'] ?? null,
        'weakest_subject' => $subjectRankings ? $subjectRankings[count($subjectRankings) - 1]['subject_name'] : null,
        'best_class' => $classSummary[0]['class_level'] ?? null,
        'lowest_class' => $classSummary ? $classSummary[count($classSummary) - 1]['class_level'] : null,
    ];
}

function get_academic_report_card(PDO $pdo, int $studentId, string $academicYear, string $term): ?array
{
    ensure_academic_report_cards_table($pdo);
    $statement = $pdo->prepare(
        "SELECT *
         FROM academic_report_cards
         WHERE student_id = :student_id
           AND academic_year = :academic_year
           AND term = :term
         LIMIT 1"
    );
    $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (empty($row['head_teacher_comment']) && !empty($row['principal_comment'])) {
        $row['head_teacher_comment'] = $row['principal_comment'];
    }
    return $row;
}

function save_academic_report_card(PDO $pdo, int $studentId, string $academicYear, string $term, ?string $teacherComment, ?string $headTeacherComment): int
{
    ensure_academic_report_cards_table($pdo);
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        throw new InvalidArgumentException('Student not found.');
    }
    academic_recalculate_class_results($pdo, $student['class_level'], $academicYear, $term);

    $statement = $pdo->prepare(
        "INSERT INTO academic_report_cards (student_id, academic_year, term, teacher_comment, head_teacher_comment, principal_comment)
         VALUES (:student_id, :academic_year, :term, :teacher_comment, :head_teacher_comment, :principal_comment)
         ON DUPLICATE KEY UPDATE
             teacher_comment = VALUES(teacher_comment),
             head_teacher_comment = VALUES(head_teacher_comment),
             principal_comment = VALUES(principal_comment),
             generated_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP,
             id = LAST_INSERT_ID(id)"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
        'teacher_comment' => $teacherComment ?: null,
        'head_teacher_comment' => $headTeacherComment ?: null,
        'principal_comment' => $headTeacherComment ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

function fetch_academic_report_cards_for_term(PDO $pdo, string $academicYear, string $term): array
{
    ensure_academic_report_cards_table($pdo);
    $statement = $pdo->prepare(
        "SELECT rc.*, s.full_name, s.registration_no, s.class_level
         FROM academic_report_cards rc
         JOIN students s ON s.id = rc.student_id
         WHERE rc.academic_year = :academic_year
           AND rc.term = :term
         ORDER BY rc.generated_at DESC"
    );
    $statement->execute(['academic_year' => $academicYear, 'term' => $term]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function academic_grading_rows(PDO $pdo): array
{
    ensure_academic_grading_table($pdo);
    $statement = $pdo->query(
        "SELECT *
         FROM academic_grading_scales
         ORDER BY min_score DESC, display_order ASC"
    );
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function save_academic_grade(PDO $pdo, string $grade, float $minScore, float $maxScore, ?string $remark = null, int $id = 0): int
{
    ensure_academic_grading_table($pdo);
    $grade = strtoupper(trim($grade));
    if ($grade === '') {
        throw new InvalidArgumentException('Grade is required.');
    }
    if ($minScore < 0 || $maxScore > 100 || $minScore > $maxScore) {
        throw new InvalidArgumentException('Grade range must be between 0 and 100, with minimum below maximum.');
    }

    if ($id > 0) {
        $statement = $pdo->prepare(
            "UPDATE academic_grading_scales
             SET grade = :grade,
                 min_score = :min_score,
                 max_score = :max_score,
                 remark = :remark,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'grade' => $grade,
            'min_score' => $minScore,
            'max_score' => $maxScore,
            'remark' => $remark ?: null,
            'id' => $id,
        ]);
        return $id;
    }

    $statement = $pdo->prepare(
        "INSERT INTO academic_grading_scales (grade, min_score, max_score, remark, display_order)
         VALUES (:grade, :min_score, :max_score, :remark, :display_order)"
    );
    $statement->execute([
        'grade' => $grade,
        'min_score' => $minScore,
        'max_score' => $maxScore,
        'remark' => $remark ?: null,
        'display_order' => count(academic_grading_rows($pdo)) + 1,
    ]);
    return (int) $pdo->lastInsertId();
}

function delete_academic_grade(PDO $pdo, int $id): bool
{
    ensure_academic_grading_table($pdo);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM academic_grading_scales")->fetchColumn();
    if ($count <= 1) {
        throw new InvalidArgumentException('At least one grade range is required.');
    }

    $statement = $pdo->prepare("DELETE FROM academic_grading_scales WHERE id = :id");
    return $statement->execute(['id' => $id]);
}

function migrate_to_percentage_based_grading(PDO $pdo): bool
{
    ensure_academic_grading_table($pdo);
    
    $rows = $pdo->query(
        "SELECT id, grade FROM academic_grading_scales ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    
    $hasOldGrades = false;
    foreach ($rows as $row) {
        $grade = strtoupper(trim((string) $row['grade']));
        if (in_array($grade, ['A', 'B', 'C', 'D', 'E'], true)) {
            $hasOldGrades = true;
            break;
        }
    }
    
    if (!$hasOldGrades) {
        return false;
    }
    
    $pdo->exec("DELETE FROM academic_grading_scales");
    
    $defaults = [
        [90, 100, '8', 'Exceeding Expectations'],
        [75, 89, '7', 'Exceeding Expectations'],
        [58, 74, '6', 'Meeting Expectations'],
        [41, 57, '5', 'Meeting Expectations'],
        [31, 40, '4', 'Approaching Expectations'],
        [21, 30, '3', 'Approaching Expectations'],
        [11, 20, '2', 'Below Expectations'],
        [0, 10, '1', 'Below Expectations'],
    ];
    $insert = $pdo->prepare(
        "INSERT INTO academic_grading_scales (min_score, max_score, grade, remark, display_order)
         VALUES (:min_score, :max_score, :grade, :remark, :display_order)"
    );
    foreach ($defaults as $index => $row) {
        $insert->execute([
            'min_score' => $row[0],
            'max_score' => $row[1],
            'grade' => $row[2],
            'remark' => $row[3],
            'display_order' => $index + 1,
        ]);
    }
    
    return true;
}

function academic_report_filter_label(string $academicYear, string $term, ?string $classLevel = null, ?string $subjectName = null): string
{
    $parts = [$academicYear, $term];
    if ($classLevel) {
        $parts[] = $classLevel;
    }
    if ($subjectName) {
        $parts[] = $subjectName;
    }
    return implode(' | ', $parts);
}

function academic_module_nav_items(): array
{
    return [
        ['label' => 'Academic Overview', 'href' => 'admin/academic_dashboard.php', 'icon' => 'fa-gauge-high', 'matches' => ['academic_dashboard.php', 'academic_records.php']],
        ['label' => 'Subjects', 'href' => 'admin/subjects.php', 'icon' => 'fa-book', 'matches' => ['subjects.php']],
        ['label' => 'Exams', 'href' => 'admin/exams.php', 'icon' => 'fa-calendar-check', 'matches' => ['exams.php']],
        ['label' => 'Marks Entry', 'href' => 'admin/marks_entry.php', 'icon' => 'fa-pen-to-square', 'matches' => ['marks_entry.php']],
        ['label' => 'Results', 'href' => 'admin/results.php', 'icon' => 'fa-square-poll-vertical', 'matches' => ['results.php']],
        ['label' => 'Report Cards', 'href' => 'admin/report_cards.php', 'icon' => 'fa-file-lines', 'matches' => ['report_cards.php']],
        ['label' => 'Class Rankings', 'href' => 'admin/class_rankings.php', 'icon' => 'fa-ranking-star', 'matches' => ['class_rankings.php']],
        ['label' => 'Subject Rankings', 'href' => 'admin/subject_rankings.php', 'icon' => 'fa-award', 'matches' => ['subject_rankings.php']],
        ['label' => 'Academic Reports', 'href' => 'admin/academic_reports.php', 'icon' => 'fa-chart-line', 'matches' => ['academic_reports.php', 'academic_analytics.php']],
        ['label' => 'Academic Settings', 'href' => 'admin/academic_settings.php', 'icon' => 'fa-sliders', 'matches' => ['academic_settings.php']],
    ];
}

function render_academic_module_nav(?string $activeScript = null): void
{
    $activeScript = $activeScript ?: basename($_SERVER['SCRIPT_NAME'] ?? '');
    echo '<nav class="academic-module-nav no-print" aria-label="Academic module navigation">';
    foreach (academic_module_nav_items() as $item) {
        $active = in_array($activeScript, $item['matches'], true) ? ' active' : '';
        echo '<a class="academic-module-link' . $active . '" href="' . h(url($item['href'])) . '">';
        echo '<i class="fa-solid ' . h($item['icon']) . '"></i>';
        echo '<span>' . h($item['label']) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}

/**
 * Get exam filter options for a specific term
 * Returns an array with 'All Exams' option plus individual exam types
 */
function academic_exam_filter_options(PDO $pdo, string $academicYear, string $term): array
{
    $options = ['all' => 'All Exams'];
    $exams = academic_exams_for_period($pdo, $academicYear, $term);
    
    foreach ($exams as $exam) {
        $options[$exam['exam_type']] = $exam['exam_name'] ?: academic_exam_label($exam['exam_type']);
    }
    
    return $options;
}

/**
 * Get filtered marks summary for a specific exam type
 * If $examType is null or 'all', returns combined marks as before
 */
function academic_student_marks_summary_for_exam(PDO $pdo, int $studentId, string $academicYear, string $term, ?string $examType = null): array
{
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        return [];
    }

    academic_ensure_results_available($pdo, $academicYear, $term, $student['class_level']);
    
    $statement = $pdo->prepare(
        "SELECT sr.*, sub.name AS subject_name, sub.teacher_name
         FROM academic_subject_results sr
         JOIN academic_subjects sub ON sub.id = sr.subject_id
         WHERE sr.student_id = :student_id
           AND sr.academic_year = :academic_year
           AND sr.term = :term
         ORDER BY sub.name ASC"
    );
    $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);

    $summary = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Filter by exam type if specified
        if ($examType && $examType !== 'all') {
            $marks = null;
            $total = 0.0;
            $average = 0.0;
            $grade = '1';
            
            if ($examType === 'Opening' && $row['opening_marks'] !== null) {
                $marks = (float) $row['opening_marks'];
                $total = $marks;
                $average = $marks;
                $grade = academic_grade_for_score($marks, $pdo);
            } elseif ($examType === 'Midterm' && $row['midterm_marks'] !== null) {
                $marks = (float) $row['midterm_marks'];
                $total = $marks;
                $average = $marks;
                $grade = academic_grade_for_score($marks, $pdo);
            } elseif ($examType === 'Closing' && $row['closing_marks'] !== null) {
                $marks = (float) $row['closing_marks'];
                $total = $marks;
                $average = $marks;
                $grade = academic_grade_for_score($marks, $pdo);
            } else {
                // No marks for this exam type, skip
                continue;
            }
            
            $summary[] = [
                'subject_id' => (int) $row['subject_id'],
                'subject_name' => $row['subject_name'],
                'teacher_name' => $row['teacher_name'],
                'opening' => $examType === 'Opening' ? $marks : null,
                'midterm' => $examType === 'Midterm' ? $marks : null,
                'closing' => $examType === 'Closing' ? $marks : null,
                'subject_total' => $total,
                'average' => $average,
                'grade' => $grade,
                'points' => (int) $grade,
                'performance_level' => academic_grade_expectation_label($grade),
                'comment' => academic_subject_comment_for_points((int) $grade),
                'subject_position' => $row['subject_position'],
                'marks_count' => (int) $row['marks_count'],
                'exam_type_filter' => $examType,
            ];
        } else {
            // No filter - return original data
            $summary[] = [
                'subject_id' => (int) $row['subject_id'],
                'subject_name' => $row['subject_name'],
                'teacher_name' => $row['teacher_name'],
                'opening' => $row['opening_marks'] !== null ? (float) $row['opening_marks'] : null,
                'midterm' => $row['midterm_marks'] !== null ? (float) $row['midterm_marks'] : null,
                'closing' => $row['closing_marks'] !== null ? (float) $row['closing_marks'] : null,
                'subject_total' => (float) $row['subject_total'],
                'average' => (float) $row['average'],
                'grade' => $row['grade'],
                'points' => (int) $row['grade'],
                'performance_level' => academic_grade_expectation_label($row['grade']),
                'comment' => academic_subject_comment_for_points((int) $row['grade']),
                'subject_position' => $row['subject_position'],
                'marks_count' => (int) $row['marks_count'],
                'exam_type_filter' => null,
            ];
        }
    }

    return $summary;
}

/**
 * Get filtered result for a specific exam type
 * If $examType is null or 'all', returns combined results as before
 */
function academic_student_result_for_exam(PDO $pdo, int $studentId, string $academicYear, string $term, ?string $examType = null): ?array
{
    ensure_academic_results_tables($pdo);
    $student = academic_get_student($pdo, $studentId);
    if (!$student) {
        return null;
    }

    academic_ensure_results_available($pdo, $academicYear, $term, $student['class_level']);
    
    if (!$examType || $examType === 'all') {
        // Return the full result without filtering
        $statement = $pdo->prepare(
            "SELECT *
             FROM academic_student_results
             WHERE student_id = :student_id
               AND academic_year = :academic_year
               AND term = :term
             LIMIT 1"
        );
        $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $storedGrade = strtoupper(trim((string) ($result['grade'] ?? '')));
            $calculatedGrade = academic_grade_for_mean_points((float) ($result['average'] ?? 0.0));
            if ($calculatedGrade !== $storedGrade) {
                academic_recalculate_student_results($pdo, $studentId, $academicYear, $term);
                $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
                $result = $statement->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!$result) {
            return null;
        }

        $pointsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CAST(grade AS UNSIGNED)), 0)
             FROM academic_subject_results
             WHERE student_id = :student_id
               AND academic_year = :academic_year
               AND term = :term
               AND marks_count > 0"
        );
        $pointsStmt->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
        $result['total_points'] = (int) $pointsStmt->fetchColumn();
        $result['mean_points'] = (float) $result['average'];
        $result['performance_level'] = academic_mean_points_to_performance_level((float) $result['average']);
        $result['exam_type_filter'] = null;

        return $result;
    }

    // Filter by specific exam type
    $summaryRows = academic_student_marks_summary_for_exam($pdo, $studentId, $academicYear, $term, $examType);
    
    $studentTotal = 0.0;
    $totalPoints = 0;
    $subjectsWithMarks = 0;
    
    foreach ($summaryRows as $row) {
        $studentTotal += (float) $row['subject_total'];
        if ((int) $row['marks_count'] > 0) {
            $totalPoints += (int) $row['points'];
            $subjectsWithMarks++;
        }
    }

    $meanPoints = $subjectsWithMarks > 0 ? round($totalPoints / $subjectsWithMarks, 2) : 0.0;
    $grade = academic_grade_for_mean_points($meanPoints);
    
    // Get the full result as base
    $statement = $pdo->prepare(
        "SELECT *
         FROM academic_student_results
         WHERE student_id = :student_id
           AND academic_year = :academic_year
           AND term = :term
         LIMIT 1"
    );
    $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear, 'term' => $term]);
    $result = $statement->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        $result = [
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'term' => $term,
            'class_level' => $student['class_level'],
            'class_position' => null,
        ];
    }
    
    // Override with exam-specific calculations
    $result['student_total'] = round($studentTotal, 2);
    $result['average'] = $meanPoints;
    $result['mean_points'] = $meanPoints;
    $result['grade'] = $grade;
    $result['total_points'] = $totalPoints;
    $result['performance_level'] = academic_mean_points_to_performance_level($meanPoints);
    $result['exam_type_filter'] = $examType;
    $result['subject_count'] = $subjectsWithMarks;
    
    return $result;
}
