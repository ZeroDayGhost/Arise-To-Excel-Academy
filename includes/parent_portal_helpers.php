<?php

function parent_portal_schema_ready(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('parent_student_links', 'parent_link_audit')");
        return (int) $statement->fetchColumn() === 2;
    }

    $links = $pdo->query("SHOW TABLES LIKE 'parent_student_links'")->fetchColumn();
    $audit = $pdo->query("SHOW TABLES LIKE 'parent_link_audit'")->fetchColumn();
    return $links !== false && $audit !== false;
}

function parent_portal_is_parent_account(PDO $pdo, int $userId, bool $requireActive = true): bool
{
    if ($userId <= 0) {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT a.status, COALESCE(r.role_name, r.name) AS role_name
         FROM admin a
         LEFT JOIN user_roles ur ON ur.user_id = a.id
         LEFT JOIN roles r ON r.id = ur.role_id
         WHERE a.id = :user_id"
    );
    $statement->execute(['user_id' => $userId]);
    $rows = $statement->fetchAll();
    if (!$rows || ($requireActive && ($rows[0]['status'] ?? '') !== 'Active')) {
        return false;
    }

    $roles = array_values(array_unique(array_filter(array_column($rows, 'role_name'), static function ($role): bool {
        return is_string($role) && $role !== '';
    })));
    return $roles === ['Parent'];
}

function parent_portal_clear_role_grants(PDO $pdo, int $roleId): void
{
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
    $pdo->prepare('DELETE FROM module_access WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
}

function parent_portal_term_options(): array
{
    return ['Term 1', 'Term 2', 'Term 3'];
}

function parent_portal_normalize_year($value): ?string
{
    $year = trim((string) $value);
    return preg_match('/^(20|21)\d{2}$/', $year) === 1 ? $year : null;
}

function parent_portal_children(PDO $pdo, int $parentId, string $year, string $term): array
{
    ensure_student_class_history_schema($pdo);
    $classLevelSql = student_class_sql_for_year('s', $year);
    $statement = $pdo->prepare(
        "SELECT s.id, s.registration_no, s.full_name, {$classLevelSql} AS class_level, s.enrollment_status,
                s.gender, s.parent_name, s.guardian_phone, s.guardian_alternate_phone, s.guardian_email,
                fb.id AS fee_balance_id,
                COALESCE(fb.required_amount, 0.00) AS assessed_amount,
                COALESCE(fb.paid_amount, 0.00) AS paid_amount,
                COALESCE(fb.balance, 0.00) AS balance
         FROM parent_student_links pl
         JOIN students s ON s.id = pl.student_id
         LEFT JOIN fee_balances fb
           ON fb.student_id = s.id
          AND fb.academic_year = :year
          AND fb.term = :term
         WHERE pl.parent_user_id = :parent_id
           AND pl.is_active = 1
         ORDER BY s.full_name, s.registration_no"
    );
    $statement->execute(['year' => $year, 'term' => $term, 'parent_id' => $parentId]);
    $children = $statement->fetchAll();
    foreach ($children as &$child) {
        $child['fee_sponsorship_status'] = student_fee_sponsorship_status($pdo, (int) $child['id'], $year);
        if ($child['fee_sponsorship_status'] === 'Sponsored Student') {
            $child['assessed_amount'] = 0.0;
            $child['balance'] = 0.0;
        }
    }
    unset($child);
    return $children;
}

function parent_portal_child(PDO $pdo, int $parentId, int $studentId, ?string $year = null): ?array
{
    ensure_student_class_history_schema($pdo);
    $classLevelSql = $year !== null && preg_match('/^\d{4}$/', $year)
        ? student_class_sql_for_year('s', $year)
        : 's.class_level';
    $statement = $pdo->prepare(
        "SELECT s.id, s.registration_no, s.full_name, {$classLevelSql} AS class_level, s.enrollment_status
         FROM parent_student_links pl
         JOIN students s ON s.id = pl.student_id
         WHERE pl.parent_user_id = :parent_id
           AND pl.student_id = :student_id
           AND pl.is_active = 1
         LIMIT 1"
    );
    $statement->execute(['parent_id' => $parentId, 'student_id' => $studentId]);
    $period = $statement->fetch();
    if (!$period) {
        return null;
    }
    $period['fee_sponsorship_status'] = student_fee_sponsorship_status($pdo, $studentId, $year);
    if ($period['fee_sponsorship_status'] === 'Sponsored Student') {
        $period['assessed_amount'] = 0.0;
        $period['balance'] = 0.0;
    }
    return $period;
}

function parent_portal_child_period(PDO $pdo, int $parentId, int $studentId, string $year, string $term): ?array
{
    $statement = $pdo->prepare(
        "SELECT fb.required_amount AS assessed_amount,
                fb.paid_amount,
                fb.balance,
                fb.id AS fee_balance_id
         FROM parent_student_links pl
         LEFT JOIN fee_balances fb
           ON fb.student_id = pl.student_id
          AND fb.academic_year = :year
          AND fb.term = :term
         WHERE pl.parent_user_id = :parent_id
           AND pl.student_id = :student_id
           AND pl.is_active = 1
         LIMIT 1"
    );
    $statement->execute([
        'year' => $year,
        'term' => $term,
        'parent_id' => $parentId,
        'student_id' => $studentId,
    ]);
    return $statement->fetch() ?: null;
}

function parent_portal_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $statement->execute(['table' => $table]);
        return (bool) $statement->fetchColumn();
    }
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    $statement->execute(['table' => $table]);
    return $statement->fetchColumn() !== false;
}

