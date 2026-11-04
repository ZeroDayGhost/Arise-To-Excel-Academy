<?php

function activities_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS activities (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        activity_type VARCHAR(30) NOT NULL,
        activity_date DATE NOT NULL,
        academic_year VARCHAR(4) NOT NULL,
        term VARCHAR(20) NOT NULL,
        cost_per_student DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_activities_period (academic_year, term, activity_date),
        KEY idx_activities_type_status (activity_type, status),
        CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_activities_updated_by FOREIGN KEY (updated_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_classes (
        activity_id INT UNSIGNED NOT NULL,
        class_level VARCHAR(80) NOT NULL,
        PRIMARY KEY (activity_id, class_level),
        KEY idx_activity_classes_class (class_level),
        CONSTRAINT fk_activity_classes_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_participants (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        activity_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        expected_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        participation_status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_activity_participant (activity_id, student_id),
        KEY idx_activity_participant_student (student_id, activity_id),
        CONSTRAINT fk_activity_participants_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_activity_participants_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        activity_id INT UNSIGNED NOT NULL,
        participant_id BIGINT UNSIGNED NOT NULL,
        receipt_no VARCHAR(60) NOT NULL UNIQUE,
        request_key CHAR(64) NOT NULL UNIQUE,
        amount DECIMAL(12,2) NOT NULL,
        payment_date DATE NOT NULL,
        academic_year VARCHAR(4) NOT NULL,
        term VARCHAR(20) NOT NULL,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
        reference_no VARCHAR(100) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Recorded',
        recorded_by INT UNSIGNED NULL,
        reversed_by INT UNSIGNED NULL,
        reversed_at DATETIME NULL,
        reversal_reason VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_activity_payments_period_status (academic_year, term, status, payment_date),
        KEY idx_activity_payments_activity (activity_id, participant_id),
        CONSTRAINT fk_activity_payments_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_activity_payments_participant FOREIGN KEY (participant_id) REFERENCES activity_participants(id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_activity_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_activity_payments_reversed_by FOREIGN KEY (reversed_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        activity_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NULL,
        action VARCHAR(40) NOT NULL,
        payment_id BIGINT UNSIGNED NULL,
        details LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_activity_audit_activity (activity_id, created_at),
        KEY idx_activity_audit_actor (actor_id, created_at),
        CONSTRAINT fk_activity_audit_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_activity_audit_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_activity_audit_payment FOREIGN KEY (payment_id) REFERENCES activity_payments(id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB");
}

function activity_audit(PDO $pdo, int $activityId, string $action, array $details = [], ?int $paymentId = null): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_audit_logs (activity_id, actor_id, action, payment_id, details) VALUES (:activity_id, :actor_id, :action, :payment_id, :details)');
    $stmt->execute([
        'activity_id' => $activityId,
        'actor_id' => (int) ($_SESSION['admin_id'] ?? 0) ?: null,
        'action' => $action,
        'payment_id' => $paymentId,
        'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function activity_paid_sql(string $participantAlias = 'ap'): string
{
    return "COALESCE((SELECT SUM(pay.amount) FROM activity_payments pay WHERE pay.participant_id = {$participantAlias}.id AND pay.status = 'Recorded'), 0)";
}

function activity_type_from_name(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    if (preg_match('/\b(swim(?:ming)?|pool)\b/u', $name)) {
        return 'Swimming';
    }
    if (preg_match('/\b(trip|excursion|educational visit|school tour)\b/u', $name)) {
        return 'Trip';
    }
    return 'Other';
}

function activity_income_total(PDO $pdo, array $filters = []): float
{
    $where = ["pay.status = 'Recorded'"];
    $params = [];
    $year = preg_match('/^\d{4}$/', (string) ($filters['year'] ?? '')) ? (string) $filters['year'] : '';
    $term = in_array((string) ($filters['term'] ?? ''), ['Term 1', 'Term 2', 'Term 3'], true) ? (string) $filters['term'] : '';
    $class = in_array((string) ($filters['class'] ?? ''), class_level_options(), true) ? (string) $filters['class'] : '';
    $type = in_array((string) ($filters['type'] ?? ''), ['Swimming', 'Trip', 'Other'], true) ? (string) $filters['type'] : '';
    $activityId = filter_var($filters['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $studentId = filter_var($filters['student_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $gender = in_array((string) ($filters['gender'] ?? ''), gender_options(), true) ? (string) $filters['gender'] : '';
    $enrollmentStatus = in_array((string) ($filters['enrollment_status'] ?? ''), ['Active', 'Transferred Out', 'Graduated', 'Withdrawn'], true) ? (string) $filters['enrollment_status'] : '';
    $status = in_array((string) ($filters['status'] ?? ''), ['Paid', 'Partial', 'Partially Paid', 'Unpaid', 'Outstanding'], true) ? (string) $filters['status'] : '';
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_from'] ?? '')) ? (string) $filters['date_from'] : '';
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_to'] ?? '')) ? (string) $filters['date_to'] : '';
    if ($year !== '') { $where[] = 'pay.academic_year = :income_year'; $params['income_year'] = $year; }
    if ($term !== '') { $where[] = 'pay.term = :income_term'; $params['income_term'] = $term; }
    if ($type !== '') { $where[] = 'a.activity_type = :income_type'; $params['income_type'] = $type; }
    if ($activityId > 0) { $where[] = 'a.id = :income_activity_id'; $params['income_activity_id'] = $activityId; }
    if ($studentId > 0) { $where[] = 's.id = :income_student_id'; $params['income_student_id'] = $studentId; }
    if ($gender !== '') { $where[] = 's.gender = :income_gender'; $params['income_gender'] = $gender; }
    if ($enrollmentStatus !== '') { $where[] = 's.enrollment_status = :income_enrollment_status'; $params['income_enrollment_status'] = $enrollmentStatus; }
    if ($status !== '') {
        $paidSql = activity_paid_sql('ap');
        $where[] = match ($status) {
            'Paid' => "a.status = 'Active' AND ap.participation_status = 'Active' AND (ap.expected_amount <= 0.005 OR {$paidSql} >= ap.expected_amount)",
            'Partial', 'Partially Paid' => "a.status = 'Active' AND ap.participation_status = 'Active' AND {$paidSql} > 0 AND {$paidSql} < ap.expected_amount",
            'Outstanding' => "a.status = 'Active' AND ap.participation_status = 'Active' AND {$paidSql} < ap.expected_amount",
            default => "a.status = 'Active' AND ap.participation_status = 'Active' AND ap.expected_amount > 0 AND {$paidSql} <= 0",
        };
    }
    if ($dateFrom !== '') { $where[] = 'pay.payment_date >= :income_date_from'; $params['income_date_from'] = $dateFrom; }
    if ($dateTo !== '') { $where[] = 'pay.payment_date <= :income_date_to'; $params['income_date_to'] = $dateTo; }
    if ($class !== '') {
        $classSql = student_class_sql('s', 'pay.academic_year');
        $where[] = "{$classSql} = :income_class";
        $params['income_class'] = $class;
    }
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(pay.amount), 0) FROM activity_payments pay '
        . 'JOIN activities a ON a.id = pay.activity_id '
        . 'JOIN activity_participants ap ON ap.id = pay.participant_id '
        . 'JOIN students s ON s.id = ap.student_id WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function activity_student_status(float $expected, float $paid, string $participation = 'Active', string $activity = 'Active'): string
{
    if ($activity !== 'Active' || $participation !== 'Active') {
        return 'Withdrawn';
    }
    if ($expected <= 0.005) {
        return 'Paid';
    }
    if ($paid <= 0.005) {
        return 'Unpaid';
    }
    return $paid + 0.005 >= $expected ? 'Paid' : 'Partially Paid';
}

function activity_report_data(PDO $pdo, array $filters = []): array
{
    $year = preg_match('/^\d{4}$/', (string) ($filters['year'] ?? '')) ? (string) $filters['year'] : '';
    $term = in_array((string) ($filters['term'] ?? ''), ['Term 1', 'Term 2', 'Term 3'], true) ? (string) $filters['term'] : '';
    $class = in_array((string) ($filters['class'] ?? ''), class_level_options(), true) ? (string) $filters['class'] : '';
    $type = in_array((string) ($filters['type'] ?? ''), ['Swimming', 'Trip', 'Other'], true) ? (string) $filters['type'] : '';
    $activityId = filter_var($filters['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $studentId = filter_var($filters['student_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $gender = in_array((string) ($filters['gender'] ?? ''), gender_options(), true) ? (string) $filters['gender'] : '';
    $enrollmentStatus = in_array((string) ($filters['enrollment_status'] ?? ''), ['Active', 'Transferred Out', 'Graduated', 'Withdrawn'], true) ? (string) $filters['enrollment_status'] : '';
    $status = in_array((string) ($filters['status'] ?? ''), ['Paid', 'Partial', 'Partially Paid', 'Unpaid', 'Outstanding'], true) ? (string) $filters['status'] : '';
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_from'] ?? '')) ? (string) $filters['date_from'] : '';
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_to'] ?? '')) ? (string) $filters['date_to'] : '';

    $where = [];
    $params = [];
    if ($year !== '') { $where[] = 'a.academic_year = :activity_year'; $params['activity_year'] = $year; }
    if ($term !== '') { $where[] = 'a.term = :activity_term'; $params['activity_term'] = $term; }
    if ($type !== '') { $where[] = 'a.activity_type = :activity_type'; $params['activity_type'] = $type; }
    if ($activityId > 0) { $where[] = 'a.id = :activity_id'; $params['activity_id'] = $activityId; }
    if ($studentId > 0) { $where[] = 's.id = :activity_student_id'; $params['activity_student_id'] = $studentId; }
    if ($gender !== '') { $where[] = 's.gender = :activity_gender'; $params['activity_gender'] = $gender; }
    if ($enrollmentStatus !== '') { $where[] = 's.enrollment_status = :activity_enrollment_status'; $params['activity_enrollment_status'] = $enrollmentStatus; }
    if ($dateFrom !== '') { $where[] = 'a.activity_date >= :activity_date_from'; $params['activity_date_from'] = $dateFrom; }
    if ($dateTo !== '') { $where[] = 'a.activity_date <= :activity_date_to'; $params['activity_date_to'] = $dateTo; }
    if ($class !== '') {
        $classSql = student_class_sql('s', 'a.academic_year');
        $where[] = "{$classSql} = :activity_class";
        $params['activity_class'] = $class;
    }
    $paidSql = activity_paid_sql('ap');
    $classSql = student_class_sql('s', 'a.academic_year');
    if ($status !== '') {
        $where[] = match ($status) {
            'Paid' => "a.status = 'Active' AND ap.participation_status = 'Active' AND (ap.expected_amount <= 0.005 OR {$paidSql} >= ap.expected_amount)",
            'Partial', 'Partially Paid' => "a.status = 'Active' AND ap.participation_status = 'Active' AND {$paidSql} > 0 AND {$paidSql} < ap.expected_amount",
            'Outstanding' => "a.status = 'Active' AND ap.participation_status = 'Active' AND {$paidSql} < ap.expected_amount",
            default => "a.status = 'Active' AND ap.participation_status = 'Active' AND ap.expected_amount > 0 AND {$paidSql} <= 0",
        };
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $rowsStmt = $pdo->prepare(
        "SELECT a.id AS activity_id, a.name AS activity_name, a.activity_type, a.activity_date, a.academic_year, a.term, a.cost_per_student, a.status AS activity_status,
                s.id AS student_id, s.full_name AS student_name, s.registration_no, {$classSql} AS class_level,
                ap.expected_amount, ap.participation_status, {$paidSql} AS amount_paid
         FROM activities a
         JOIN activity_participants ap ON ap.activity_id = a.id
         JOIN students s ON s.id = ap.student_id{$sqlWhere}
         ORDER BY a.activity_date DESC, a.id DESC, s.full_name ASC"
    );
    $rowsStmt->execute($params);
    $participants = $rowsStmt->fetchAll();
    foreach ($participants as &$row) {
        $row['expected_amount'] = ($row['activity_status'] === 'Active' && $row['participation_status'] === 'Active') ? (float) $row['expected_amount'] : 0.0;
        $row['amount_paid'] = (float) $row['amount_paid'];
        $row['outstanding'] = max(0.0, $row['expected_amount'] - $row['amount_paid']);
        $row['payment_status'] = activity_student_status($row['expected_amount'], $row['amount_paid'], (string) $row['participation_status'], (string) $row['activity_status']);
    }
    unset($row);

    $expected = $collected = $outstanding = 0.0;
    $byTerm = [];
    $byYear = [];
    $byType = [];
    foreach ($participants as $row) {
        $expected += $row['expected_amount'];
        $collected += $row['amount_paid'];
        $outstanding += $row['outstanding'];
        $termKey = $row['academic_year'] . '|' . $row['term'];
        if (!isset($byTerm[$termKey])) {
            $byTerm[$termKey] = ['academic_year' => $row['academic_year'], 'term' => $row['term'], 'expected' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
        }
        if (!isset($byType[$row['activity_type']])) {
            $byType[$row['activity_type']] = ['activity_type' => $row['activity_type'], 'expected' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
        }
        foreach (['expected', 'collected', 'outstanding'] as $key) {
            $source = ['expected' => 'expected_amount', 'collected' => 'amount_paid', 'outstanding' => 'outstanding'][$key];
            $byTerm[$termKey][$key] += $row[$source];
            $byYear[$row['academic_year']][$key] = ($byYear[$row['academic_year']][$key] ?? 0.0) + $row[$source];
            $byType[$row['activity_type']][$key] += $row[$source];
        }
    }
    return [
        'participants' => $participants,
        'expected' => $expected,
        'collected' => $collected,
        'outstanding' => $outstanding,
        'by_term' => array_values($byTerm),
        'by_year' => $byYear,
        'by_type' => array_values($byType),
    ];
}

function activity_payment_report_rows(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];
    $year = preg_match('/^\d{4}$/', (string) ($filters['year'] ?? '')) ? (string) $filters['year'] : '';
    $term = in_array((string) ($filters['term'] ?? ''), ['Term 1', 'Term 2', 'Term 3'], true) ? (string) $filters['term'] : '';
    $class = in_array((string) ($filters['class'] ?? ''), class_level_options(), true) ? (string) $filters['class'] : '';
    $type = in_array((string) ($filters['type'] ?? ''), ['Swimming', 'Trip', 'Other'], true) ? (string) $filters['type'] : '';
    $activityId = filter_var($filters['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $studentId = filter_var($filters['student_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $gender = in_array((string) ($filters['gender'] ?? ''), gender_options(), true) ? (string) $filters['gender'] : '';
    $enrollmentStatus = in_array((string) ($filters['enrollment_status'] ?? ''), ['Active', 'Transferred Out', 'Graduated', 'Withdrawn'], true) ? (string) $filters['enrollment_status'] : '';
    $status = in_array((string) ($filters['status'] ?? ''), ['Paid', 'Partial', 'Partially Paid', 'Unpaid', 'Outstanding'], true) ? (string) $filters['status'] : '';
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_from'] ?? '')) ? (string) $filters['date_from'] : '';
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_to'] ?? '')) ? (string) $filters['date_to'] : '';

    if ($year !== '') { $where[] = 'pay.academic_year = :payment_year'; $params['payment_year'] = $year; }
    if ($term !== '') { $where[] = 'pay.term = :payment_term'; $params['payment_term'] = $term; }
    if ($type !== '') { $where[] = 'a.activity_type = :payment_type'; $params['payment_type'] = $type; }
    if ($activityId > 0) { $where[] = 'a.id = :payment_activity_id'; $params['payment_activity_id'] = $activityId; }
    if ($studentId > 0) { $where[] = 's.id = :payment_student_id'; $params['payment_student_id'] = $studentId; }
    if ($gender !== '') { $where[] = 's.gender = :payment_gender'; $params['payment_gender'] = $gender; }
    if ($enrollmentStatus !== '') { $where[] = 's.enrollment_status = :payment_enrollment_status'; $params['payment_enrollment_status'] = $enrollmentStatus; }
    if ($dateFrom !== '') { $where[] = 'pay.payment_date >= :payment_date_from'; $params['payment_date_from'] = $dateFrom; }
    if ($dateTo !== '') { $where[] = 'pay.payment_date <= :payment_date_to'; $params['payment_date_to'] = $dateTo; }
    if ($status !== '') {
        $paidSql = activity_paid_sql('ap');
        $where[] = match ($status) {
            'Paid' => "a.status = 'Active' AND ap.participation_status = 'Active' AND (ap.expected_amount <= 0.005 OR {$paidSql} >= ap.expected_amount)",
            'Partial', 'Partially Paid' => "a.status = 'Active' AND ap.participation_status = 'Active' AND {$paidSql} > 0 AND {$paidSql} < ap.expected_amount",
            'Outstanding' => "a.status = 'Active' AND ap.participation_status = 'Active' AND {$paidSql} < ap.expected_amount",
            default => "a.status = 'Active' AND ap.participation_status = 'Active' AND ap.expected_amount > 0 AND {$paidSql} <= 0",
        };
    }
    if ($class !== '') {
        $where[] = student_class_sql('s', 'pay.academic_year') . ' = :payment_class';
        $params['payment_class'] = $class;
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare(
        "SELECT pay.id AS payment_id, pay.receipt_no, pay.amount, pay.payment_date, pay.academic_year, pay.term,
                pay.payment_method, pay.reference_no, pay.status AS payment_status, pay.reversal_reason,
                a.name AS activity_name, a.activity_type, s.full_name AS student_name, s.registration_no,
                " . student_class_sql('s', 'pay.academic_year') . " AS class_level
         FROM activity_payments pay
         JOIN activities a ON a.id = pay.activity_id
         JOIN activity_participants ap ON ap.id = pay.participant_id
         JOIN students s ON s.id = ap.student_id{$sqlWhere}
         ORDER BY pay.payment_date DESC, pay.id DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

