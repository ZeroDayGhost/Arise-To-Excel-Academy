<?php
require_once __DIR__ . '/app.php';

// Use environment variables on hosting; local XAMPP defaults are development-only.
$appEnvironment = strtolower((string) (getenv('ARISE_APP_ENV') ?: 'local'));
$dbHostEnv = getenv('ARISE_DB_HOST');
$dbNameEnv = getenv('ARISE_DB_NAME');
$dbUserEnv = getenv('ARISE_DB_USER');
$dbPassEnv = getenv('ARISE_DB_PASS');
$dbPortEnv = getenv('ARISE_DB_PORT');
$dbSocketEnv = getenv('ARISE_DB_SOCKET');

if ($appEnvironment === 'production'
    && ($dbHostEnv === false || $dbHostEnv === ''
        || $dbNameEnv === false || $dbNameEnv === ''
        || $dbUserEnv === false || $dbUserEnv === ''
        || $dbPassEnv === false || $dbPassEnv === '')) {
    error_log('Production database configuration is incomplete.');
    http_response_code(500);
    echo '<h1>Database configuration is incomplete</h1>';
    echo '<p>Please configure the application database credentials with the hosting provider.</p>';
    exit;
}

$dbHost = $dbHostEnv !== false && $dbHostEnv !== '' ? $dbHostEnv : 'localhost';
$dbName = $dbNameEnv !== false && $dbNameEnv !== '' ? $dbNameEnv : 'arise_to_excel';
$dbUser = $dbUserEnv !== false && $dbUserEnv !== '' ? $dbUserEnv : 'root';
$dbPass = $dbPassEnv !== false ? $dbPassEnv : '';
$dbPort = $dbPortEnv !== false && ctype_digit($dbPortEnv) ? (int) $dbPortEnv : 3306;
$dbSocket = $dbSocketEnv !== false && $dbSocketEnv !== '' ? $dbSocketEnv : null;

try {
    $dsn = $dbSocket !== null
        ? "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4"
        : "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    function get_pdo(): PDO
    {
        global $pdo;
        return $pdo;
    }
} catch (PDOException $exception) {
    error_log('Database connection failed with SQLSTATE ' . (string) $exception->getCode());
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    echo '<p>Please check that the local database service is running and contact the system administrator.</p>';
    exit;
}