function parent_portal_has_service_transactions(PDO $pdo, int $parentId, string $service): bool
{
    if ($parentId <= 0) {
        return false;
    }

    $queries = [
        'feeding' => [
            ['feeding_payments', 'feeding_subscriptions'],
            "SELECT 1 FROM feeding_payments fp
             JOIN feeding_subscriptions fs ON fs.id = fp.feeding_subscription_id
             JOIN parent_student_links pl ON pl.student_id = fs.student_id
             WHERE pl.parent_user_id = :parent_id AND pl.is_active = 1 LIMIT 1",
        ],
        'transport' => [
            ['transport_payments', 'transport_accounts', 'transport_students'],
            "SELECT 1 FROM transport_payments tp
             JOIN transport_accounts ta ON ta.id = tp.transport_account_id
             JOIN transport_students ts ON ts.id = ta.transport_student_id
             JOIN parent_student_links pl ON pl.student_id = ts.student_id
             WHERE pl.parent_user_id = :parent_id AND pl.is_active = 1 LIMIT 1",
        ],
        'activity' => [
            ['activity_payments', 'activity_participants'],
            "SELECT 1 FROM activity_payments ap
             JOIN activity_participants participant ON participant.id = ap.participant_id
             JOIN parent_student_links pl ON pl.student_id = participant.student_id
             WHERE pl.parent_user_id = :parent_id AND pl.is_active = 1 LIMIT 1",
        ],
        'uniform' => [
            ['uniform_sales'],
            "SELECT 1 FROM uniform_sales us
             JOIN parent_student_links pl ON pl.student_id = us.student_id
             WHERE pl.parent_user_id = :parent_id AND pl.is_active = 1 AND us.amount_paid > 0 LIMIT 1",
        ],
    ];

    if (!isset($queries[$service])) {
        return false;
    }

    [$tables, $sql] = $queries[$service];
    foreach ($tables as $table) {
        if (!parent_portal_table_exists($pdo, $table)) {
            return false;
        }
    }

    $statement = $pdo->prepare($sql);
    $statement->execute(['parent_id' => $parentId]);
    if ($statement->fetchColumn() !== false) {
        return true;
    }

    if ($service === 'uniform' && parent_portal_table_exists($pdo, 'uniform_sale_payments')) {
        $statement = $pdo->prepare(
            "SELECT 1 FROM uniform_sale_payments usp
             JOIN uniform_sales us ON us.id = usp.sale_id
             JOIN parent_student_links pl ON pl.student_id = us.student_id
             WHERE pl.parent_user_id = :parent_id AND pl.is_active = 1 AND usp.amount_paid > 0 LIMIT 1"
        );
        $statement->execute(['parent_id' => $parentId]);
        return $statement->fetchColumn() !== false;
    }

    return false;
}

