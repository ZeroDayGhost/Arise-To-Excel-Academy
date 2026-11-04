<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = in_array('--apply', $argv, true);
$host = getenv('ARISE_DB_HOST') ?: 'localhost';
$database = getenv('ARISE_DB_NAME') ?: 'arise_to_excel';
$username = getenv('ARISE_DB_USER') ?: 'root';
$password = getenv('ARISE_DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $exception) {
    fwrite(STDERR, "Unable to connect to the configured MySQL database. Check the XAMPP service and ARISE_DB_* settings.\n");
    exit(1);
}

$lockName = 'arise_student_identity_migration';
$lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 30)');
$lock->execute(['lock_name' => $lockName]);
if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Another student identity migration is running. Try again later.\n");
    exit(1);
}

function student_migration_column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $statement->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function student_migration_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
    );
    $statement->execute(['table_name' => $table, 'index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function student_migration_restrict_student_deletes(PDO $pdo): int
{
    $constraints = $pdo->query(
        "SELECT DISTINCT rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
          AND kcu.TABLE_NAME = rc.TABLE_NAME
          AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
          AND kcu.TABLE_NAME IS NOT NULL AND kcu.REFERENCED_TABLE_NAME = 'students'
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.DELETE_RULE <> 'RESTRICT'"
    )->fetchAll();
    $changed = 0;
    foreach ($constraints as $constraint) {
        $columns = $pdo->prepare(
            'SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name
             ORDER BY ORDINAL_POSITION'
        );
        $columns->execute(['table_name' => $constraint['TABLE_NAME'], 'constraint_name' => $constraint['CONSTRAINT_NAME']]);
        $columnRows = $columns->fetchAll();
        if (!$columnRows) {
            throw new RuntimeException('Unable to inspect student foreign key ' . $constraint['CONSTRAINT_NAME'] . '.');
        }
        $quote = static fn(string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
        $childColumns = implode(', ', array_map(static fn(array $row): string => $quote((string) $row['COLUMN_NAME']), $columnRows));
        $parentColumns = implode(', ', array_map(static fn(array $row): string => $quote((string) $row['REFERENCED_COLUMN_NAME']), $columnRows));
        $table = $quote((string) $constraint['TABLE_NAME']);
        $name = $quote((string) $constraint['CONSTRAINT_NAME']);
        $updateRule = strtoupper((string) $constraint['UPDATE_RULE']);
        if (!in_array($updateRule, ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'], true)) {
            throw new RuntimeException('Unsupported update rule on student foreign key ' . $constraint['CONSTRAINT_NAME'] . '.');
        }
        $pdo->exec(
            "ALTER TABLE {$table} DROP FOREIGN KEY {$name},
             ADD CONSTRAINT {$name} FOREIGN KEY ({$childColumns}) REFERENCES `students` ({$parentColumns})
             ON DELETE RESTRICT ON UPDATE {$updateRule}"
        );
        $changed++;
    }
    return $changed;
}

function student_migration_create_delete_guard(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_students_prevent_delete'"
    );
    $statement->execute();
    if ((int) $statement->fetchColumn() > 0) {
        return false;
    }
    $pdo->exec(
        "CREATE TRIGGER trg_students_prevent_delete BEFORE DELETE ON students
         FOR EACH ROW SIGNAL SQLSTATE '45000'
         SET MESSAGE_TEXT = 'Student records are permanent; update enrollment status instead'"
    );
    return true;
}

try {
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $duplicateGroups = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT registration_no FROM students GROUP BY registration_no HAVING COUNT(*) > 1
         ) AS duplicate_numbers'
    )->fetchColumn();
    $missingNumbers = (int) $pdo->query(
        "SELECT COUNT(*) FROM students WHERE registration_no IS NULL OR TRIM(registration_no) = ''"
    )->fetchColumn();
    if ($duplicateGroups > 0 || $missingNumbers > 0) {
        throw new RuntimeException("Preflight found {$duplicateGroups} duplicate group(s) and {$missingNumbers} missing number(s). Resolve these individually before migration.");
    }

    $students = $pdo->query('SELECT id, registration_no FROM students ORDER BY id')->fetchAll();
    $hasMap = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_admission_number_migrations'"
    )->fetchColumn() > 0;
    $existingMap = [];
    if ($hasMap) {
        foreach ($pdo->query('SELECT student_id, new_registration_no FROM student_admission_number_migrations')->fetchAll() as $row) {
            $existingMap[(int) $row['student_id']] = (string) $row['new_registration_no'];
        }
    }
    $pdo->rollBack();

    $used = [];
    $maximum = 0;
    foreach ($students as $student) {
        $id = (int) $student['id'];
        $current = (string) $student['registration_no'];
        $number = $existingMap[$id] ?? $current;
        if (preg_match('/^ATX([0-9]{3,})$/', $number, $matches)) {
            $sequence = (int) $matches[1];
            $used[$sequence] = true;
            $maximum = max($maximum, $sequence);
        }
    }

    $plan = [];
    $next = 1;
    foreach ($students as $student) {
        $id = (int) $student['id'];
        $current = (string) $student['registration_no'];
        if (isset($existingMap[$id])) {
            $assigned = $existingMap[$id];
        } elseif (preg_match('/^ATX[0-9]{3,}$/', $current)) {
            $assigned = $current;
        } else {
            while (isset($used[$next])) {
                $next++;
            }
            $assigned = 'ATX' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $used[$next] = true;
            $maximum = max($maximum, $next);
            $next++;
        }
        $plan[] = ['id' => $id, 'old' => $current, 'new' => $assigned];
    }

    $plannedNumbers = array_column($plan, 'new');
    if (count(array_unique($plannedNumbers)) !== count($plannedNumbers)) {
        throw new RuntimeException('The migration mapping would produce duplicate admission numbers. No changes were made.');
    }
    foreach ($plan as $row) {
        if (strlen($row['new']) > 40) {
            throw new RuntimeException('A generated admission number exceeds the existing students.registration_no column size. No changes were made.');
        }
    }

    if (!$apply) {
        echo "DRY RUN - no database changes made.\n";
        echo 'Students checked: ' . count($students) . "\n";
        echo "Duplicate groups: 0; missing admission numbers: 0\n";
        echo "Numbers to change: " . count(array_filter($plan, static fn(array $row): bool => $row['old'] !== $row['new'])) . "\n";
        echo 'Next sequence value: ' . ($maximum + 1) . "\n";
        foreach (array_slice($plan, 0, 8) as $row) {
            echo $row['id'] . ': ' . $row['old'] . ' -> ' . $row['new'] . "\n";
        }
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
        exit(0);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_admission_number_migrations (
            student_id INT UNSIGNED NOT NULL PRIMARY KEY,
            old_registration_no VARCHAR(40) NOT NULL,
            new_registration_no VARCHAR(40) NOT NULL UNIQUE,
            migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_admission_sequence (
            singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            next_number BIGINT UNSIGNED NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    if (!student_migration_column_exists($pdo, 'students', 'enrollment_status')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN enrollment_status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER registration_no");
    }
    if (!student_migration_index_exists($pdo, 'students', 'idx_students_enrollment_status')) {
        $pdo->exec('ALTER TABLE students ADD INDEX idx_students_enrollment_status (enrollment_status)');
    }
    foreach ([
        'module' => 'VARCHAR(60) NULL',
        'action' => 'VARCHAR(80) NULL',
        'module_name' => 'VARCHAR(80) NULL',
        'permission_name' => 'VARCHAR(150) NULL',
    ] as $column => $definition) {
        if (!student_migration_column_exists($pdo, 'permissions', $column)) {
            $pdo->exec("ALTER TABLE permissions ADD COLUMN {$column} {$definition}");
        }
    }
    $registrationIndex = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
           AND COLUMN_NAME = 'registration_no' AND NON_UNIQUE = 0"
    );
    $registrationIndex->execute();
    if ((int) $registrationIndex->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE students ADD UNIQUE KEY uniq_students_registration_no (registration_no)');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_status_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            previous_status VARCHAR(20) NOT NULL,
            new_status VARCHAR(20) NOT NULL,
            effective_date DATE NOT NULL,
            destination_school VARCHAR(150) NULL,
            reason VARCHAR(255) NULL,
            notes TEXT NULL,
            changed_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student_status_history_student (student_id, id),
            CONSTRAINT fk_student_status_history_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_student_status_history_actor FOREIGN KEY (changed_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_historical_fee_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            actor_id INT UNSIGNED NULL,
            reason VARCHAR(500) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_historical_payment (payment_id),
            KEY idx_student_historical_fee_student (student_id, created_at),
            CONSTRAINT fk_student_historical_fee_payment FOREIGN KEY (payment_id) REFERENCES fees(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_student_historical_fee_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_student_historical_fee_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->beginTransaction();
    $lockedStudents = $pdo->query('SELECT id, registration_no, enrollment_status FROM students ORDER BY id FOR UPDATE')->fetchAll();
    $mappingRows = $pdo->query('SELECT student_id, new_registration_no FROM student_admission_number_migrations')->fetchAll();
    $mappingById = [];
    foreach ($mappingRows as $row) {
        $mappingById[(int) $row['student_id']] = (string) $row['new_registration_no'];
    }
    $usedSequences = [];
    $maxSequence = 0;
    foreach ($lockedStudents as $student) {
        $id = (int) $student['id'];
        $candidate = $mappingById[$id] ?? (string) $student['registration_no'];
        if (preg_match('/^ATX([0-9]{3,})$/', $candidate, $matches)) {
            $sequence = (int) $matches[1];
            $usedSequences[$sequence] = true;
            $maxSequence = max($maxSequence, $sequence);
        }
        if (!in_array((string) $student['enrollment_status'], ['Active', 'Transferred Out', 'Graduated', 'Withdrawn'], true)) {
            throw new RuntimeException('An existing enrollment_status is not supported. Resolve it before migration.');
        }
    }

    $nextSequence = 1;
    $finalNumbers = [];
    $mapInsert = $pdo->prepare(
        'INSERT INTO student_admission_number_migrations (student_id, old_registration_no, new_registration_no)
         VALUES (:student_id, :old_registration_no, :new_registration_no)'
    );
    foreach ($lockedStudents as $student) {
        $id = (int) $student['id'];
        $old = (string) $student['registration_no'];
        if (isset($mappingById[$id])) {
            $new = $mappingById[$id];
        } elseif (preg_match('/^ATX[0-9]{3,}$/', $old)) {
            $new = $old;
        } else {
            while (isset($usedSequences[$nextSequence])) {
                $nextSequence++;
            }
            $new = 'ATX' . str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
            $usedSequences[$nextSequence] = true;
            $maxSequence = max($maxSequence, $nextSequence);
            $nextSequence++;
        }
        $finalNumbers[$id] = $new;
        if (!isset($mappingById[$id])) {
            $mapInsert->execute([
                'student_id' => $id,
                'old_registration_no' => $old,
                'new_registration_no' => $new,
            ]);
        }
    }
    if (count(array_unique($finalNumbers)) !== count($finalNumbers)) {
        throw new RuntimeException('Existing migration entries conflict. No student numbers were changed.');
    }

    $temporaryUpdate = $pdo->prepare('UPDATE students SET registration_no = :temporary_no WHERE id = :student_id');
    $finalUpdate = $pdo->prepare('UPDATE students SET registration_no = :registration_no WHERE id = :student_id');
    foreach ($lockedStudents as $student) {
        $id = (int) $student['id'];
        if ((string) $student['registration_no'] !== $finalNumbers[$id]) {
            $temporaryUpdate->execute(['temporary_no' => '__ATX_MIGRATE__' . $id, 'student_id' => $id]);
        }
    }
    foreach ($lockedStudents as $student) {
        $id = (int) $student['id'];
        if ((string) $student['registration_no'] !== $finalNumbers[$id]) {
            $finalUpdate->execute(['registration_no' => $finalNumbers[$id], 'student_id' => $id]);
        }
    }

    $sequenceUpsert = $pdo->prepare(
        'INSERT INTO student_admission_sequence (singleton_id, next_number)
         VALUES (1, :next_number)
         ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number))'
    );
    $sequenceUpsert->execute(['next_number' => $maxSequence + 1]);
    $pdo->commit();

    $deleteGuardCreated = student_migration_create_delete_guard($pdo);
    $restrictedStudentForeignKeys = student_migration_restrict_student_deletes($pdo);

    $permissionRows = [
        ['students.transfer', 'Manage student enrollment status', 'students', 'students.transfer', 'Students'],
        ['students.manage_historical_fees', 'Manage historical student balances', 'students', 'students.manage_historical_fees', 'Students'],
    ];
    $permissionInsert = $pdo->prepare(
        'INSERT INTO permissions (permission_key, label, module, action, module_name, permission_name)
         VALUES (:permission_key, :label, :module, :action, :module_name, :permission_name)
         ON DUPLICATE KEY UPDATE label = VALUES(label), module = VALUES(module), action = VALUES(action), module_name = VALUES(module_name), permission_name = VALUES(permission_name)'
    );
    foreach ($permissionRows as [$key, $label, $module, $action, $moduleName]) {
        $permissionInsert->execute([
            'permission_key' => $key,
            'label' => $label,
            'module' => $module,
            'action' => $action,
            'module_name' => $moduleName,
            'permission_name' => $label,
        ]);
    }
    $hasRoleName = student_migration_column_exists($pdo, 'roles', 'role_name');
    $hasLegacyRoleName = student_migration_column_exists($pdo, 'roles', 'name');
    if (!$hasRoleName && !$hasLegacyRoleName) {
        throw new RuntimeException('The roles table has no supported role-name column.');
    }
    $roleNameExpression = $hasRoleName && $hasLegacyRoleName
        ? 'COALESCE(NULLIF(r.role_name, \'\'), r.name)'
        : ($hasRoleName ? 'r.role_name' : 'r.name');
    $grant = $pdo->prepare(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
         WHERE LOWER(' . $roleNameExpression . ') = LOWER(:role_name)
           AND p.permission_key = :permission_key'
    );
    foreach ([
        ['Director', 'students.transfer'],
        ['Headteacher', 'students.transfer'],
        ['Director', 'students.manage_historical_fees'],
        ['Accountant', 'students.manage_historical_fees'],
    ] as [$roleName, $permissionKey]) {
        $grant->execute(['role_name' => $roleName, 'permission_key' => $permissionKey]);
    }

    echo "Student identity and enrollment schema migration completed.\n";
    echo 'Students checked: ' . count($lockedStudents) . "\n";
    echo 'Admission numbers standardized: ' . count(array_filter($lockedStudents, static fn(array $student): bool => (string) $student['registration_no'] !== $finalNumbers[(int) $student['id']])) . "\n";
    echo 'Next global admission number: ATX' . str_pad((string) ($maxSequence + 1), 3, '0', STR_PAD_LEFT) . "\n";
    echo 'Student foreign keys changed to preserve records: ' . $restrictedStudentForeignKeys . "\n";
    echo 'Student deletion guard ' . ($deleteGuardCreated ? 'created' : 'already active') . ".\n";
    echo "No student IDs or linked historical rows were changed.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($pdo)) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}