try {
    // Compatibility for databases created before the latest fee-management fields were added.
    $classLevelColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'class_level'")->fetch();
    if (!$classLevelColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN class_level VARCHAR(30) NOT NULL DEFAULT '' AFTER full_name");
    }

    $genderColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'gender'")->fetch();
    if (!$genderColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN gender VARCHAR(10) NOT NULL DEFAULT '' AFTER full_name");
    }

    $classNameColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'class_name'")->fetch();
    if ($classNameColumn) {
        $pdo->exec("UPDATE students SET class_level = class_name WHERE class_level = '' AND class_name IS NOT NULL AND class_name <> ''");
        $pdo->exec("ALTER TABLE students MODIFY class_name VARCHAR(80) NULL DEFAULT NULL");
    }

    $parentColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'parent_name'")->fetch();
    if (!$parentColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN parent_name VARCHAR(150) NOT NULL DEFAULT '' AFTER class_level");
    }

    $guardianColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'guardian_name'")->fetch();
    if ($guardianColumn) {
        $pdo->exec("UPDATE students SET parent_name = guardian_name WHERE parent_name = '' AND guardian_name IS NOT NULL AND guardian_name <> ''");
    }

    $studentTypeColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'student_type'")->fetch();
    if (!$studentTypeColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN student_type VARCHAR(30) NOT NULL DEFAULT 'Normal Student' AFTER class_level");
    }

    $feeSponsorshipColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'fee_sponsorship_status'")->fetch();
    if (!$feeSponsorshipColumn) {
        $pdo->exec("ALTER TABLE students ADD COLUMN fee_sponsorship_status VARCHAR(30) NOT NULL DEFAULT 'Regular Student' AFTER student_type");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_fee_sponsorship_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        academic_year VARCHAR(4) NOT NULL,
        sponsorship_status VARCHAR(30) NOT NULL DEFAULT 'Regular Student',
        changed_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_sponsorship_year (student_id, academic_year),
        KEY idx_sponsorship_year_status (academic_year, sponsorship_status),
        CONSTRAINT fk_student_sponsorship_history_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB");

    $transportStudentsTable = $pdo->query("SHOW TABLES LIKE 'transport_students'")->fetch();
    if ($transportStudentsTable) {
        $transportSchoolNameCol = $pdo->query("SHOW COLUMNS FROM transport_students LIKE 'school_name'")->fetch();
        if (!$transportSchoolNameCol) {
            $pdo->exec("ALTER TABLE transport_students ADD COLUMN school_name VARCHAR(150) NULL AFTER student_name");
        }
    }

    $adminTable = $pdo->query("SHOW TABLES LIKE 'admin'")->fetch();
    if ($adminTable) {
        $adminStatusColumn = $pdo->query("SHOW COLUMNS FROM admin LIKE 'status'")->fetch();
        if (!$adminStatusColumn) {
            $pdo->exec("ALTER TABLE admin ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER password_hash");
        }
        $adminClassLevelColumn = $pdo->query("SHOW COLUMNS FROM admin LIKE 'class_level'")->fetch();
        if (!$adminClassLevelColumn) {
            $pdo->exec("ALTER TABLE admin ADD COLUMN class_level VARCHAR(30) NULL AFTER status");
        }
    }

    // Ensure kitchen schema compatibility for older databases
    $purchaseTypeCol = $pdo->query("SHOW COLUMNS FROM kitchen_inventory LIKE 'purchase_type'")->fetch();
    if (!$purchaseTypeCol) {
        // add purchase_type and category if they don't exist
        try {
            $pdo->exec("ALTER TABLE kitchen_inventory ADD COLUMN purchase_type VARCHAR(30) NOT NULL DEFAULT 'weekly' AFTER supplier");
        } catch (Exception $e) {
            // ignore failures during ALTER
        }
    }
    $categoryCol = $pdo->query("SHOW COLUMNS FROM kitchen_inventory LIKE 'category'")->fetch();
    if (!$categoryCol) {
        try {
            $pdo->exec("ALTER TABLE kitchen_inventory ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'Kitchen' AFTER purchase_type");
        } catch (Exception $e) {
            // ignore failures during ALTER
        }
    }

    // Create new kitchen support tables if they don't exist
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_daily_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            category VARCHAR(80) NOT NULL DEFAULT 'Daily',
            supplier VARCHAR(150) NULL,
            notes TEXT NULL,
            purchase_date DATE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(150) NULL,
            shopping_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            weekly_shopping_id INT UNSIGNED NOT NULL,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_weekly_shopping_items_weekly FOREIGN KEY (weekly_shopping_id) REFERENCES weekly_shopping(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $feeColumns = [
        'mpesa_code' => "ALTER TABLE fees ADD COLUMN mpesa_code VARCHAR(30) NOT NULL DEFAULT '' AFTER amount_paid",
        'mpesa_reference_text' => "ALTER TABLE fees ADD COLUMN mpesa_reference_text TEXT NULL AFTER mpesa_code",
        'term' => "ALTER TABLE fees ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER mpesa_reference_text",
        'year' => "ALTER TABLE fees ADD COLUMN year VARCHAR(4) NOT NULL DEFAULT '' AFTER term",
    ];

    foreach ($feeColumns as $column => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM fees LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_structures (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            academic_year VARCHAR(4) NOT NULL,
            class_level VARCHAR(30) NOT NULL,
            term1_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            term2_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            term3_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fee_structure_year_class (academic_year, class_level)
        ) ENGINE=InnoDB"
    );

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

    $defaultClassLevels = ['Playgroup', 'PP1', 'PP2', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
    $insertClassLevel = $pdo->prepare("INSERT IGNORE INTO class_levels (name) VALUES (:name)");
    foreach ($defaultClassLevels as $level) {
        $insertClassLevel->execute(['name' => $level]);
    }

    $feeBalancesTable = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('fee_balances'))->fetch();
    if (!$feeBalancesTable) {
        $pdo->exec(
            "CREATE TABLE fee_balances (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_id INT UNSIGNED NOT NULL,
                academic_year VARCHAR(4) NOT NULL,
                term VARCHAR(20) NOT NULL,
                original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term),
                CONSTRAINT fk_fee_balances_student_term
                  FOREIGN KEY (student_id) REFERENCES students(id)
                  ON DELETE CASCADE
                  ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    } else {
        $feeBalanceTermColumn = $pdo->query("SHOW COLUMNS FROM fee_balances LIKE 'term'")->fetch();
        if (!$feeBalanceTermColumn) {
            $stagedMigrationTable = $pdo->query("SHOW TABLES LIKE 'fee_balances_term_migration'")->fetch();
            if ($stagedMigrationTable) {
                throw new PDOException('A previous fee-balance migration table exists; it was preserved for manual review.');
            }
            $pdo->exec(
                "CREATE TABLE fee_balances_term_migration (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    student_id INT UNSIGNED NOT NULL,
                    academic_year VARCHAR(4) NOT NULL,
                    term VARCHAR(20) NOT NULL,
                    original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                    discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term),
                    CONSTRAINT fk_fee_balances_student_term_migration
                      FOREIGN KEY (student_id) REFERENCES students(id)
                      ON DELETE CASCADE
                      ON UPDATE CASCADE
                ) ENGINE=InnoDB"
            );
            $pdo->exec(
                "INSERT INTO fee_balances_term_migration
                    (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee, required_amount, paid_amount, balance, created_at)
                 SELECT
                    legacy.student_id,
                    legacy.academic_year,
                    legacy.term,
                    CASE legacy.term
                        WHEN 'Term 1' THEN COALESCE(fee_structures.term1_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        WHEN 'Term 2' THEN COALESCE(fee_structures.term2_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        ELSE COALESCE(fee_structures.term3_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                    END AS original_fee,
                    0.00 AS discount_percentage,
                    CASE legacy.term
                        WHEN 'Term 1' THEN COALESCE(fee_structures.term1_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        WHEN 'Term 2' THEN COALESCE(fee_structures.term2_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        ELSE COALESCE(fee_structures.term3_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                    END AS discounted_fee,
                    CASE legacy.term
                        WHEN 'Term 1' THEN COALESCE(fee_structures.term1_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        WHEN 'Term 2' THEN COALESCE(fee_structures.term2_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                        ELSE COALESCE(fee_structures.term3_total, legacy.balance_amount + COALESCE(payments.paid_amount, 0))
                    END AS required_amount,
                    COALESCE(payments.paid_amount, 0) AS paid_amount,
                    legacy.balance_amount AS balance,
                    legacy.created_at
                 FROM (
                    SELECT student_id, academic_year, 'Term 1' AS term, term1_balance AS balance_amount, created_at FROM fee_balances
                    UNION ALL
                    SELECT student_id, academic_year, 'Term 2' AS term, term2_balance AS balance_amount, created_at FROM fee_balances
                    UNION ALL
                    SELECT student_id, academic_year, 'Term 3' AS term, term3_balance AS balance_amount, created_at FROM fee_balances
                 ) AS legacy
                 JOIN students ON students.id = legacy.student_id
                 LEFT JOIN fee_structures
                   ON fee_structures.academic_year = legacy.academic_year
                  AND fee_structures.class_level = students.class_level
                 LEFT JOIN (
                    SELECT student_id, year AS academic_year, term, COALESCE(SUM(amount_paid), 0) AS paid_amount
                    FROM fees
                    GROUP BY student_id, year, term
                 ) AS payments
                   ON payments.student_id = legacy.student_id
                  AND payments.academic_year = legacy.academic_year
                  AND payments.term = legacy.term"
            );
            $legacyBalanceTable = 'fee_balances_legacy_' . date('Ymd_His');
            $pdo->exec("RENAME TABLE fee_balances TO `{$legacyBalanceTable}`, fee_balances_term_migration TO fee_balances");
        } else {
            $feeBalanceColumns = [
                'original_fee' => "ALTER TABLE fee_balances ADD COLUMN original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER term",
                'discount_percentage' => "ALTER TABLE fee_balances ADD COLUMN discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER original_fee",
                'discounted_fee' => "ALTER TABLE fee_balances ADD COLUMN discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_percentage",
                'required_amount' => "ALTER TABLE fee_balances ADD COLUMN required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER term",
                'paid_amount' => "ALTER TABLE fee_balances ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER required_amount",
                'balance' => "ALTER TABLE fee_balances ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount",
                'updated_at' => "ALTER TABLE fee_balances ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
            ];
            foreach ($feeBalanceColumns as $column => $sql) {
                $exists = $pdo->query("SHOW COLUMNS FROM fee_balances LIKE " . $pdo->quote($column))->fetch();
                if (!$exists) {
                    $pdo->exec($sql);
                }
            }

            $pdo->exec(
                "UPDATE fee_balances
                 SET original_fee = required_amount
                 WHERE original_fee = 0.00"
            );
            $pdo->exec(
                "UPDATE fee_balances
                 SET discounted_fee = required_amount
                 WHERE discounted_fee = 0.00"
            );

            $termIndex = $pdo->query("SHOW INDEX FROM fee_balances WHERE Key_name = 'uniq_fee_balance_student_year_term'")->fetch();
            if (!$termIndex) {
                $duplicateBalance = $pdo->query(
                    "SELECT 1 FROM fee_balances
                     GROUP BY student_id, academic_year, term
                     HAVING COUNT(*) > 1 LIMIT 1"
                )->fetchColumn();
                if (!$duplicateBalance) {
                    $pdo->exec("ALTER TABLE fee_balances ADD UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term)");
                } else {
                    error_log('Fee balance uniqueness index was not added because duplicate period rows were preserved for review.');
                }
            }

            $yearOnlyIndex = $pdo->query("SHOW INDEX FROM fee_balances WHERE Key_name = 'uniq_fee_balance_student_year'")->fetch();
            if ($yearOnlyIndex) {
                $pdo->exec("ALTER TABLE fee_balances DROP INDEX uniq_fee_balance_student_year");
            }
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_fee_discounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_fee_discount_term (student_id, academic_year, term),
            CONSTRAINT fk_student_fee_discounts_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_calendar (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            academic_year VARCHAR(4) NOT NULL,
            term_name VARCHAR(20) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_settings (
            setting_key VARCHAR(60) PRIMARY KEY,
            setting_value VARCHAR(100) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $calendarIndex = $pdo->query("SHOW INDEX FROM academic_calendar WHERE Key_name = 'uniq_academic_calendar_year_term'")->fetch();
    if (!$calendarIndex) {
        $duplicateCalendar = $pdo->query(
            "SELECT 1 FROM academic_calendar
             GROUP BY academic_year, term_name
             HAVING COUNT(*) > 1 LIMIT 1"
        )->fetchColumn();
        if (!$duplicateCalendar) {
            $pdo->exec("ALTER TABLE academic_calendar ADD UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)");
        } else {
            error_log('Academic calendar uniqueness index was not added because duplicate period rows were preserved for review.');
        }
    }

    $calendarYear = date('Y');
    $calendarRows = [
        ['Term 1', "{$calendarYear}-01-01", "{$calendarYear}-04-30"],
        ['Term 2', "{$calendarYear}-05-01", "{$calendarYear}-08-31"],
        ['Term 3', "{$calendarYear}-09-01", "{$calendarYear}-12-31"],
    ];
    $calendarInsert = $pdo->prepare(
        "INSERT IGNORE INTO academic_calendar (academic_year, term_name, start_date, end_date)
         VALUES (:academic_year, :term_name, :start_date, :end_date)"
    );
    $calendarYearRows = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_calendar
         WHERE academic_year = :academic_year"
    );
    $calendarYearRows->execute(['academic_year' => $calendarYear]);
    if ((int) $calendarYearRows->fetchColumn() === 0) {
        foreach ($calendarRows as $calendarRow) {
            $calendarInsert->execute([
                'academic_year' => $calendarYear,
                'term_name' => $calendarRow[0],
                'start_date' => $calendarRow[1],
                'end_date' => $calendarRow[2],
            ]);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feeding_subscriptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            feeding_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_feeding_student_year_term (student_id, academic_year, term),
            CONSTRAINT fk_feeding_subscriptions_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feeding_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feeding_subscription_id INT UNSIGNED NOT NULL,
            amount_paid DECIMAL(12,2) NOT NULL,
            payment_date DATE NOT NULL,
            reference_no VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_feeding_payments_subscription
              FOREIGN KEY (feeding_subscription_id) REFERENCES feeding_subscriptions(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_inventory (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NOT NULL DEFAULT 'kg',
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            item_date DATE NOT NULL,
            supplier VARCHAR(150) NULL,
            purchase_type VARCHAR(30) NOT NULL DEFAULT 'weekly',
            category VARCHAR(80) NOT NULL DEFAULT 'Kitchen',
            academic_year VARCHAR(4) NOT NULL DEFAULT '',
            term VARCHAR(20) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_daily_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            category VARCHAR(80) NOT NULL DEFAULT 'Daily',
            supplier VARCHAR(150) NULL,
            notes TEXT NULL,
            purchase_date DATE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(150) NULL,
            shopping_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            weekly_shopping_id INT UNSIGNED NOT NULL,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_weekly_shopping_items_weekly FOREIGN KEY (weekly_shopping_id) REFERENCES weekly_shopping(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS school_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            category VARCHAR(40) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            unit VARCHAR(30) NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expense_date DATE NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    // School Uniform tables
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS uniforms (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uniform_name VARCHAR(150) NOT NULL,
            category VARCHAR(60) NOT NULL,
            gender VARCHAR(10) NOT NULL,
            size VARCHAR(30) NOT NULL,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            opening_stock INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS uniform_variants (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uniform_id INT UNSIGNED NOT NULL,
            size VARCHAR(30) NOT NULL,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            opening_stock INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_uniform_variant_size (uniform_id, size),
            KEY idx_uniform_variants_status (status),
            CONSTRAINT fk_uniform_variants_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS uniform_stock_movements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uniform_id INT UNSIGNED NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            quantity INT NOT NULL,
            uniform_variant_id INT UNSIGNED NULL,
            reference_id INT UNSIGNED NULL,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_uniform_stock_movements_variant (uniform_variant_id),
            CONSTRAINT fk_uniform_stock_movements_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS uniform_sales (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NULL,
            receipt_no VARCHAR(80) NOT NULL UNIQUE,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
            mpesa_code VARCHAR(30) NOT NULL DEFAULT '',
            served_by INT UNSIGNED NULL,
            payment_date DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_uniform_sales_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS uniform_sale_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id INT UNSIGNED NOT NULL,
            uniform_id INT UNSIGNED NOT NULL,
            uniform_variant_id INT UNSIGNED NULL,
            size VARCHAR(30) NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_uniform_sale_items_variant (uniform_variant_id),
            CONSTRAINT fk_uniform_sale_items_sale FOREIGN KEY (sale_id) REFERENCES uniform_sales(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_uniform_sale_items_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    // Upgrade existing one-size catalogs without removing their sales or stock history.
    foreach ([
        'uniform_stock_movements' => 'uniform_variant_id',
        'uniform_sale_items' => 'uniform_variant_id',
    ] as $uniformTable => $variantColumn) {
        $column = $pdo->query("SHOW COLUMNS FROM `{$uniformTable}` LIKE '{$variantColumn}'")->fetch();
        if (!$column) {
            $pdo->exec("ALTER TABLE `{$uniformTable}` ADD COLUMN `{$variantColumn}` INT UNSIGNED NULL");
        }
        $index = $pdo->query("SHOW INDEX FROM `{$uniformTable}` WHERE Key_name = 'idx_{$uniformTable}_variant'")->fetch();
        if (!$index) {
            $pdo->exec("ALTER TABLE `{$uniformTable}` ADD INDEX `idx_{$uniformTable}_variant` (`{$variantColumn}`)");
        }
    }

    $pdo->exec(
        "INSERT INTO uniform_variants (uniform_id, size, selling_price, opening_stock, reorder_level, status)
         SELECT u.id, u.size, u.selling_price, u.opening_stock, u.reorder_level, u.status
         FROM uniforms u
         LEFT JOIN uniform_variants uv ON uv.uniform_id = u.id AND uv.size = u.size
         WHERE uv.id IS NULL"
    );
    $pdo->exec(
        "UPDATE uniform_sale_items si
         INNER JOIN uniform_variants uv ON uv.uniform_id = si.uniform_id AND uv.size = si.size
         SET si.uniform_variant_id = uv.id
         WHERE si.uniform_variant_id IS NULL"
    );
    $pdo->exec(
        "UPDATE uniform_stock_movements sm
         INNER JOIN uniforms u ON u.id = sm.uniform_id
         INNER JOIN uniform_variants uv ON uv.uniform_id = u.id AND uv.size = u.size
         SET sm.uniform_variant_id = uv.id
         WHERE sm.uniform_variant_id IS NULL"
    );

    foreach ([
        ['table' => 'uniform_stock_movements', 'constraint' => 'fk_uniform_stock_movements_variant'],
        ['table' => 'uniform_sale_items', 'constraint' => 'fk_uniform_sale_items_variant'],
    ] as $variantForeignKey) {
        $constraint = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name LIMIT 1'
        );
        $constraint->execute(['table_name' => $variantForeignKey['table'], 'constraint_name' => $variantForeignKey['constraint']]);
        if (!$constraint->fetchColumn()) {
            $pdo->exec(
                "ALTER TABLE `{$variantForeignKey['table']}` ADD CONSTRAINT `{$variantForeignKey['constraint']}` FOREIGN KEY (uniform_variant_id) REFERENCES uniform_variants(id) ON DELETE SET NULL ON UPDATE CASCADE"
            );
        }
    }

    // School Van Fuel tables
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vehicles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_name VARCHAR(100) NOT NULL,
            registration_no VARCHAR(60) NULL,
            driver_name VARCHAR(120) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fuel_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT UNSIGNED NOT NULL,
            fuel_date DATE NOT NULL,
            fuel_type VARCHAR(60) NOT NULL,
            litres DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cost_per_litre DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            fuel_station VARCHAR(150) NULL,
            receipt_no VARCHAR(80) NULL,
            notes TEXT NULL,
            recorded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_fuel_transactions_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_students (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NULL,
            student_name VARCHAR(150) NOT NULL,
            school_name VARCHAR(150) NULL,
            gender VARCHAR(10) NOT NULL,
            parent_name VARCHAR(150) NOT NULL,
            parent_phone VARCHAR(40) NULL,
            pickup_location VARCHAR(150) NOT NULL,
            is_outside TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_transport_existing_student (student_id),
            CONSTRAINT fk_transport_students_student
              FOREIGN KEY (student_id) REFERENCES students(id)
              ON DELETE SET NULL
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $existingTransportStudentSchoolName = $pdo->query("SHOW COLUMNS FROM transport_students LIKE 'school_name'")->fetch();
    if (!$existingTransportStudentSchoolName) {
        $pdo->exec("ALTER TABLE transport_students ADD COLUMN school_name VARCHAR(150) NULL AFTER student_name");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_fee_structures (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            location_name VARCHAR(150) NOT NULL,
            fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            academic_year VARCHAR(4) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_transport_fee_location (location_name, academic_year)
        ) ENGINE=InnoDB"
    );

    $transportFeeYearColumn = $pdo->query("SHOW COLUMNS FROM transport_fee_structures LIKE 'academic_year'")->fetch();
    if (!$transportFeeYearColumn) {
        $defaultYear = date('Y');
        $pdo->exec("ALTER TABLE transport_fee_structures ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '{$defaultYear}' AFTER fee_amount");
        $transportFeeIndex = $pdo->query("SHOW INDEX FROM transport_fee_structures WHERE Key_name = 'uniq_transport_fee_location'")->fetch();
        if ($transportFeeIndex) {
            $pdo->exec("ALTER TABLE transport_fee_structures DROP INDEX uniq_transport_fee_location");
        }
        $pdo->exec("ALTER TABLE transport_fee_structures ADD UNIQUE KEY uniq_transport_fee_location (location_name, academic_year)");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transport_student_id INT UNSIGNED NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_transport_student_year_term (transport_student_id, academic_year, term),
            CONSTRAINT fk_transport_accounts_student
              FOREIGN KEY (transport_student_id) REFERENCES transport_students(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transport_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transport_account_id INT UNSIGNED NOT NULL,
            amount_paid DECIMAL(12,2) NOT NULL,
            payment_date DATE NOT NULL,
            reference_no VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_transport_payments_account
              FOREIGN KEY (transport_account_id) REFERENCES transport_accounts(id)
              ON DELETE CASCADE
              ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS permissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            label VARCHAR(150) NOT NULL,
            module VARCHAR(60) NOT NULL,
            action VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, permission_id),
            CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED,
            action VARCHAR(100) NOT NULL,
            resource VARCHAR(200),
            granted TINYINT(1) NOT NULL DEFAULT 0,
            level ENUM('INFO', 'WARN', 'DENY', 'ERROR') NOT NULL DEFAULT 'INFO',
            details JSON,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_action (user_id, action),
            INDEX idx_created_at (created_at),
            INDEX idx_resource (resource),
            CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );
} catch (PDOException $exception) {
    error_log('Database schema check failed with SQLSTATE ' . (string) $exception->getCode() . ': ' . $exception->getMessage());
    http_response_code(500);
    echo '<h1>Database schema needs attention</h1>';
    echo '<p>Saved records were preserved. Contact the administrator to complete the documented database update.</p>';
    exit;
}