function parent_portal_calendar_period(PDO $pdo, string $year, string $term): ?array
{
    if (!parent_portal_table_exists($pdo, 'academic_calendar')) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1'
    );
    $statement->execute(['year' => $year, 'term' => $term]);
    return $statement->fetch() ?: null;
}

function parent_portal_service_balances(PDO $pdo, int $parentId, int $studentId, string $year, string $term): array
{
    if (!parent_portal_child($pdo, $parentId, $studentId, $year)) {
        return [];
    }

    $services = [];
    $addService = static function (string $name, array $row) use (&$services): void {
        if ((int) ($row['account_count'] ?? 0) > 0) {
            $services[] = [
                'name' => $name,
                'assessed_amount' => (float) ($row['assessed_amount'] ?? 0),
                'paid_amount' => (float) ($row['paid_amount'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
                'account_count' => (int) $row['account_count'],
            ];
        }
    };

    if (parent_portal_table_exists($pdo, 'feeding_subscriptions') && parent_portal_table_exists($pdo, 'feeding_payments')) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS account_count,
                    COALESCE(SUM(fs.feeding_amount), 0) AS assessed_amount,
                    COALESCE(SUM(COALESCE(paid.amount, 0)), 0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN fs.feeding_amount - COALESCE(paid.amount, 0) > 0 THEN fs.feeding_amount - COALESCE(paid.amount, 0) ELSE 0 END), 0) AS balance
             FROM feeding_subscriptions fs
             LEFT JOIN (
                 SELECT feeding_subscription_id, SUM(amount_paid) AS amount
                 FROM feeding_payments GROUP BY feeding_subscription_id
             ) paid ON paid.feeding_subscription_id = fs.id
             WHERE fs.student_id = :student_id AND fs.academic_year = :year AND fs.term = :term AND fs.status = 'Active'
               AND EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :parent_id AND pl.student_id = fs.student_id AND pl.is_active = 1)"
        );
        $statement->execute(['student_id' => $studentId, 'year' => $year, 'term' => $term, 'parent_id' => $parentId]);
        $addService('Feeding', $statement->fetch() ?: []);
    }

    if (parent_portal_table_exists($pdo, 'transport_accounts') && parent_portal_table_exists($pdo, 'transport_students') && parent_portal_table_exists($pdo, 'transport_payments')) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS account_count,
                    COALESCE(SUM(ta.amount_due), 0) AS assessed_amount,
                    COALESCE(SUM(COALESCE(paid.amount, 0)), 0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN ta.amount_due - COALESCE(paid.amount, 0) > 0 THEN ta.amount_due - COALESCE(paid.amount, 0) ELSE 0 END), 0) AS balance
             FROM transport_accounts ta
             JOIN transport_students ts ON ts.id = ta.transport_student_id
             LEFT JOIN (
                 SELECT transport_account_id, SUM(amount_paid) AS amount
                 FROM transport_payments GROUP BY transport_account_id
             ) paid ON paid.transport_account_id = ta.id
             WHERE ts.student_id = :student_id AND ta.academic_year = :year AND ta.term = :term AND ta.status = 'Active'
               AND EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :parent_id AND pl.student_id = ts.student_id AND pl.is_active = 1)"
        );
        $statement->execute(['student_id' => $studentId, 'year' => $year, 'term' => $term, 'parent_id' => $parentId]);
        $addService('Transport', $statement->fetch() ?: []);
    }

    if (parent_portal_table_exists($pdo, 'activities') && parent_portal_table_exists($pdo, 'activity_participants') && parent_portal_table_exists($pdo, 'activity_payments')) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS account_count,
                    COALESCE(SUM(ap.expected_amount), 0) AS assessed_amount,
                    COALESCE(SUM(COALESCE(paid.amount, 0)), 0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN ap.expected_amount - COALESCE(paid.amount, 0) > 0 THEN ap.expected_amount - COALESCE(paid.amount, 0) ELSE 0 END), 0) AS balance
             FROM activity_participants ap
             JOIN activities a ON a.id = ap.activity_id
             LEFT JOIN (
                 SELECT participant_id, SUM(amount) AS amount
                 FROM activity_payments WHERE status = 'Recorded' GROUP BY participant_id
             ) paid ON paid.participant_id = ap.id
             WHERE ap.student_id = :student_id AND a.academic_year = :year AND a.term = :term
               AND ap.participation_status = 'Active' AND a.status = 'Active'
               AND EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :parent_id AND pl.student_id = ap.student_id AND pl.is_active = 1)"
        );
        $statement->execute(['student_id' => $studentId, 'year' => $year, 'term' => $term, 'parent_id' => $parentId]);
        $addService('Activities & Trips', $statement->fetch() ?: []);
    }

    if (parent_portal_table_exists($pdo, 'uniform_sales')) {
        $calendar = parent_portal_calendar_period($pdo, $year, $term);
        if ($calendar) {
            $paidAmount = 'us.amount_paid';
            $statement = $pdo->prepare(
                "SELECT COUNT(*) AS account_count,
                        COALESCE(SUM(us.grand_total), 0) AS assessed_amount,
                        COALESCE(SUM({$paidAmount}), 0) AS paid_amount,
                        COALESCE(SUM(us.balance), 0) AS balance
                 FROM uniform_sales us
                 WHERE us.student_id = :student_id AND DATE(us.payment_date) BETWEEN :start_date AND :end_date
                   AND EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :parent_id AND pl.student_id = us.student_id AND pl.is_active = 1)"
            );
            $statement->execute([
                'student_id' => $studentId,
                'start_date' => $calendar['start_date'],
                'end_date' => $calendar['end_date'],
                'parent_id' => $parentId,
            ]);
            $addService('School Uniform', $statement->fetch() ?: []);
        }
    }

    return $services;
}

function parent_portal_collect_payment_rows(PDO $pdo, int $parentId, string $year, string $term, ?int $studentId = null): array
{
    $rows = [];
    $scope = static function (string $studentExpression, string $key) use ($studentId): string {
        $studentFilter = $studentId !== null ? " AND {$studentExpression} = :{$key}_student" : '';
        return "EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :{$key}_parent AND pl.student_id = {$studentExpression} AND pl.is_active = 1){$studentFilter}";
    };
    $fetch = static function (string $sql, array $params) use ($pdo, &$rows): void {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        array_push($rows, ...$statement->fetchAll());
    };
    $params = ['fees_parent' => $parentId, 'fees_year' => $year, 'fees_term' => $term];
    if ($studentId !== null) $params['fees_student'] = $studentId;
    $fetch(
        "SELECT f.student_id, 'fees' AS source_module, 'School Fees' AS category, '' AS description,
                f.id AS source_id, f.receipt_no, f.mpesa_code AS payment_reference,
                f.payment_status, f.payment_date, f.amount_paid, f.year AS academic_year, f.term
         FROM fees f WHERE {$scope('f.student_id', 'fees')} AND f.year = :fees_year AND f.term = :fees_term",
        $params
    );

    if (parent_portal_table_exists($pdo, 'feeding_payments') && parent_portal_table_exists($pdo, 'feeding_subscriptions')) {
        $params = ['feeding_parent' => $parentId, 'feeding_year' => $year, 'feeding_term' => $term];
        if ($studentId !== null) $params['feeding_student'] = $studentId;
        $fetch(
            "SELECT fs.student_id, 'feeding' AS source_module, 'Feeding' AS category, 'Feeding contribution' AS description,
                    fp.id AS source_id, NULL AS receipt_no, fp.reference_no AS payment_reference,
                    'Recorded' AS payment_status, fp.payment_date, fp.amount_paid,
                    fs.academic_year, fs.term
             FROM feeding_payments fp JOIN feeding_subscriptions fs ON fs.id = fp.feeding_subscription_id
             WHERE {$scope('fs.student_id', 'feeding')} AND fs.academic_year = :feeding_year AND fs.term = :feeding_term",
            $params
        );
    }

    if (parent_portal_table_exists($pdo, 'transport_payments') && parent_portal_table_exists($pdo, 'transport_accounts') && parent_portal_table_exists($pdo, 'transport_students')) {
        $params = ['transport_parent' => $parentId, 'transport_year' => $year, 'transport_term' => $term];
        if ($studentId !== null) $params['transport_student'] = $studentId;
        $fetch(
            "SELECT ts.student_id, 'transport' AS source_module, 'Transport' AS category, 'Transport payment' AS description,
                    tp.id AS source_id, NULL AS receipt_no, tp.reference_no AS payment_reference,
                    'Recorded' AS payment_status, tp.payment_date, tp.amount_paid,
                    ta.academic_year, ta.term
             FROM transport_payments tp JOIN transport_accounts ta ON ta.id = tp.transport_account_id
             JOIN transport_students ts ON ts.id = ta.transport_student_id
             WHERE {$scope('ts.student_id', 'transport')} AND ta.academic_year = :transport_year AND ta.term = :transport_term",
            $params
        );
    }

    if (parent_portal_table_exists($pdo, 'activity_payments') && parent_portal_table_exists($pdo, 'activity_participants') && parent_portal_table_exists($pdo, 'activities')) {
        $params = ['activity_parent' => $parentId, 'activity_year' => $year, 'activity_term' => $term];
        if ($studentId !== null) $params['activity_student'] = $studentId;
        $fetch(
            "SELECT participant.student_id, 'activity' AS source_module, 'Activities & Trips' AS category, a.name AS description,
                    apay.id AS source_id, apay.receipt_no, apay.reference_no AS payment_reference,
                    apay.status AS payment_status, apay.payment_date, apay.amount AS amount_paid,
                    apay.academic_year, apay.term
             FROM activity_payments apay JOIN activity_participants participant ON participant.id = apay.participant_id
             JOIN activities a ON a.id = apay.activity_id
             WHERE {$scope('participant.student_id', 'activity')} AND apay.academic_year = :activity_year AND apay.term = :activity_term",
            $params
        );
    }

    if (parent_portal_table_exists($pdo, 'uniform_sale_payments') && parent_portal_table_exists($pdo, 'uniform_sales')) {
        $params = ['uniform_parent' => $parentId, 'uniform_year' => $year, 'uniform_term' => $term];
        if ($studentId !== null) $params['uniform_student'] = $studentId;
        $fetch(
            "SELECT us.student_id, 'uniform' AS source_module, 'School Uniform' AS category, 'Uniform sale' AS description,
                    usp.id AS source_id, us.receipt_no, usp.reference_no AS payment_reference,
                    'Recorded' AS payment_status, usp.payment_date, usp.amount_paid,
                    usp.academic_year, usp.term
             FROM uniform_sale_payments usp JOIN uniform_sales us ON us.id = usp.sale_id
             WHERE {$scope('us.student_id', 'uniform')} AND usp.academic_year = :uniform_year AND usp.term = :uniform_term",
            $params
        );
    }

    $calendar = parent_portal_calendar_period($pdo, $year, $term);
    if (parent_portal_table_exists($pdo, 'uniform_sales') && $calendar) {
        $installmentsAvailable = parent_portal_table_exists($pdo, 'uniform_sale_payments');
        $paidAtCreation = $installmentsAvailable
            ? 'CASE WHEN us.amount_paid - COALESCE(SUM(usp.amount_paid), 0) > 0 THEN us.amount_paid - COALESCE(SUM(usp.amount_paid), 0) ELSE 0 END'
            : 'us.amount_paid';
        $installmentJoin = $installmentsAvailable
            ? 'LEFT JOIN uniform_sale_payments usp ON usp.sale_id = us.id'
            : '';
        $params = [
            'uniform_base_parent' => $parentId,
            'uniform_base_year' => $year,
            'uniform_base_term' => $term,
            'uniform_base_start' => $calendar['start_date'],
            'uniform_base_end' => $calendar['end_date'],
        ];
        if ($studentId !== null) $params['uniform_base_student'] = $studentId;
        $fetch(
            "SELECT us.student_id, 'uniform' AS source_module, 'School Uniform' AS category, 'Uniform sale' AS description,
                    us.id AS source_id, us.receipt_no, us.mpesa_code AS payment_reference,
                    'Recorded' AS payment_status, DATE(us.payment_date) AS payment_date, {$paidAtCreation} AS amount_paid,
                    :uniform_base_year AS academic_year, :uniform_base_term AS term
             FROM uniform_sales us {$installmentJoin}
             WHERE {$scope('us.student_id', 'uniform_base')} AND DATE(us.payment_date) BETWEEN :uniform_base_start AND :uniform_base_end
             GROUP BY us.id, us.student_id, us.receipt_no, us.mpesa_code, us.payment_date, us.amount_paid
             HAVING {$paidAtCreation} > 0",
            $params
        );
    }

    usort($rows, static function (array $a, array $b): int {
        $dateOrder = strcmp((string) ($b['payment_date'] ?? ''), (string) ($a['payment_date'] ?? ''));
        return $dateOrder !== 0 ? $dateOrder : ((int) ($b['source_id'] ?? 0) <=> (int) ($a['source_id'] ?? 0));
    });
    return $rows;
}

function parent_portal_payments(PDO $pdo, int $parentId, int $studentId, string $year, string $term): array
{
    return parent_portal_collect_payment_rows($pdo, $parentId, $year, $term, $studentId);
}

function parent_portal_recent_payments(PDO $pdo, int $parentId, string $year, string $term, int $limit = 10, bool $allTerms = false): array
{
    $limit = max(1, min($limit, 25));
    $rows = [];
    $periods = $allTerms ? parent_portal_term_options() : [$term];
    foreach ($periods as $periodTerm) {
        array_push($rows, ...parent_portal_collect_payment_rows($pdo, $parentId, $year, $periodTerm));
    }
    if (!$rows) {
        return [];
    }
    usort($rows, static function (array $a, array $b): int {
        $dateOrder = strcmp((string) ($b['payment_date'] ?? ''), (string) ($a['payment_date'] ?? ''));
        return $dateOrder !== 0 ? $dateOrder : ((int) ($b['source_id'] ?? 0) <=> (int) ($a['source_id'] ?? 0));
    });
    $studentIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int) ($row['student_id'] ?? 0), $rows))));
    if (!$studentIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $students = $pdo->prepare("SELECT id, registration_no, full_name, enrollment_status FROM students WHERE id IN ({$placeholders})");
    $students->execute($studentIds);
    $studentMap = [];
    foreach ($students->fetchAll() as $student) $studentMap[(int) $student['id']] = $student;

    foreach ($rows as &$row) {
        $student = $studentMap[(int) ($row['student_id'] ?? 0)] ?? [];
        $row['registration_no'] = $student['registration_no'] ?? '';
        $row['full_name'] = $student['full_name'] ?? '';
        $row['enrollment_status'] = $student['enrollment_status'] ?? '';
    }
    unset($row);
    return array_slice($rows, 0, $limit);
}

function parent_portal_receipt(PDO $pdo, int $parentId, int $paymentId): ?array
{
    ensure_student_class_history_schema($pdo);
    $statement = $pdo->prepare(
        "SELECT f.id, f.receipt_no, f.amount_paid, f.mpesa_code, f.mpesa_reference_text,
                f.payment_method, f.payment_status, f.reversal_reason,
                f.term, f.year, f.balance_after_payment, f.payment_date,
                s.id AS student_id, s.registration_no, s.full_name, " . student_class_sql('s', 'f.year') . " AS class_level, s.enrollment_status
         FROM fees f
         JOIN students s ON s.id = f.student_id
         JOIN parent_student_links pl ON pl.student_id = s.id
         WHERE pl.parent_user_id = :parent_id
           AND pl.is_active = 1
           AND f.id = :payment_id
         LIMIT 1"
    );
    $statement->execute(['parent_id' => $parentId, 'payment_id' => $paymentId]);
    return $statement->fetch() ?: null;
}

function parent_portal_available_years(PDO $pdo, int $parentId): array
{
    $queries = [
        "SELECT academic_year AS year FROM fee_balances fb WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :balance_parent AND pl.student_id = fb.student_id AND pl.is_active = 1)",
        "SELECT f.year AS year FROM fees f WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :payment_parent AND pl.student_id = f.student_id AND pl.is_active = 1)",
    ];
    $params = ['balance_parent' => $parentId, 'payment_parent' => $parentId];
    if (parent_portal_table_exists($pdo, 'feeding_subscriptions')) {
        $queries[] = "SELECT fs.academic_year AS year FROM feeding_subscriptions fs WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :feeding_parent AND pl.student_id = fs.student_id AND pl.is_active = 1)";
        $params['feeding_parent'] = $parentId;
    }
    if (parent_portal_table_exists($pdo, 'transport_accounts') && parent_portal_table_exists($pdo, 'transport_students')) {
        $queries[] = "SELECT ta.academic_year AS year FROM transport_accounts ta JOIN transport_students ts ON ts.id = ta.transport_student_id WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :transport_parent AND pl.student_id = ts.student_id AND pl.is_active = 1)";
        $params['transport_parent'] = $parentId;
    }
    if (parent_portal_table_exists($pdo, 'activities') && parent_portal_table_exists($pdo, 'activity_participants')) {
        $queries[] = "SELECT a.academic_year AS year FROM activities a JOIN activity_participants ap ON ap.activity_id = a.id WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :activity_parent AND pl.student_id = ap.student_id AND pl.is_active = 1)";
        $params['activity_parent'] = $parentId;
    }
    if (parent_portal_table_exists($pdo, 'uniform_sale_payments') && parent_portal_table_exists($pdo, 'uniform_sales')) {
        $queries[] = "SELECT usp.academic_year AS year FROM uniform_sale_payments usp JOIN uniform_sales us ON us.id = usp.sale_id WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :uniform_parent AND pl.student_id = us.student_id AND pl.is_active = 1)";
        $params['uniform_parent'] = $parentId;
    }
    if (parent_portal_table_exists($pdo, 'uniform_sales')) {
        $queries[] = "SELECT SUBSTR(us.payment_date, 1, 4) AS year FROM uniform_sales us WHERE EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = :uniform_sale_parent AND pl.student_id = us.student_id AND pl.is_active = 1)";
        $params['uniform_sale_parent'] = $parentId;
    }
    $statement = $pdo->prepare(implode(' UNION ', $queries) . ' ORDER BY year DESC');
    $statement->execute($params);
    $years = array_values(array_filter(array_map('parent_portal_normalize_year', array_column($statement->fetchAll(), 'year'))));
    return array_values(array_unique($years));
}

function parent_portal_audit(PDO $pdo, int $actorId, int $parentId, ?int $studentId, string $action, ?string $details = null): void
{
    $statement = $pdo->prepare(
        "INSERT INTO parent_link_audit (actor_admin_id, parent_user_id, student_id, action, details)
         VALUES (:actor_id, :parent_id, :student_id, :action, :details)"
    );
    $statement->execute([
        'actor_id' => $actorId,
        'parent_id' => $parentId,
        'student_id' => $studentId,
        'action' => $action,
        'details' => $details,
    ]);
}

function parent_portal_link_child(
    PDO $pdo,
    int $actorId,
    int $parentId,
    int $studentId,
    ?string $relationshipType = null,
    bool $isPrimary = false,
    bool $allowExisting = false
): string
{
    if (!parent_portal_is_parent_account($pdo, $parentId, false) || $studentId <= 0) {
        throw new InvalidArgumentException('Select a valid Parent account and student.');
    }
    $studentCheck = $pdo->prepare('SELECT id, enrollment_status FROM students WHERE id = :id LIMIT 1');
    $studentCheck->execute(['id' => $studentId]);
    if (!$studentCheck->fetch()) {
        throw new InvalidArgumentException('The selected student could not be found.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $existing = $pdo->prepare(
            'SELECT id, is_active, relationship_type, is_primary FROM parent_student_links WHERE parent_user_id = :parent_id AND student_id = :student_id LIMIT 1' . $lock
        );
        $existing->execute(['parent_id' => $parentId, 'student_id' => $studentId]);
        $link = $existing->fetch();
        $studentLock = $pdo->prepare('SELECT enrollment_status FROM students WHERE id = :id LIMIT 1' . $lock);
        $studentLock->execute(['id' => $studentId]);
        $studentStatus = $studentLock->fetchColumn();
        if ($studentStatus !== 'Active' && !($link && (int) $link['is_active'] === 1)) {
            throw new InvalidArgumentException('Only active students can receive a new parent-child link.');
        }
        if ($link && (int) $link['is_active'] === 1) {
            if (!$allowExisting) {
                throw new InvalidArgumentException('This child is already linked to that parent account.');
            }
            if ((string) ($link['relationship_type'] ?? '') !== (string) ($relationshipType ?? '')
                || (int) $link['is_primary'] !== ($isPrimary ? 1 : 0)) {
                $update = $pdo->prepare(
                    'UPDATE parent_student_links SET relationship_type = :relationship_type, is_primary = :is_primary WHERE id = :id'
                );
                $update->execute([
                    'relationship_type' => $relationshipType,
                    'is_primary' => $isPrimary ? 1 : 0,
                    'id' => (int) $link['id'],
                ]);
                parent_portal_audit($pdo, $actorId, $parentId, $studentId, 'guardian_details_updated');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return 'link_exists';
        }
        if ($link) {
            $update = $pdo->prepare(
                'UPDATE parent_student_links SET is_active = 1, relationship_type = :relationship_type, is_primary = :is_primary, linked_by = :actor_id, linked_at = CURRENT_TIMESTAMP, unlinked_by = NULL, unlinked_at = NULL WHERE id = :id'
            );
            $update->execute([
                'relationship_type' => $relationshipType,
                'is_primary' => $isPrimary ? 1 : 0,
                'actor_id' => $actorId,
                'id' => (int) $link['id'],
            ]);
            $event = 'link_reactivated';
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO parent_student_links (parent_user_id, student_id, relationship_type, is_primary, is_active, linked_by)
                 VALUES (:parent_id, :student_id, :relationship_type, :is_primary, 1, :actor_id)'
            );
            $insert->execute([
                'parent_id' => $parentId,
                'student_id' => $studentId,
                'relationship_type' => $relationshipType,
                'is_primary' => $isPrimary ? 1 : 0,
                'actor_id' => $actorId,
            ]);
            $event = 'child_linked';
        }
        parent_portal_audit($pdo, $actorId, $parentId, $studentId, $event);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $event;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function parent_portal_unlink_child(PDO $pdo, int $actorId, int $linkId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $find = $pdo->prepare('SELECT id, parent_user_id, student_id FROM parent_student_links WHERE id = :id AND is_active = 1 LIMIT 1' . $lock);
        $find->execute(['id' => $linkId]);
        $link = $find->fetch();
        if (!$link || !parent_portal_is_parent_account($pdo, (int) $link['parent_user_id'], false)) {
            throw new InvalidArgumentException('The active parent-child link could not be found.');
        }
        $update = $pdo->prepare(
            'UPDATE parent_student_links SET is_active = 0, unlinked_by = :actor_id, unlinked_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1'
        );
        $update->execute(['actor_id' => $actorId, 'id' => $linkId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The relationship has already changed. Refresh the page and try again.');
        }
        parent_portal_audit($pdo, $actorId, (int) $link['parent_user_id'], (int) $link['student_id'], 'child_unlinked');
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function parent_portal_delete_link(PDO $pdo, int $actorId, int $linkId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $find = $pdo->prepare('SELECT id, parent_user_id, student_id FROM parent_student_links WHERE id = :id AND is_active = 1 LIMIT 1' . $lock);
        $find->execute(['id' => $linkId]);
        $link = $find->fetch();
        if (!$link) {
            throw new InvalidArgumentException('This link is no longer active. Refresh the page and try again.');
        }

        parent_portal_audit($pdo, $actorId, (int) $link['parent_user_id'], (int) $link['student_id'], 'child_link_deleted');
        $delete = $pdo->prepare('DELETE FROM parent_student_links WHERE id = :id AND is_active = 1');
        $delete->execute(['id' => $linkId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('The relationship has already changed. Refresh the page and try again.');
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
