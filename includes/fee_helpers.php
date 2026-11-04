<?php

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/registration_number_helpers.php';
require_once __DIR__ . '/fee_receipt_helpers.php';
require_once __DIR__ . '/fee_correction_helpers.php';
require_once __DIR__ . '/student_class_helpers.php';
require_once __DIR__ . '/payment_transactions.php';

function class_level_options(): array
{
    global $pdo;

    $defaultClassLevels = ['Playgroup', 'PP1', 'PP2', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];

    if (!isset($pdo) || !$pdo instanceof PDO) {
        return $defaultClassLevels;
    }

    try {
        $statement = $pdo->query("SELECT name FROM class_levels WHERE status = 'Active' ORDER BY name ASC");
        $levels = array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name');
        return $levels !== [] ? $levels : $defaultClassLevels;
    } catch (Throwable $exception) {
        return $defaultClassLevels;
    }
}

function fetch_class_levels(PDO $pdo): array
{
    $statement = $pdo->query("SELECT id, name, status FROM class_levels ORDER BY name ASC");
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function get_class_level_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT id, name, status FROM class_levels WHERE id = :id LIMIT 1");
    $statement->execute(['id' => $id]);
    $classLevel = $statement->fetch();
    return $classLevel ?: null;
}

function class_level_exists(PDO $pdo, string $name, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*) FROM class_levels WHERE LOWER(name) = LOWER(:name)";
    $params = ['name' => $name];
    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn() > 0;
}

function save_class_level(PDO $pdo, string $name, int $id = 0): int
{
    if ($id > 0) {
        $statement = $pdo->prepare("UPDATE class_levels SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute(['name' => $name, 'id' => $id]);
        return $id;
    }

    $statement = $pdo->prepare("INSERT INTO class_levels (name, status) VALUES (:name, 'Active')");
    $statement->execute(['name' => $name]);
    return (int) $pdo->lastInsertId();
}

function is_class_level_in_use(PDO $pdo, string $name): bool
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_level = :name");
    $statement->execute(['name' => $name]);
    if ((int) $statement->fetchColumn() > 0) {
        return true;
    }

    $statement = $pdo->prepare("SELECT COUNT(*) FROM fee_structures WHERE class_level = :name");
    $statement->execute(['name' => $name]);
    return (int) $statement->fetchColumn() > 0;
}

function delete_class_level(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare("DELETE FROM class_levels WHERE id = :id");
    return $statement->execute(['id' => $id]);
}

function normalize_payment_code(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', '', $value)));
}

function payment_code_duplicate_exists(PDO $pdo, string $value, string $context): bool
{
    $value = normalize_payment_code($value);
    if ($value === '') {
        return false;
    }

    $contexts = [
        'transport_reference' => ['table' => 'transport_payments', 'column' => 'reference_no'],
        'feeding_reference' => ['table' => 'feeding_payments', 'column' => 'reference_no'],
        'fee_mpesa_code' => ['table' => 'fees', 'column' => 'mpesa_code'],
        'uniform_mpesa_code' => ['table' => 'uniform_sales', 'column' => 'mpesa_code'],
        'activity_reference' => ['table' => 'activity_payments', 'column' => 'reference_no'],
        'daycare_reference' => ['table' => 'daycare_payments', 'column' => 'payment_reference'],
    ];

    if (!isset($contexts[$context])) {
        throw new InvalidArgumentException('Invalid payment duplicate check context.');
    }

    $tables = [
        ['payment_transactions', 'mpesa_code'],
        ['fees', 'mpesa_code'],
        ['activity_payments', 'reference_no'],
        ['daycare_payments', 'payment_reference'],
        ['uniform_sales', 'mpesa_code'],
        ['transport_payments', 'reference_no'],
        ['feeding_payments', 'reference_no'],
    ];
    foreach ($tables as [$table, $column]) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:table");
            $exists->execute(['table' => $table]);
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
            $exists->execute(['table' => $table]);
        }
        if (!$exists->fetchColumn()) continue;
        $statement = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE UPPER(REPLACE(TRIM(`{$column}`),' ','')) = :value LIMIT 1");
        $statement->execute(['value' => $value]);
        if ($statement->fetchColumn()) return true;
    }
    return false;
}

function transport_fee_amount_for_location(PDO $pdo, string $location, ?string $academicYear = null): ?float
{
    $location = trim($location);
    if ($location === '') {
        return null;
    }

    if ($academicYear === null || !preg_match('/^\d{4}$/', trim($academicYear))) {
        $context = current_academic_context($pdo);
        $academicYear = $context['academic_year'];
    }
    $statement = $pdo->prepare(
        "SELECT fee_amount
         FROM transport_fee_structures
         WHERE location_name = :location_name
           AND academic_year = :academic_year
           AND status = 'Active'
         LIMIT 1"
    );
    $statement->execute([
        'location_name' => $location,
        'academic_year' => $academicYear,
    ]);

    $fee = $statement->fetchColumn();
    return $fee === false ? null : (float) $fee;
}

// Kitchen inventory helpers
function ensure_kitchen_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_expense_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(40) NOT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_kitchen_expense_category_name (category_name),
            CONSTRAINT fk_kitchen_expense_category_creator FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT 'kg',
            category VARCHAR(60) NOT NULL DEFAULT 'Kitchen',
            opening_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            min_stock_level DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    // ensure a normalized name column and unique constraint to prevent case/space duplicates
    if (!db_has_column($pdo, 'kitchen_items', 'item_name_norm')) {
        $pdo->exec("ALTER TABLE kitchen_items ADD COLUMN item_name_norm VARCHAR(120) GENERATED ALWAYS AS (LOWER(TRIM(item_name))) STORED AFTER item_name");
    }
    // create unique index on normalized name if it doesn't exist
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_kitchen_items_item_name_norm ON kitchen_items(item_name_norm)");
    } catch (Exception $e) {
        // some MySQL versions don't support IF NOT EXISTS for CREATE INDEX; ignore duplicate index errors
    }

    if (!db_has_column($pdo, 'kitchen_items', 'status')) {
        $pdo->exec("ALTER TABLE kitchen_items ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER updated_at");
    }

    if (!db_has_column($pdo, 'kitchen_items', 'unit')) {
        $pdo->exec("ALTER TABLE kitchen_items ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'kg' AFTER item_name");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_stock_movements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kitchen_item_id INT UNSIGNED NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reference_id INT UNSIGNED NULL,
            note TEXT NULL,
            recorded_by INT UNSIGNED NULL,
            transaction_date DATE NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_kitchen_stock_movements_item FOREIGN KEY (kitchen_item_id) REFERENCES kitchen_items(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'unit')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN unit VARCHAR(30) NULL AFTER quantity");
    }
    $pdo->exec("UPDATE kitchen_stock_movements ksm JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id SET ksm.unit = ki.unit WHERE ksm.unit IS NULL OR ksm.unit = ''");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_daily_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            category VARCHAR(80) NOT NULL DEFAULT 'Daily',
            supplier VARCHAR(150) NULL,
            notes TEXT NULL,
            purchase_date DATE NOT NULL,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'quantity')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER item_name");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'unit')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN unit VARCHAR(30) NULL AFTER quantity");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'academic_year')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER purchase_date");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'term')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
    }
    if (!db_has_column($pdo, 'kitchen_daily_purchases', 'payment_method')) {
        $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN payment_method VARCHAR(80) NULL AFTER notes");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_shopping (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(150) NULL,
            shopping_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
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
        "CREATE TABLE IF NOT EXISTS kitchen_purchase_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_token CHAR(64) NOT NULL,
            item_name VARCHAR(120) NOT NULL,
            category VARCHAR(40) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            purchase_date DATE NOT NULL,
            supplier VARCHAR(150) NULL,
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            academic_year VARCHAR(4) NOT NULL,
            term VARCHAR(20) NOT NULL,
            school_expense_id INT UNSIGNED NULL,
            recorded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_kitchen_purchase_request (request_token),
            UNIQUE KEY ux_kitchen_purchase_expense (school_expense_id),
            KEY ix_kitchen_purchase_date (purchase_date, category)
        ) ENGINE=InnoDB"
    );
    if (!db_has_column($pdo, 'kitchen_purchase_records', 'description')) {
        $pdo->exec("ALTER TABLE kitchen_purchase_records ADD COLUMN description TEXT NULL AFTER supplier");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS kitchen_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(30) NOT NULL,
            record_id INT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            before_value DECIMAL(12,2) NULL,
            after_value DECIMAL(12,2) NULL,
            details TEXT NULL,
            recorded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_kitchen_audit_record (record_type, record_id, created_at),
            KEY ix_kitchen_audit_user (recorded_by, created_at)
        ) ENGINE=InnoDB"
    );
    if (!db_has_column($pdo, 'weekly_shopping_items', 'unit')) {
        $pdo->exec("ALTER TABLE weekly_shopping_items ADD COLUMN unit VARCHAR(30) NULL AFTER quantity");
    }
    if (!db_has_column($pdo, 'weekly_shopping_items', 'supplier')) {
        $pdo->exec("ALTER TABLE weekly_shopping_items ADD COLUMN supplier VARCHAR(150) NULL AFTER unit_price");
    }
    if (!db_has_column($pdo, 'weekly_shopping', 'academic_year')) {
        $pdo->exec("ALTER TABLE weekly_shopping ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER total_amount");
    }
    if (!db_has_column($pdo, 'weekly_shopping', 'term')) {
        $pdo->exec("ALTER TABLE weekly_shopping ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
    }
    $pdo->exec("UPDATE kitchen_daily_purchases p JOIN kitchen_items ki ON LOWER(TRIM(p.item_name)) = LOWER(TRIM(ki.item_name)) SET p.unit = ki.unit WHERE p.unit IS NULL OR p.unit = ''");
    $pdo->exec("UPDATE weekly_shopping_items p JOIN kitchen_items ki ON LOWER(TRIM(p.item_name)) = LOWER(TRIM(ki.item_name)) SET p.unit = ki.unit WHERE p.unit IS NULL OR p.unit = ''");
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'transaction_date')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN transaction_date DATE NOT NULL DEFAULT CURRENT_DATE AFTER recorded_by");
    }
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'academic_year')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER transaction_date");
    }
    if (!db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
        $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
    }
    if (db_has_column($pdo, 'school_expenses', 'quantity') && !db_has_column($pdo, 'school_expenses', 'unit')) {
        $pdo->exec("ALTER TABLE school_expenses ADD COLUMN unit VARCHAR(30) NULL AFTER quantity");
    }
    if (db_has_column($pdo, 'school_expenses', 'unit')) {
        $pdo->exec("UPDATE school_expenses e JOIN kitchen_items ki ON LOWER(TRIM(e.item_name)) = LOWER(TRIM(ki.item_name)) SET e.unit = ki.unit WHERE e.unit IS NULL AND e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE')");
    }
}

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function kitchen_unit_options(): array
{
    return [
        'kg' => 'Kilograms (kg)',
        'g' => 'Grams (g)',
        'mg' => 'Milligrams (mg)',
        'L' => 'Litres (L)',
        'ml' => 'Millilitres (ml)',
        'pcs' => 'Pieces',
        'packets' => 'Packets',
        'bags' => 'Bags',
        'cartons' => 'Cartons',
        'loaves' => 'Loaves',
        'crates' => 'Crates',
        'bottles' => 'Bottles',
        'tins' => 'Tins',
        'cans' => 'Cans',
        'trays' => 'Trays',
        'bunches' => 'Bunches',
        'bundles' => 'Bundles',
        'boxes' => 'Boxes',
        'rolls' => 'Rolls',
        'sachets' => 'Sachets',
        'cups' => 'Cups',
        'buckets' => 'Buckets',
        'dozen' => 'Dozen',
        'pairs' => 'Pairs',
        'bars' => 'Bars',
    ];
}

function kitchen_available_unit_options(PDO $pdo): array
{
    $options = kitchen_unit_options();
    try {
        $stmt = $pdo->query("SELECT code, label FROM kitchen_units ORDER BY id ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $options[$code] = trim((string) ($row['label'] ?? '')) ?: $code;
            }
        }
    } catch (Throwable $e) {
        // Custom units are optional on older installations.
    }
    foreach (['kitchen_items', 'kitchen_inventory', 'kitchen_daily_purchases', 'weekly_shopping_items', 'school_expenses'] as $table) {
        if (!db_has_column($pdo, $table, 'unit')) {
            continue;
        }
        $stmt = $pdo->query("SELECT DISTINCT unit FROM `{$table}` WHERE unit IS NOT NULL AND TRIM(unit) <> ''");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $unit) {
            $unit = trim((string) $unit);
            if ($unit !== '' && !array_key_exists($unit, $options)) {
                $options[$unit] = $unit;
            }
        }
    }
    return $options;
}

function kitchen_quantity_summary(array $rows, string $quantityKey = 'qty_used'): string
{
    $totals = [];
    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? '')) ?: 'unit';
        $totals[$unit] = ($totals[$unit] ?? 0.0) + (float) ($row[$quantityKey] ?? 0);
    }
    $parts = [];
    foreach ($totals as $unit => $quantity) {
        $parts[] = number_format($quantity, 2) . ' ' . $unit;
    }
    return $parts ? implode(', ', $parts) : '0';
}

function kitchen_validate_unit(string $unit): string
{
    $unit = trim($unit);
    return $unit !== '' ? $unit : 'kg';
}

function kitchen_get_or_create_item(PDO $pdo, string $itemName, string $category = 'Kitchen', float $opening = 0.0, float $minLevel = 0.0, string $unit = ''): array
{
    ensure_kitchen_tables($pdo);
    $unit = trim($unit);
    $name = trim($itemName);
    $norm = mb_strtolower($name);
    // prefer normalized lookup to avoid case/space duplicates
    $stmt = $pdo->prepare("SELECT * FROM kitchen_items WHERE (item_name_norm = :norm OR item_name = :item_name) LIMIT 1");
    $stmt->execute(['norm' => $norm, 'item_name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        $updates = [];
        $params = ['id' => $row['id']];

        if ($category && $row['category'] !== $category) {
            $updates[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($opening > 0.0 && (float) $row['opening_stock'] !== $opening) {
            $updates[] = 'opening_stock = :opening_stock';
            $params['opening_stock'] = $opening;
        }
        if ($minLevel > 0.0 && (float) $row['min_stock_level'] !== $minLevel) {
            $updates[] = 'min_stock_level = :min_stock_level';
            $params['min_stock_level'] = $minLevel;
        }
        if ($unit !== '' && (!isset($row['unit']) || $row['unit'] !== $unit)) {
            $updates[] = 'unit = :unit';
            $params['unit'] = $unit;
        }

        if ($updates) {
            $updateSql = "UPDATE kitchen_items SET " . implode(', ', $updates) . " WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);
            $stmt->execute(['norm' => $norm, 'item_name' => $name]);
            $row = $stmt->fetch();
        }

        return $row;
    }

    // insert normalized name trimmed
    $ins = $pdo->prepare(
        "INSERT INTO kitchen_items (item_name, unit, category, opening_stock, min_stock_level) VALUES (:item_name, :unit, :category, :opening_stock, :min_stock_level)"
    );
    $ins->execute([
        'item_name' => $name,
        'unit' => $unit !== '' ? $unit : 'kg',
        'category' => $category,
        'opening_stock' => $opening,
        'min_stock_level' => $minLevel,
    ]);

    // re-query and return
    $stmt->execute(['norm' => $norm, 'item_name' => $name]);
    return $stmt->fetch();
}

function kitchen_transaction_context(PDO $pdo, ?string $transactionDate = null): array
{
    $transactionDate = $transactionDate ?: date('Y-m-d');
    $context = current_academic_context($pdo, $transactionDate);

    return [
        'transaction_date' => $transactionDate,
        'academic_year' => $context['academic_year'],
        'term' => $context['term'],
    ];
}

function kitchen_current_access_level(PDO $pdo): string
{
    return rbac_user_kitchen_access_level($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function kitchen_require_access(PDO $pdo, bool $manage = false): void
{
    $canAccess = $manage
        ? rbac_current_admin_has_permission($pdo, 'kitchen.entry.add')
        : (
            rbac_current_admin_has_permission($pdo, 'kitchen.view')
            || rbac_current_admin_has_permission($pdo, 'kitchen.expenditure.view')
            || rbac_current_admin_has_permission($pdo, 'kitchen.export')
            || rbac_current_admin_has_permission($pdo, 'kitchen.entry.add')
        );
    if (!$canAccess) {
        rbac_deny($pdo, $manage
            ? 'You do not have permission to manage kitchen expenses.'
            : 'You do not have access to Kitchen records.');
    }
}

function kitchen_expense_total(PDO $pdo, string $from, string $to): float
{
    if (!valid_date_value($from) || !valid_date_value($to) || $from > $to) {
        return 0.0;
    }
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0)
         FROM school_expenses
         WHERE expense_date BETWEEN :date_from AND :date_to
           AND (category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill') OR category LIKE 'Kitchen %')"
    );
    $stmt->execute(['date_from' => $from, 'date_to' => $to]);
    return (float) $stmt->fetchColumn();
}

function kitchen_expense_filter(PDO $pdo, array $filters): array
{
    $where = ["(e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill') OR e.category LIKE 'Kitchen %')"];
    $params = [];
    $year = preg_match('/^\\d{4}$/', (string) ($filters['year'] ?? '')) ? (string) $filters['year'] : '';
    $term = in_array((string) ($filters['term'] ?? ''), term_options(), true) ? (string) $filters['term'] : '';
    $hasExpensePeriod = db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term');

    if ($year !== '' && $term !== '') {
        $calendar = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
        $calendar->execute(['year' => $year, 'term' => $term]);
        $dates = $calendar->fetch(PDO::FETCH_ASSOC);
        $periodMatch = '(kp.academic_year = :kitchen_year AND kp.term = :kitchen_term)';
        $params['kitchen_year'] = $year;
        $params['kitchen_term'] = $term;
        if ($hasExpensePeriod) {
            $periodMatch .= " OR (kp.id IS NULL AND NULLIF(e.academic_year, '') = :expense_year AND e.term = :expense_term)";
            $params['expense_year'] = $year;
            $params['expense_term'] = $term;
        }
        if ($dates) {
            $periodMatch .= ' OR (kp.id IS NULL AND ' . ($hasExpensePeriod ? "(NULLIF(e.academic_year, '') IS NULL OR e.term = '') AND " : '') . 'e.expense_date BETWEEN :legacy_start AND :legacy_end)';
            $params['legacy_start'] = $dates['start_date'];
            $params['legacy_end'] = $dates['end_date'];
        }
        $where[] = '(' . $periodMatch . ')';
    } elseif ($year !== '') {
        $periodMatch = 'kp.academic_year = :kitchen_year';
        $params['kitchen_year'] = $year;
        if ($hasExpensePeriod) {
            $periodMatch .= " OR (kp.id IS NULL AND NULLIF(e.academic_year, '') = :expense_year)";
            $params['expense_year'] = $year;
        }
        $periodMatch .= ' OR (kp.id IS NULL AND ' . ($hasExpensePeriod ? "NULLIF(e.academic_year, '') IS NULL AND " : '') . 'YEAR(e.expense_date) = :legacy_year)';
        $params['legacy_year'] = $year;
        $where[] = '(' . $periodMatch . ')';
    }

    if (!empty($filters['date_from']) && valid_date_value((string) $filters['date_from'])) {
        $where[] = 'e.expense_date >= :kitchen_date_from';
        $params['kitchen_date_from'] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to']) && valid_date_value((string) $filters['date_to'])) {
        $where[] = 'e.expense_date <= :kitchen_date_to';
        $params['kitchen_date_to'] = (string) $filters['date_to'];
    }

    return [' WHERE ' . implode(' AND ', $where), $params];
}

function kitchen_write_audit(PDO $pdo, string $recordType, int $recordId, string $action, ?float $before, ?float $after, array $details, ?int $userId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO kitchen_audit_log (record_type, record_id, action, before_value, after_value, details, recorded_by)
         VALUES (:record_type, :record_id, :action, :before_value, :after_value, :details, :recorded_by)"
    );
    $stmt->execute([
        'record_type' => $recordType,
        'record_id' => $recordId,
        'action' => $action,
        'before_value' => $before,
        'after_value' => $after,
        'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'recorded_by' => $userId,
    ]);
}

function kitchen_record_purchase(PDO $pdo, array $purchase, int $recordedBy): array
{
    ensure_kitchen_tables($pdo);
    $requestToken = strtolower(trim((string) ($purchase['request_token'] ?? '')));
    $itemName = trim((string) ($purchase['item_name'] ?? ''));
    $category = trim((string) ($purchase['category'] ?? ''));
    $quantity = (float) ($purchase['quantity'] ?? 0);
    $unit = trim((string) ($purchase['unit'] ?? ''));
    $unitPrice = (float) ($purchase['unit_price'] ?? 0);
    $date = trim((string) ($purchase['purchase_date'] ?? ''));
    $supplier = trim((string) ($purchase['supplier'] ?? ''));
    $trackStock = !empty($purchase['track_stock']);
    $categories = ['Food', 'Gas', 'Cooking Supplies', 'Other'];

    if (!preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
        throw new InvalidArgumentException('Refresh the form and try recording the purchase again.');
    }
    if ($itemName === '' || strlen($itemName) > 120 || !in_array($category, $categories, true)) {
        throw new InvalidArgumentException('Enter an item name and choose a valid kitchen category.');
    }
    if ($quantity <= 0 || $unitPrice <= 0 || !array_key_exists($unit, kitchen_available_unit_options($pdo))) {
        throw new InvalidArgumentException('Enter a valid quantity, unit, and unit price.');
    }
    if (!valid_date_value($date) || strlen($supplier) > 150) {
        throw new InvalidArgumentException('Enter a valid purchase date and supplier.');
    }

    $total = round($quantity * $unitPrice, 2);
    $context = kitchen_transaction_context($pdo, $date);
    $existing = $pdo->prepare("SELECT id FROM kitchen_purchase_records WHERE request_token = :token LIMIT 1");
    $existing->execute(['token' => $requestToken]);
    $existingId = $existing->fetchColumn();
    if ($existingId !== false) {
        return ['id' => (int) $existingId, 'created' => false];
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            "INSERT INTO kitchen_purchase_records
                (request_token, item_name, category, quantity, unit, unit_price, total_amount, purchase_date, supplier, track_stock, academic_year, term, recorded_by)
             VALUES
                (:request_token, :item_name, :category, :quantity, :unit, :unit_price, :total_amount, :purchase_date, :supplier, :track_stock, :academic_year, :term, :recorded_by)"
        );
        $insert->execute([
            'request_token' => $requestToken,
            'item_name' => $itemName,
            'category' => $category,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total_amount' => $total,
            'purchase_date' => $date,
            'supplier' => $supplier !== '' ? $supplier : null,
            'track_stock' => $trackStock ? 1 : 0,
            'academic_year' => $context['academic_year'],
            'term' => $context['term'],
            'recorded_by' => $recordedBy,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();

        $expenseColumns = 'item_name, category, amount, quantity, unit, total_amount, expense_date, description';
        $expenseValues = ':item_name, :category, :amount, :quantity, :unit, :total_amount, :expense_date, :description';
        $expenseParams = [
            'item_name' => $itemName,
            'category' => 'Kitchen',
            'amount' => $unitPrice,
            'quantity' => $quantity,
            'unit' => $unit,
            'total_amount' => $total,
            'expense_date' => $date,
            'description' => 'Kitchen category: ' . $category . ' | Purchase #' . $purchaseId
                . ($supplier !== '' ? ' | Supplier: ' . $supplier : '')
                . ' | Stock tracked: ' . ($trackStock ? 'Yes' : 'No'),
        ];
        if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
            $expenseColumns .= ', academic_year, term';
            $expenseValues .= ', :academic_year, :term';
            $expenseParams['academic_year'] = $context['academic_year'];
            $expenseParams['term'] = $context['term'];
        }
        $expense = $pdo->prepare("INSERT INTO school_expenses ({$expenseColumns}) VALUES ({$expenseValues})");
        $expense->execute($expenseParams);
        $expenseId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE kitchen_purchase_records SET school_expense_id = :expense_id WHERE id = :id")
            ->execute(['expense_id' => $expenseId, 'id' => $purchaseId]);

        if ($trackStock) {
            $norm = mb_strtolower($itemName);
            $itemStmt = $pdo->prepare("SELECT * FROM kitchen_items WHERE item_name_norm = :norm OR item_name = :name LIMIT 1 FOR UPDATE");
            $itemStmt->execute(['norm' => $norm, 'name' => $itemName]);
            $kitchenItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if ($kitchenItem && (string) $kitchenItem['unit'] !== $unit) {
                throw new InvalidArgumentException('This item is already tracked in ' . $kitchenItem['unit'] . '. Use the same unit for its stock records.');
            }
            if (!$kitchenItem) {
                $createItem = $pdo->prepare("INSERT INTO kitchen_items (item_name, unit, category, opening_stock, min_stock_level) VALUES (:name, :unit, :category, 0, 0)");
                $createItem->execute(['name' => $itemName, 'unit' => $unit, 'category' => $category]);
                $itemId = (int) $pdo->lastInsertId();
            } else {
                $itemId = (int) $kitchenItem['id'];
                $pdo->prepare("UPDATE kitchen_items SET category = :category WHERE id = :id")
                    ->execute(['category' => $category, 'id' => $itemId]);
            }
            $trackedItem = $kitchenItem ?: ['id' => $itemId, 'item_name' => $itemName, 'unit' => $unit];
            kitchen_record_legacy_stock_baseline($pdo, $trackedItem, $recordedBy);
            $movement = $pdo->prepare(
                "INSERT INTO kitchen_stock_movements
                    (kitchen_item_id, movement_type, quantity, unit, unit_price, total_cost, reference_id, note, recorded_by, transaction_date, academic_year, term)
                 VALUES (:item_id, 'purchase', :quantity, :unit, :unit_price, :total_cost, :reference_id, :note, :recorded_by, :transaction_date, :academic_year, :term)"
            );
            $movement->execute([
                'item_id' => $itemId,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'total_cost' => $total,
                'reference_id' => $purchaseId,
                'note' => 'Kitchen purchase #' . $purchaseId . ' (' . $category . ')',
                'recorded_by' => $recordedBy,
                'transaction_date' => $date,
                'academic_year' => $context['academic_year'],
                'term' => $context['term'],
            ]);
        }

        kitchen_write_audit($pdo, 'purchase', $purchaseId, 'purchase_recorded', null, $quantity, [
            'item_name' => $itemName,
            'category' => $category,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total_amount' => $total,
            'purchase_date' => $date,
            'track_stock' => $trackStock,
            'school_expense_id' => $expenseId,
        ], $recordedBy);

        $pdo->commit();
        return ['id' => $purchaseId, 'created' => true];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $existing = $pdo->prepare("SELECT id FROM kitchen_purchase_records WHERE request_token = :token LIMIT 1");
            $existing->execute(['token' => $requestToken]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                return ['id' => (int) $existingId, 'created' => false];
            }
        }
        throw $exception;
    }
}

function kitchen_record_expense(PDO $pdo, array $entry, int $recordedBy): array
{
    ensure_kitchen_tables($pdo);
    $requestToken = strtolower(trim((string) ($entry['request_token'] ?? '')));
    $itemName = trim((string) ($entry['item_name'] ?? ''));
    $categorySelection = trim((string) ($entry['category'] ?? ''));
    $category = $categorySelection;
    $newCategory = $categorySelection === '__add_category__';
    if ($newCategory) {
        $category = trim((string) ($entry['custom_category'] ?? ''));
    }
    $quantityText = trim((string) ($entry['quantity'] ?? ''));
    $unit = trim((string) ($entry['unit'] ?? ''));
    $unitPriceText = trim((string) ($entry['unit_price'] ?? ''));
    $totalText = trim((string) ($entry['total_amount'] ?? ''));
    $date = trim((string) ($entry['purchase_date'] ?? ''));
    $description = trim((string) ($entry['description'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
        throw new InvalidArgumentException('Refresh the form and try saving the expense again.');
    }
    if ($itemName === '' || strlen($itemName) > 120) {
        throw new InvalidArgumentException('Enter an item name and choose a valid kitchen category.');
    }
    if ($newCategory) {
        $categoryLength = function_exists('mb_strlen') ? mb_strlen($category, 'UTF-8') : strlen($category);
        $normalizedCategory = function_exists('mb_strtolower') ? mb_strtolower($category, 'UTF-8') : strtolower($category);
        if ($category === '' || preg_match('//u', $category) !== 1 || $categoryLength > 40 || str_contains($category, '|') || preg_match('/[\x00-\x1F\x7F]/u', $category)
            || in_array($normalizedCategory, ['food', 'gas', 'other'], true)) {
            throw new InvalidArgumentException('Enter a new category name up to 40 characters. Food, Gas, and Other are reserved.');
        }
    } elseif (!in_array($category, ['Food', 'Gas', 'Other'], true)) {
        $categoryLookup = $pdo->prepare('SELECT category_name FROM kitchen_expense_categories WHERE category_name = :category LIMIT 1');
        $categoryLookup->execute(['category' => $category]);
        $savedCategory = $categoryLookup->fetchColumn();
        if ($savedCategory === false) {
            throw new InvalidArgumentException('Choose a saved kitchen category or select Other to add one.');
        }
        $category = (string) $savedCategory;
    }
    if (strlen($unit) > 30) {
        throw new InvalidArgumentException('Keep the unit under 30 characters.');
    }
    if (($quantityText !== '' && (!valid_quantity_value($quantityText) || (float) $quantityText <= 0))
        || ($unitPriceText !== '' && (!valid_money_value($unitPriceText) || (float) $unitPriceText <= 0))
        || ($totalText !== '' && (!valid_money_value($totalText) || (float) $totalText <= 0))) {
        throw new InvalidArgumentException('Enter a valid quantity, unit price, or total amount.');
    }
    $quantity = $quantityText === '' ? 0.0 : (float) $quantityText;
    $unitPrice = $unitPriceText === '' ? 0.0 : (float) $unitPriceText;
    $total = $quantity > 0 && $unitPrice > 0 ? round($quantity * $unitPrice, 2) : (float) $totalText;
    if ($total <= 0) {
        throw new InvalidArgumentException('Enter a total amount, or provide both quantity and unit price.');
    }
    if (!valid_date_value($date) || strlen($description) > 2000) {
        throw new InvalidArgumentException('Enter a valid purchase date and keep the notes under 2,000 characters.');
    }

    $existing = $pdo->prepare('SELECT id FROM kitchen_purchase_records WHERE request_token = :token LIMIT 1');
    $existing->execute(['token' => $requestToken]);
    $existingId = $existing->fetchColumn();
    if ($existingId !== false) {
        return ['id' => (int) $existingId, 'created' => false];
    }

    // New entries belong to the currently active school period, even if a back-dated purchase date is entered.
    $context = current_academic_context($pdo);
    $pdo->beginTransaction();
    try {
        if ($newCategory) {
            $pdo->prepare('INSERT IGNORE INTO kitchen_expense_categories (category_name, created_by) VALUES (:category, :created_by)')
                ->execute(['category' => $category, 'created_by' => $recordedBy > 0 ? $recordedBy : null]);
            $categoryLookup = $pdo->prepare('SELECT category_name FROM kitchen_expense_categories WHERE category_name = :category LIMIT 1');
            $categoryLookup->execute(['category' => $category]);
            $category = (string) $categoryLookup->fetchColumn();
            if ($category === '') {
                throw new RuntimeException('The kitchen category could not be saved.');
            }
        }

        $insert = $pdo->prepare(
            "INSERT INTO kitchen_purchase_records
                (request_token, item_name, category, quantity, unit, unit_price, total_amount, purchase_date, supplier, description, track_stock, academic_year, term, recorded_by)
             VALUES
                (:request_token, :item_name, :category, :quantity, :unit, :unit_price, :total_amount, :purchase_date, NULL, :description, 0, :academic_year, :term, :recorded_by)"
        );
        $insert->execute([
            'request_token' => $requestToken,
            'item_name' => $itemName,
            'category' => $category,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total_amount' => $total,
            'purchase_date' => $date,
            'description' => $description !== '' ? $description : null,
            'academic_year' => $context['academic_year'],
            'term' => $context['term'],
            'recorded_by' => $recordedBy,
        ]);
        $entryId = (int) $pdo->lastInsertId();

        $expenseColumns = 'item_name, category, amount, quantity, unit, total_amount, expense_date, description';
        $expenseValues = ':item_name, \'Kitchen\', :amount, :quantity, NULL, :total_amount, :expense_date, :description';
        $params = [
            'item_name' => $itemName,
            'amount' => $unitPrice > 0 ? $unitPrice : $total,
            'quantity' => $quantity > 0 ? $quantity : 1,
            'total_amount' => $total,
            'expense_date' => $date,
            'description' => 'Kitchen category: ' . $category . ' | Kitchen entry #' . $entryId . ($description !== '' ? ' | ' . $description : ''),
        ];
        if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
            $expenseColumns .= ', academic_year, term';
            $expenseValues .= ', :academic_year, :term';
            $params['academic_year'] = $context['academic_year'];
            $params['term'] = $context['term'];
        }
        $expense = $pdo->prepare("INSERT INTO school_expenses ({$expenseColumns}) VALUES ({$expenseValues})");
        $expense->execute($params);
        $expenseId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE kitchen_purchase_records SET school_expense_id = :expense_id WHERE id = :id')
            ->execute(['expense_id' => $expenseId, 'id' => $entryId]);

        kitchen_write_audit($pdo, 'expense', $entryId, 'expense_recorded', null, $total, [
            'item_name' => $itemName,
            'category' => $category,
            'quantity' => $quantity > 0 ? $quantity : null,
            'unit' => $unit !== '' ? $unit : null,
            'unit_price' => $unitPrice > 0 ? $unitPrice : null,
            'total_amount' => $total,
            'purchase_date' => $date,
            'academic_year' => $context['academic_year'],
            'term' => $context['term'],
            'school_expense_id' => $expenseId,
        ], $recordedBy);

        $pdo->commit();
        return ['id' => $entryId, 'created' => true];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $existing->execute(['token' => $requestToken]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                return ['id' => (int) $existingId, 'created' => false];
            }
        }
        throw $exception;
    }
}

function kitchen_adjust_stock(PDO $pdo, int $itemId, float $countedQuantity, string $date, string $reason, int $recordedBy): array
{
    ensure_kitchen_tables($pdo);
    if ($itemId <= 0 || $countedQuantity < 0 || !valid_date_value($date) || trim($reason) === '' || strlen(trim($reason)) > 500) {
        throw new InvalidArgumentException('Enter a valid counted quantity, date, and reason for the adjustment.');
    }

    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare("SELECT * FROM kitchen_items WHERE id = :id FOR UPDATE");
        $itemStmt->execute(['id' => $itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new InvalidArgumentException('The selected kitchen item no longer exists.');
        }

        $movementStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN movement_type IN ('in', 'purchase') THEN quantity ELSE 0 END), 0) AS incoming,
                    COALESCE(SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END), 0) AS outgoing
             FROM kitchen_stock_movements WHERE kitchen_item_id = :id"
        );
        $movementStmt->execute(['id' => $itemId]);
        $movementTotals = $movementStmt->fetch(PDO::FETCH_ASSOC) ?: ['incoming' => 0, 'outgoing' => 0];
        $incoming = (float) $movementTotals['incoming'];
        $purchased = $incoming;
        $legacyBalance = ['quantity' => 0.0, 'unit' => '', 'unit_price' => 0.0, 'date' => ''];
        if ($incoming <= 0) {
            $legacyBalance = kitchen_legacy_inventory_balance($pdo, (string) $item['item_name']);
            $purchased = (float) $legacyBalance['quantity'];
        }
        $before = (float) $item['opening_stock'] + $purchased - (float) $movementTotals['outgoing'];
        $delta = round($countedQuantity - $before, 2);
        $movementId = null;

        if (abs($delta) >= 0.005) {
            $context = kitchen_transaction_context($pdo, $date);
            if ($delta > 0 && $incoming <= 0 && (float) $legacyBalance['quantity'] > 0) {
                kitchen_record_legacy_stock_baseline($pdo, $item, $recordedBy, $legacyBalance);
            }
            $priceStmt = $pdo->prepare("SELECT unit_price FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type IN ('in', 'purchase') ORDER BY transaction_date DESC, id DESC LIMIT 1");
            $priceStmt->execute(['id' => $itemId]);
            $unitPrice = (float) ($priceStmt->fetchColumn() ?: 0);
            $movement = $pdo->prepare(
                "INSERT INTO kitchen_stock_movements
                    (kitchen_item_id, movement_type, quantity, unit, unit_price, total_cost, reference_id, note, recorded_by, transaction_date, academic_year, term)
                 VALUES (:item_id, :movement_type, :quantity, :unit, :unit_price, :total_cost, NULL, :note, :recorded_by, :transaction_date, :academic_year, :term)"
            );
            $movement->execute([
                'item_id' => $itemId,
                'movement_type' => $delta > 0 ? 'in' : 'out',
                'quantity' => abs($delta),
                'unit' => $item['unit'],
                'unit_price' => $unitPrice,
                'total_cost' => round(abs($delta) * $unitPrice, 2),
                'note' => 'Stock count adjustment: ' . trim($reason),
                'recorded_by' => $recordedBy,
                'transaction_date' => $date,
                'academic_year' => $context['academic_year'],
                'term' => $context['term'],
            ]);
            $movementId = (int) $pdo->lastInsertId();
        }

        kitchen_write_audit($pdo, 'stock', $itemId, 'stock_count_adjusted', $before, $countedQuantity, [
            'item_name' => $item['item_name'],
            'unit' => $item['unit'],
            'reason' => trim($reason),
            'adjustment' => $delta,
            'movement_id' => $movementId,
            'count_date' => $date,
        ], $recordedBy);
        $pdo->commit();

        return ['before' => $before, 'after' => $countedQuantity, 'adjustment' => $delta];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function kitchen_legacy_inventory_balance(PDO $pdo, string $itemName): array
{
    $where = 'LOWER(TRIM(item_name)) = LOWER(TRIM(:name))';
    if (db_has_column($pdo, 'kitchen_inventory', 'purchase_type')) {
        $where .= " AND COALESCE(purchase_type, 'weekly') <> 'daily'";
    }
    $quantity = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM kitchen_inventory WHERE {$where}");
    $quantity->execute(['name' => $itemName]);

    $latest = $pdo->prepare(
        "SELECT COALESCE(unit, '') AS unit, COALESCE(unit_price, 0) AS unit_price, item_date
         FROM kitchen_inventory WHERE {$where}
         ORDER BY item_date DESC, id DESC LIMIT 1"
    );
    $latest->execute(['name' => $itemName]);
    $row = $latest->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'quantity' => (float) $quantity->fetchColumn(),
        'unit' => trim((string) ($row['unit'] ?? '')),
        'unit_price' => (float) ($row['unit_price'] ?? 0),
        'date' => (string) ($row['item_date'] ?? ''),
    ];
}

function kitchen_record_legacy_stock_baseline(PDO $pdo, array $item, int $recordedBy, ?array $legacyBalance = null): float
{
    $incoming = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM kitchen_stock_movements
         WHERE kitchen_item_id = :item_id AND movement_type IN ('in', 'purchase')"
    );
    $incoming->execute(['item_id' => (int) $item['id']]);
    if ((float) $incoming->fetchColumn() > 0) {
        return 0.0;
    }

    $legacyBalance ??= kitchen_legacy_inventory_balance($pdo, (string) $item['item_name']);
    $quantity = (float) ($legacyBalance['quantity'] ?? 0);
    if ($quantity <= 0) {
        return 0.0;
    }
    $legacyUnit = trim((string) ($legacyBalance['unit'] ?? ''));
    $unit = trim((string) ($item['unit'] ?? 'kg')) ?: 'kg';

    $date = valid_date_value((string) ($legacyBalance['date'] ?? ''))
        ? (string) $legacyBalance['date']
        : date('Y-m-d');
    $context = kitchen_transaction_context($pdo, $date);
    $price = (float) ($legacyBalance['unit_price'] ?? 0);
    $movement = $pdo->prepare(
        "INSERT INTO kitchen_stock_movements
            (kitchen_item_id, movement_type, quantity, unit, unit_price, total_cost, reference_id, note, recorded_by, transaction_date, academic_year, term)
         VALUES (:item_id, 'in', :quantity, :unit, :unit_price, :total_cost, NULL, :note, :recorded_by, :transaction_date, :academic_year, :term)"
    );
    $movement->execute([
        'item_id' => (int) $item['id'],
        'quantity' => $quantity,
        'unit' => $unit,
        'unit_price' => $price,
        'total_cost' => round($quantity * $price, 2),
        'note' => 'Legacy stock balance carried forward; no new expense recorded',
        'recorded_by' => $recordedBy,
        'transaction_date' => $date,
        'academic_year' => $context['academic_year'],
        'term' => $context['term'],
    ]);
    kitchen_write_audit($pdo, 'stock', (int) $item['id'], 'legacy_stock_carried_forward', null, $quantity, [
        'item_name' => (string) $item['item_name'],
        'unit' => $unit,
        'legacy_unit' => $legacyUnit,
        'historical_date' => $date,
        'reason' => 'Preserved prior stock records while moving this item into the new purchase ledger.',
    ], $recordedBy);

    return $quantity;
}

function kitchen_record_movement(PDO $pdo, int $kitchenItemId, string $type, float $quantity, float $unitPrice = 0.0, float $total = 0.0, ?int $referenceId = null, ?string $note = null, ?int $recordedBy = null, ?string $transactionDate = null, ?string $unit = null): void
{
    ensure_kitchen_tables($pdo);
    $context = kitchen_transaction_context($pdo, $transactionDate);
    if ($unit === null || trim($unit) === '') {
        $unitStmt = $pdo->prepare("SELECT unit FROM kitchen_items WHERE id = :id");
        $unitStmt->execute(['id' => $kitchenItemId]);
        $unit = (string) ($unitStmt->fetchColumn() ?: 'kg');
    }
    $stmt = $pdo->prepare(
        "INSERT INTO kitchen_stock_movements (kitchen_item_id, movement_type, quantity, unit, unit_price, total_cost, reference_id, note, recorded_by, transaction_date, academic_year, term) VALUES (:kitchen_item_id, :movement_type, :quantity, :unit, :unit_price, :total_cost, :reference_id, :note, :recorded_by, :transaction_date, :academic_year, :term)"
    );
    $stmt->execute([
        'kitchen_item_id' => $kitchenItemId,
        'movement_type' => $type,
        'quantity' => $quantity,
        'unit' => $unit,
        'unit_price' => $unitPrice,
        'total_cost' => $total,
        'reference_id' => $referenceId,
        'note' => $note,
        'recorded_by' => $recordedBy,
        'transaction_date' => $context['transaction_date'],
        'academic_year' => $context['academic_year'],
        'term' => $context['term'],
    ]);
}

function kitchen_get_purchase_movement(PDO $pdo, int $referenceId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM kitchen_stock_movements WHERE reference_id = :reference_id AND movement_type = 'purchase' LIMIT 1"
    );
    $stmt->execute(['reference_id' => $referenceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function kitchen_sync_purchase_movement(PDO $pdo, int $purchaseId, string $itemName, float $quantity, float $unitPrice, float $totalCost, ?string $note = null, ?int $recordedBy = null, ?string $unit = null): void
{
    $kitem = kitchen_get_or_create_item($pdo, $itemName, 'Kitchen', 0.0, 0.0, $unit ?? '');
    $unit = $unit ?: (string) ($kitem['unit'] ?? 'kg');
    $movement = kitchen_get_purchase_movement($pdo, $purchaseId);

    if ($movement) {
        $stmt = $pdo->prepare(
            "UPDATE kitchen_stock_movements
             SET kitchen_item_id = :kitchen_item_id,
                 quantity = :quantity,
                 unit = :unit,
                 unit_price = :unit_price,
                 total_cost = :total_cost,
                 note = :note,
                 recorded_by = :recorded_by
             WHERE id = :id"
        );
        $stmt->execute([
            'kitchen_item_id' => $kitem['id'],
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total_cost' => $totalCost,
            'note' => $note,
            'recorded_by' => $recordedBy,
            'id' => $movement['id'],
        ]);
    } else {
        kitchen_record_movement($pdo, (int)$kitem['id'], 'purchase', $quantity, $unitPrice, $totalCost, $purchaseId, $note, $recordedBy, null, $unit);
    }
}

function kitchen_delete_purchase_movement(PDO $pdo, int $purchaseId): void
{
    $stmt = $pdo->prepare("DELETE FROM kitchen_stock_movements WHERE reference_id = :reference_id AND movement_type = 'purchase'");
    $stmt->execute(['reference_id' => $purchaseId]);
}

function kitchen_find_weekly_purchase_movement(PDO $pdo, string $itemName, float $quantity, float $totalAmount, ?string $unit = null): ?array
{
    $stmt = $pdo->prepare(
        "SELECT ksm.id, ksm.reference_id
         FROM kitchen_stock_movements ksm
         JOIN kitchen_items ki ON ki.id = ksm.kitchen_item_id
         WHERE ksm.movement_type = 'purchase'
           AND ksm.note LIKE 'Weekly shopping%'
           AND ksm.quantity = :quantity
           AND ksm.unit <=> :unit
           AND ksm.total_cost = :total_amount
           AND ki.item_name = :item_name
         ORDER BY ksm.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        'quantity' => $quantity,
        'unit' => $unit,
        'total_amount' => $totalAmount,
        'item_name' => $itemName,
    ]);

    $movement = $stmt->fetch();
    return $movement ?: null;
}

function kitchen_delete_weekly_purchase_row(PDO $pdo, array $inventoryRow): void
{
    $movement = kitchen_find_weekly_purchase_movement($pdo, $inventoryRow['item_name'], (float) $inventoryRow['quantity'], (float) $inventoryRow['total_amount'], $inventoryRow['unit'] ?? null);
    if (!$movement) {
        return;
    }

    $refId = (int) $movement['reference_id'];

    $deleteItemStmt = $pdo->prepare(
        "DELETE FROM weekly_shopping_items
         WHERE weekly_shopping_id = :weekly_shopping_id
           AND item_name = :item_name
           AND quantity = :quantity
           AND unit <=> :unit
           AND unit_price = :unit_price
         LIMIT 1"
    );
    $deleteItemStmt->execute([
        'weekly_shopping_id' => $refId,
        'item_name' => $inventoryRow['item_name'],
        'quantity' => (float) $inventoryRow['quantity'],
        'unit' => $inventoryRow['unit'] ?? null,
        'unit_price' => (float) $inventoryRow['unit_price'],
    ]);

    $deleteMovementStmt = $pdo->prepare("DELETE FROM kitchen_stock_movements WHERE id = :id");
    $deleteMovementStmt->execute(['id' => (int) $movement['id']]);

    $countItemsStmt = $pdo->prepare("SELECT COUNT(*) FROM weekly_shopping_items WHERE weekly_shopping_id = :weekly_shopping_id");
    $countItemsStmt->execute(['weekly_shopping_id' => $refId]);
    if ((int) $countItemsStmt->fetchColumn() === 0) {
        $deleteWeeklyStmt = $pdo->prepare("DELETE FROM weekly_shopping WHERE id = :id");
        $deleteWeeklyStmt->execute(['id' => $refId]);
    }
}

function kitchen_inventory_row_for_expense(PDO $pdo, array $expense): ?array
{
    $category = $expense['category'] ?? '';
    $params = [
        'item_name' => $expense['item_name'],
        'item_date' => $expense['expense_date'],
        'quantity' => (float) $expense['quantity'],
        'total_amount' => (float) $expense['total_amount'],
    ];

    if (in_array($category, ['Kitchen', 'WHOLESALE'], true)) {
        $sql = "SELECT * FROM kitchen_inventory
                WHERE item_name = :item_name
                  AND item_date = :item_date
                  AND quantity = :quantity
                  AND total_amount = :total_amount
                  AND category IN ('Kitchen', 'WHOLESALE')
                ORDER BY id DESC
                LIMIT 1";
    } else {
        $sql = "SELECT * FROM kitchen_inventory
                WHERE item_name = :item_name
                  AND item_date = :item_date
                  AND quantity = :quantity
                  AND total_amount = :total_amount
                  AND category = :category
                ORDER BY id DESC
                LIMIT 1";
        $params['category'] = $category;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function kitchen_inventory_delete_for_expense(PDO $pdo, array $expense): void
{
    $row = kitchen_inventory_row_for_expense($pdo, $expense);
    if (!$row) {
        return;
    }

    if (($row['purchase_type'] ?? 'single') === 'weekly') {
        kitchen_delete_weekly_purchase_row($pdo, $row);
    } else {
        kitchen_delete_purchase_movement($pdo, (int) $row['id']);
    }

    $stmt = $pdo->prepare("DELETE FROM kitchen_inventory WHERE id = :id");
    $stmt->execute(['id' => (int) $row['id']]);
}

function kitchen_update_weekly_purchase_row(PDO $pdo, array $inventoryRow, array $updated): void
{
    $movement = kitchen_find_weekly_purchase_movement($pdo, $inventoryRow['item_name'], (float) $inventoryRow['quantity'], (float) $inventoryRow['total_amount'], $inventoryRow['unit'] ?? null);
    if (!$movement) {
        return;
    }

    $totalAmount = (float) $updated['amount'] * (float) $updated['quantity'];
    $unit = trim((string) ($updated['unit'] ?? '')) ?: ($inventoryRow['unit'] ?? null);

    $updateMovement = $pdo->prepare(
        "UPDATE kitchen_stock_movements
         SET quantity = :quantity,
             unit = :unit,
             unit_price = :unit_price,
             total_cost = :total_cost,
             note = :note
         WHERE id = :id"
    );
    $updateMovement->execute([
        'quantity' => (float) $updated['quantity'],
        'unit' => $unit,
        'unit_price' => (float) $updated['amount'],
        'total_cost' => $totalAmount,
        'note' => 'Weekly shopping',
        'id' => (int) $movement['id'],
    ]);

    $updateItem = $pdo->prepare(
        "UPDATE weekly_shopping_items
         SET item_name = :item_name,
             quantity = :quantity,
             unit = :unit,
             unit_price = :unit_price,
             total_amount = :total_amount
         WHERE weekly_shopping_id = :weekly_shopping_id
           AND item_name = :old_item_name
           AND quantity = :old_quantity
           AND unit <=> :old_unit
           AND unit_price = :old_unit_price
         LIMIT 1"
    );
    $updateItem->execute([
        'item_name' => $updated['item_name'],
        'quantity' => (float) $updated['quantity'],
        'unit' => $unit,
        'unit_price' => (float) $updated['amount'],
        'total_amount' => $totalAmount,
        'weekly_shopping_id' => (int) $movement['reference_id'],
        'old_item_name' => $inventoryRow['item_name'],
        'old_quantity' => (float) $inventoryRow['quantity'],
        'old_unit' => $inventoryRow['unit'] ?? null,
        'old_unit_price' => (float) $inventoryRow['unit_price'],
    ]);

    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM weekly_shopping_items WHERE weekly_shopping_id = :id");
    $sumStmt->execute(['id' => (int) $movement['reference_id']]);
    $total = (float) $sumStmt->fetchColumn();

    $updateWeekly = $pdo->prepare("UPDATE weekly_shopping SET total_amount = :total_amount WHERE id = :id");
    $updateWeekly->execute(['total_amount' => $total, 'id' => (int) $movement['reference_id']]);
}

function kitchen_inventory_update_for_expense(PDO $pdo, array $expense, array $updated): void
{
    $row = kitchen_inventory_row_for_expense($pdo, $expense);
    if (!$row) {
        return;
    }

    $totalAmount = (float) $updated['amount'] * (float) $updated['quantity'];
    $category = $updated['category'] ?? $expense['category'];
    $params = [
        'item_name' => $updated['item_name'],
        'quantity' => (float) $updated['quantity'],
        'unit' => trim((string) ($updated['unit'] ?? '')) ?: ($row['unit'] ?? 'kg'),
        'unit_price' => (float) $updated['amount'],
        'total_amount' => $totalAmount,
        'item_date' => $updated['expense_date'],
        'supplier' => $updated['supplier'] ?? $expense['supplier'] ?? null,
        'category' => $category,
        'id' => (int) $row['id'],
    ];

    $stmt = $pdo->prepare(
        "UPDATE kitchen_inventory
         SET item_name = :item_name,
             quantity = :quantity,
             unit = :unit,
             unit_price = :unit_price,
             total_amount = :total_amount,
             item_date = :item_date,
             supplier = :supplier,
             category = :category
         WHERE id = :id"
    );
    $stmt->execute($params);

    if (($row['purchase_type'] ?? 'single') === 'weekly') {
        kitchen_update_weekly_purchase_row($pdo, $row, $updated);
    } else {
        kitchen_sync_purchase_movement(
            $pdo,
            (int) $row['id'],
            $updated['item_name'],
            (float) $updated['quantity'],
            (float) $updated['amount'],
            $totalAmount,
            'Purchase from ' . ($updated['supplier'] ?? ''),
            $_SESSION['admin_id'] ?? null,
            $updated['unit'] ?? null
        );
    }
}

function kitchen_aggregated_summary(PDO $pdo, ?string $academicYear = null, ?string $term = null, bool $ignoreContext = false): array
{
    ensure_kitchen_tables($pdo);
    $context = current_academic_context($pdo);
    if (!$ignoreContext) {
        if ($academicYear === null) {
            $academicYear = $context['academic_year'];
        }
        if ($term === null) {
            $term = $context['term'];
        }
    }

    $itemsStmt = $pdo->query("SELECT * FROM kitchen_items ORDER BY item_name ASC");
    $rawItems = $itemsStmt->fetchAll();
    // deduplicate by normalized name to avoid showing duplicates (case/space variants)
    $items = [];
    $seen = [];
    foreach ($rawItems as $it) {
        $norm = mb_strtolower(trim($it['item_name']));
        if (isset($seen[$norm])) continue;
        $seen[$norm] = true;
        $items[] = $it;
    }
    $rows = [];

    $movementInSql = "SELECT COALESCE(SUM(quantity),0) FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type IN ('in','purchase')";
    $outSql = "SELECT COALESCE(SUM(quantity),0) FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type = 'out'";
    $inventoryPurchaseSql = db_has_column($pdo, 'kitchen_inventory', 'purchase_type')
        ? "SELECT COALESCE(SUM(quantity),0) FROM kitchen_inventory WHERE item_name = :item_name AND COALESCE(purchase_type,'weekly') != 'daily'"
        : "SELECT COALESCE(SUM(quantity),0) FROM kitchen_inventory WHERE item_name = :item_name";
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
        $movementInSql .= " AND academic_year = :academic_year AND term = :term";
        $outSql .= " AND academic_year = :academic_year AND term = :term";
    }
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
        $inventoryPurchaseSql .= " AND academic_year = :academic_year AND term = :term";
    }

    $movementInStmt = $pdo->prepare($movementInSql);
    $outStmt = $pdo->prepare($outSql);
    $inventoryPurchaseStmt = $pdo->prepare($inventoryPurchaseSql);
    $inventoryUnitStmt = $pdo->prepare("SELECT unit FROM kitchen_inventory WHERE item_name = :item_name AND unit IS NOT NULL LIMIT 1");
    $lastPriceSql = "SELECT unit_price FROM kitchen_stock_movements WHERE kitchen_item_id = :id AND movement_type IN ('in','purchase')";
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
        $lastPriceSql .= " AND academic_year = :academic_year AND term = :term";
    }
    $lastPriceSql .= " ORDER BY transaction_date DESC, id DESC LIMIT 1";
    $lastPriceStmt = $pdo->prepare($lastPriceSql);
    $inventoryLatestPriceStmt = $pdo->prepare("SELECT unit_price FROM kitchen_inventory WHERE item_name = :item_name ORDER BY item_date DESC, id DESC LIMIT 1");

    foreach ($items as $it) {
        $movementInParams = ['id' => $it['id']];
        $outParams = ['id' => $it['id']];
        if (!$ignoreContext && db_has_column($pdo, 'kitchen_stock_movements', 'academic_year') && db_has_column($pdo, 'kitchen_stock_movements', 'term')) {
            $movementInParams['academic_year'] = $academicYear;
            $movementInParams['term'] = $term;
            $outParams['academic_year'] = $academicYear;
            $outParams['term'] = $term;
        }
        $movementInStmt->execute($movementInParams);
        $ins = (float) $movementInStmt->fetchColumn();

        $outStmt->execute($outParams);
        $outs = (float) $outStmt->fetchColumn();

        $inventoryPurchased = 0.0;
        if ($ins <= 0) {
            $inventoryPurchaseParams = ['item_name' => $it['item_name']];
            if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
                $inventoryPurchaseParams['academic_year'] = $academicYear;
                $inventoryPurchaseParams['term'] = $term;
            }
            $inventoryPurchaseStmt->execute($inventoryPurchaseParams);
            $inventoryPurchased = (float) $inventoryPurchaseStmt->fetchColumn();
        }

        $opening = (float) $it['opening_stock'];
        $purchased = $ins > 0 ? $ins : $inventoryPurchased;
        $remaining = $opening + $purchased - $outs;

        $lastPriceStmt->execute($movementInParams);
        $unitPrice = (float) $lastPriceStmt->fetchColumn();
        $stockValue = $remaining * $unitPrice;

        $rows[] = [
            'id' => (int) $it['id'],
            'item_name' => $it['item_name'],
            'category' => $it['category'],
            'unit' => $it['unit'] ?: 'kg',
            'opening_stock' => $opening,
            'purchased_quantity' => $purchased,
            'used_quantity' => $outs,
            'remaining_stock' => $remaining,
            'unit_price' => $unitPrice,
            'stock_value' => $stockValue,
            'min_stock_level' => (float) $it['min_stock_level'],
        ];
    }

    $inventoryDistinctSql = "SELECT DISTINCT item_name FROM kitchen_inventory";
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
        $inventoryDistinctSql .= " WHERE academic_year = :academic_year AND term = :term";
    }
    $inventoryDistinctSql .= " ORDER BY item_name ASC";
    $stmt = $pdo->prepare($inventoryDistinctSql);
    $params = [];
    if (!$ignoreContext && db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
        $params = ['academic_year' => $academicYear, 'term' => $term];
    }
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $exists = false;
        foreach ($rows as $row) {
            if ($row['item_name'] === $r['item_name']) { $exists = true; break; }
        }
        if ($exists) continue;

        $inventoryPurchaseParams = ['item_name' => $r['item_name']];
        if (db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
            $inventoryPurchaseParams['academic_year'] = $academicYear;
            $inventoryPurchaseParams['term'] = $term;
        }
        $inventoryPurchaseStmt->execute($inventoryPurchaseParams);
        $purchased = (float) $inventoryPurchaseStmt->fetchColumn();
        $inventoryUnitStmt->execute(['item_name' => $r['item_name']]);
        $unitRow = $inventoryUnitStmt->fetch();
        $inventoryLatestPriceStmt->execute(['item_name' => $r['item_name']]);
        $unitPrice = (float) $inventoryLatestPriceStmt->fetchColumn();
        $stockValue = $purchased * $unitPrice;

        $rows[] = [
            'id' => 0,
            'item_name' => $r['item_name'],
            'category' => 'Kitchen',
            'unit' => $unitRow['unit'] ?? 'kg',
            'opening_stock' => 0.0,
            'purchased_quantity' => $purchased,
            'used_quantity' => 0.0,
            'remaining_stock' => $purchased,
            'unit_price' => $unitPrice,
            'stock_value' => $stockValue,
            'min_stock_level' => 0.0,
        ];
    }

    return $rows;
}

function low_stock_kitchen_items(PDO $pdo): array
{
    $rows = kitchen_aggregated_summary($pdo);
    $low = [];
    foreach ($rows as $r) {
        if ($r['remaining_stock'] <= 0) {
            $r['warning'] = 'Out of Stock';
            $low[] = $r;
            continue;
        }
        if ($r['remaining_stock'] <= $r['min_stock_level']) {
            $r['warning'] = 'Low Stock';
            $low[] = $r;
        }
    }
    return $low;
}

function low_stock_kitchen_items_all(PDO $pdo): array
{
    // Aggregate across all academic years/terms so notifications persist until addressed.
    $rows = kitchen_aggregated_summary($pdo, null, null, true);
    $low = [];
    foreach ($rows as $r) {
        if ($r['remaining_stock'] <= 0) {
            $r['warning'] = 'Out of Stock';
            $low[] = $r;
            continue;
        }
        if ($r['remaining_stock'] <= $r['min_stock_level']) {
            $r['warning'] = 'Low Stock';
            $low[] = $r;
        }
    }
    return $low;
}

function kitchen_inventory_valuation(PDO $pdo): float
{
    ensure_kitchen_tables($pdo);
    $inStmt = $pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM kitchen_stock_movements WHERE movement_type IN ('in','purchase')");
    $outStmt = $pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM kitchen_stock_movements WHERE movement_type = 'out'");
    return (float) $inStmt->fetchColumn() - (float) $outStmt->fetchColumn();
}

// Record a daily purchase and update inventory stock levels.
function record_daily_purchase(PDO $pdo, string $itemName, float $quantity, string $unit, float $amount, string $category, ?string $supplier, ?string $notes, ?string $paymentMethod, string $date): int
{
    $context = current_academic_context($pdo, $date);
    $columns = 'item_name, quantity, unit, amount, category, supplier, notes, payment_method, purchase_date';
    $values = ':item_name, :quantity, :unit, :amount, :category, :supplier, :notes, :payment_method, :purchase_date';
    $params = [
        'item_name' => $itemName,
        'quantity' => $quantity,
        'unit' => $unit ?: 'kg',
        'amount' => $amount,
        'category' => $category ?: 'Kitchen',
        'supplier' => $supplier,
        'notes' => $notes,
        'payment_method' => $paymentMethod,
        'purchase_date' => $date,
    ];
    if (db_has_column($pdo, 'kitchen_daily_purchases', 'academic_year') && db_has_column($pdo, 'kitchen_daily_purchases', 'term')) {
        $columns .= ', academic_year, term';
        $values .= ', :academic_year, :term';
        $params['academic_year'] = $context['academic_year'];
        $params['term'] = $context['term'];
    }
    $stmt = $pdo->prepare(
        "INSERT INTO kitchen_daily_purchases ({$columns}) VALUES ({$values})"
    );
    $stmt->execute($params);
    $id = (int) $pdo->lastInsertId();

    // Daily purchases are consumed the same day and should not be added to inventory stock movements.
    // We still record the purchase in `kitchen_daily_purchases` and as a school expense, but we do not
    // create a stock movement or update kitchen inventory for daily purchases.
    $unitPrice = $quantity > 0 ? $amount / $quantity : 0.0;

    // Also record as a school expense for reporting
    $expenseColumns = 'item_name, category, amount, quantity, unit, total_amount, expense_date, description';
    $expenseValues = ':item_name, :category, :amount, :quantity, :unit, :total_amount, :expense_date, :description';
    $expenseHasTerm = db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term');
    if ($expenseHasTerm) {
        $expenseColumns .= ', academic_year, term';
        $expenseValues .= ', :academic_year, :term';
    }
    $exp = $pdo->prepare("INSERT INTO school_expenses ({$expenseColumns}) VALUES ({$expenseValues})");
    $expParams = [
        'item_name' => $itemName,
        'category' => $category ?: 'Kitchen Purchases',
        'amount' => $unitPrice,
        'quantity' => $quantity,
        'unit' => $unit ?: 'kg',
        'total_amount' => $amount,
        'expense_date' => $date,
        'description' => ($notes ?: 'Daily purchase') . ($supplier ? ' | Supplier: ' . $supplier : '') . ($paymentMethod ? ' | Payment: ' . $paymentMethod : ''),
    ];
    if ($expenseHasTerm) {
        $expParams['academic_year'] = $context['academic_year'];
        $expParams['term'] = $context['term'];
    }
    $exp->execute($expParams);

    return $id;
}

// Record a weekly shopping transaction that can contain multiple items.
function record_weekly_shopping(PDO $pdo, ?string $supplier, string $date, array $items): int
{
    // items: array of ['item_name','quantity','unit','unit_price','supplier']
    // each item can have its own supplier
    $total = 0.0;
    foreach ($items as $it) {
        $qty = (float) ($it['quantity'] ?? 0);
        $up = (float) ($it['unit_price'] ?? 0);
        $total += $qty * $up;
    }

    $context = current_academic_context($pdo, $date);
    $wsHasTerm = db_has_column($pdo, 'weekly_shopping', 'academic_year') && db_has_column($pdo, 'weekly_shopping', 'term');
    if ($wsHasTerm) {
        $ins = $pdo->prepare("INSERT INTO weekly_shopping (supplier, shopping_date, total_amount, academic_year, term) VALUES (:supplier, :shopping_date, :total_amount, :academic_year, :term)");
        $ins->execute([
            'supplier' => $supplier,
            'shopping_date' => $date,
            'total_amount' => $total,
            'academic_year' => $context['academic_year'],
            'term' => $context['term'],
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO weekly_shopping (supplier, shopping_date, total_amount) VALUES (:supplier, :shopping_date, :total_amount)");
        $ins->execute([
            'supplier' => $supplier,
            'shopping_date' => $date,
            'total_amount' => $total,
        ]);
    }
    $wsId = (int) $pdo->lastInsertId();

    $hasSupplierColumn = db_has_column($pdo, 'weekly_shopping_items', 'supplier');
    $itemIns = $pdo->prepare("INSERT INTO weekly_shopping_items (weekly_shopping_id, item_name, quantity, unit, unit_price, total_amount" . ($hasSupplierColumn ? ", supplier" : "") . ") VALUES (:weekly_shopping_id, :item_name, :quantity, :unit, :unit_price, :total_amount" . ($hasSupplierColumn ? ", :supplier" : "") . ")");
    foreach ($items as $it) {
        $name = trim($it['item_name'] ?? '');
        $qty = (float) ($it['quantity'] ?? 0);
        $unit = $it['unit'] ?? null;
        $up = (float) ($it['unit_price'] ?? 0);
        $itemSupplier = trim($it['supplier'] ?? '');
        if ($name === '' || $qty <= 0) continue;
        $totalItem = $qty * $up;
        $itemInsParams = ['weekly_shopping_id' => $wsId, 'item_name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $up, 'total_amount' => $totalItem];
        if ($hasSupplierColumn) {
            $itemInsParams['supplier'] = $itemSupplier;
        }
        $itemIns->execute($itemInsParams);

        // Insert into kitchen_inventory as a stock-in purchase (weekly purchases affect stock)
        if (db_has_column($pdo, 'kitchen_inventory', 'purchase_type')) {
            $stmtSql = "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, purchase_type, category";
            $stmtVals = ":item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, 'weekly', 'Kitchen'";
            $itemParams = [
                'item_name' => $name,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $up,
                'total_amount' => $totalItem,
                'item_date' => $date,
                'supplier' => $itemSupplier,
            ];
            if (db_has_column($pdo, 'kitchen_inventory', 'academic_year') && db_has_column($pdo, 'kitchen_inventory', 'term')) {
                $stmtSql .= ", academic_year, term";
                $stmtVals .= ", :academic_year, :term";
                $itemParams['academic_year'] = $context['academic_year'];
                $itemParams['term'] = $context['term'];
            }
            $stmtSql .= ") VALUES ($stmtVals)";
            $stmt = $pdo->prepare($stmtSql);
            $stmt->execute($itemParams);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO kitchen_inventory (item_name, quantity, unit, unit_price, total_amount, item_date, supplier, category) VALUES (:item_name, :quantity, :unit, :unit_price, :total_amount, :item_date, :supplier, 'Kitchen')"
            );
            $stmt->execute(['item_name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $up, 'total_amount' => $totalItem, 'item_date' => $date, 'supplier' => $itemSupplier]);
        }
        $invId = (int) $pdo->lastInsertId();

        // ensure kitchen item exists and record movement referencing weekly_shopping id
        $kitem = kitchen_get_or_create_item($pdo, $name, 'Kitchen', 0.0, 0.0, $unit);
        kitchen_record_movement($pdo, (int)$kitem['id'], 'purchase', $qty, $up, $totalItem, $wsId, 'Weekly shopping', $_SESSION['admin_id'] ?? null, $date, $unit);
    }

    if ($total > 0) {
        $expenseColumns = 'item_name, category, amount, quantity, total_amount, expense_date, description';
        $expenseValues = ':item_name, :category, :amount, :quantity, :total_amount, :expense_date, :description';
        $expenseParams = [
            'item_name' => 'Weekly Kitchen Purchases',
            'category' => 'Kitchen Purchases',
            'amount' => $total,
            'quantity' => 1,
            'total_amount' => $total,
            'expense_date' => $date,
            'description' => 'Weekly shopping batch' . ($supplier ? ' | Supplier: ' . $supplier : ''),
        ];
        if (db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term')) {
            $expenseColumns .= ', academic_year, term';
            $expenseValues .= ', :academic_year, :term';
            $expenseParams['academic_year'] = $context['academic_year'];
            $expenseParams['term'] = $context['term'];
        }
        $exp = $pdo->prepare("INSERT INTO school_expenses ({$expenseColumns}) VALUES ({$expenseValues})");
        $exp->execute($expenseParams);
    }

    return $wsId;
}

function get_daily_purchases(PDO $pdo, ?string $from = null, ?string $to = null, ?string $academicYear = null, ?string $term = null): array
{
    $sql = "SELECT * FROM kitchen_daily_purchases WHERE 1=1";
    $params = [];
    if ($from) { $sql .= " AND purchase_date >= :from"; $params['from'] = $from; }
    if ($to) { $sql .= " AND purchase_date <= :to"; $params['to'] = $to; }
    if ($academicYear !== null && $term !== null) {
        if (db_has_column($pdo, 'kitchen_daily_purchases', 'academic_year') && db_has_column($pdo, 'kitchen_daily_purchases', 'term')) {
            $sql .= " AND academic_year = :academic_year AND term = :term";
            $params['academic_year'] = $academicYear;
            $params['term'] = $term;
        } elseif (empty($from) && empty($to)) {
            $calendarStmt = $pdo->prepare(
                "SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term LIMIT 1"
            );
            $calendarStmt->execute(['academic_year' => $academicYear, 'term' => $term]);
            $calendarRow = $calendarStmt->fetch();
            if ($calendarRow) {
                $sql .= " AND purchase_date BETWEEN :active_term_start AND :active_term_end";
                $params['active_term_start'] = $calendarRow['start_date'];
                $params['active_term_end'] = $calendarRow['end_date'];
            }
        }
    }
    $sql .= " ORDER BY purchase_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (isset($row['category']) && $row['category'] === 'WHOLESALE') {
            $row['category'] = 'Kitchen';
        }
    }
    return $rows;
}

function monthly_gas_expenses(PDO $pdo, int $year, int $month): float
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :start AND :end");
    $stmt->execute(['start' => $start, 'end' => $end]);
    return (float) $stmt->fetchColumn();
}

function yearly_gas_expenses(PDO $pdo, int $year): float
{
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM school_expenses WHERE category = 'Gas Refill' AND expense_date BETWEEN :start AND :end");
    $stmt->execute(['start' => $start, 'end' => $end]);
    return (float) $stmt->fetchColumn();
}

function gender_options(): array
{
    return ['Male', 'Female'];
}

function student_type_options(): array
{
    return ['Normal Student', 'Teacher Child'];
}

function teacher_child_student_type(): string
{
    return 'Teacher Child';
}

function is_teacher_child_type(?string $studentType): bool
{
    return $studentType === teacher_child_student_type();
}

function current_academic_year(): string
{
    return date('Y');
}

function feeding_term_amount(): float
{
    return 3000.00;
}

function term_options(): array
{
    return ['Term 1', 'Term 2', 'Term 3'];
}

function paid_status_options(): array
{
    return ['Paid', 'Partial', 'Unpaid'];
}

function fee_payment_status(float $requiredAmount, float $paidAmount, float $balance): string
{
    if ($balance <= 0.005) {
        return 'Paid';
    }

    if ($paidAmount > 0.005) {
        return 'Partial';
    }

    return 'Unpaid';
}

function feeding_status_options(): array
{
    return ['Active', 'Inactive'];
}

function expense_category_options(): array
{
    return ['Office', 'Utilities', 'Maintenance', 'Transport', 'Other'];
}

function low_stock_uniforms(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT u.*, uv.id AS variant_id, uv.size, uv.reorder_level,
                (uv.opening_stock + COALESCE(SUM(usm.quantity),0)) AS available_stock
         FROM uniforms u
         INNER JOIN uniform_variants uv ON uv.uniform_id = u.id AND uv.status = 'Active'
         LEFT JOIN uniform_stock_movements usm ON usm.uniform_variant_id = uv.id
         WHERE u.status = 'Active'
         GROUP BY u.id, uv.id
         HAVING available_stock <= uv.reorder_level
         ORDER BY available_stock ASC"
    );
    return $statement->fetchAll();
}

function uniform_stock_report_rows(PDO $pdo, bool $lowOnly = false): array
{
    $sql = "SELECT u.uniform_name, u.category, u.gender, u.status AS product_status,
                   uv.id AS variant_id, uv.size, uv.selling_price, uv.opening_stock, uv.reorder_level, uv.status AS variant_status,
                   uv.opening_stock + COALESCE(SUM(sm.quantity), 0) AS available_stock
            FROM uniforms u
            INNER JOIN uniform_variants uv ON uv.uniform_id = u.id
            LEFT JOIN uniform_stock_movements sm ON sm.uniform_variant_id = uv.id
            GROUP BY u.id, uv.id";
    if ($lowOnly) {
        $sql .= " HAVING LOWER(TRIM(u.status)) = 'active'
                      AND LOWER(TRIM(uv.status)) = 'active'
                      AND available_stock <= uv.reorder_level";
    }
    $sql .= ' ORDER BY u.category, u.uniform_name, uv.size';
    return $pdo->query($sql)->fetchAll();
}

function fee_structure_term_columns(): array
{
    return [
        'Term 1' => 'term1_total',
        'Term 2' => 'term2_total',
        'Term 3' => 'term3_total',
    ];
}

function get_fee_structure(PDO $pdo, string $classLevel, string $academicYear): ?array
{
    $statement = $pdo->prepare(
        "SELECT * FROM fee_structures WHERE academic_year = :academic_year AND class_level = :class_level LIMIT 1"
    );
    $statement->execute([
        'academic_year' => $academicYear,
        'class_level' => $classLevel,
    ]);
    $structure = $statement->fetch();
    return $structure ?: null;
}

function fetch_student_fee_discount(PDO $pdo, int $studentId, string $academicYear, string $term): ?array
{
    $statement = $pdo->prepare(
        "SELECT * FROM student_fee_discounts WHERE student_id = :student_id AND academic_year = :academic_year AND term = :term LIMIT 1"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $row = $statement->fetch();
    return $row ?: null;
}

function save_student_fee_discount(PDO $pdo, int $studentId, string $academicYear, string $term, float $originalFee, float $discountPercentage, float $discountedFee): void
{
    $existing = fetch_student_fee_discount($pdo, $studentId, $academicYear, $term);
    if ($existing) {
        $statement = $pdo->prepare(
            "UPDATE student_fee_discounts
             SET original_fee = :original_fee,
                 discount_percentage = :discount_percentage,
                 discounted_fee = :discounted_fee,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $statement->execute([
            'original_fee' => $originalFee,
            'discount_percentage' => $discountPercentage,
            'discounted_fee' => $discountedFee,
            'id' => $existing['id'],
        ]);
        return;
    }

    $statement = $pdo->prepare(
        "INSERT INTO student_fee_discounts (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee)
         VALUES (:student_id, :academic_year, :term, :original_fee, :discount_percentage, :discounted_fee)"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
        'original_fee' => $originalFee,
        'discount_percentage' => $discountPercentage,
        'discounted_fee' => $discountedFee,
    ]);
}

function remove_student_fee_discounts(PDO $pdo, int $studentId, string $academicYear): void
{
    $statement = $pdo->prepare(
        "DELETE FROM student_fee_discounts WHERE student_id = :student_id AND academic_year = :academic_year"
    );
    $statement->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
    ]);
}

function default_discounted_fee(float $originalFee, float $discountPercentage): float
{
    if ($discountPercentage < 0) {
        $discountPercentage = 0;
    }
    if ($discountPercentage > 100) {
        $discountPercentage = 100;
    }
    return round($originalFee * (100 - $discountPercentage) / 100, 2);
}

function fee_required_amount_for_term(array $structure, string $term): float
{
    $columns = fee_structure_term_columns();

    if (!isset($columns[$term])) {
        throw new InvalidArgumentException('Invalid academic term.');
    }

    return (float) $structure[$columns[$term]];
}

function default_academic_calendar_rows(string $academicYear): array
{
    return [
        [
            'academic_year' => $academicYear,
            'term_name' => 'Term 1',
            'start_date' => "{$academicYear}-01-01",
            'end_date' => "{$academicYear}-04-30",
        ],
        [
            'academic_year' => $academicYear,
            'term_name' => 'Term 2',
            'start_date' => "{$academicYear}-05-01",
            'end_date' => "{$academicYear}-08-31",
        ],
        [
            'academic_year' => $academicYear,
            'term_name' => 'Term 3',
            'start_date' => "{$academicYear}-09-01",
            'end_date' => "{$academicYear}-12-31",
        ],
    ];
}

function academic_table_exists(PDO $pdo, string $tableName): bool
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
        throw new InvalidArgumentException('Invalid academic table name.');
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table_name");
        $statement->execute(['table_name' => $tableName]);
        return (int) $statement->fetchColumn() > 0;
    }
    if ($driver === 'mysql') {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
        $statement->execute(['table_name' => $tableName]);
        return (int) $statement->fetchColumn() > 0;
    }

    return false;
}

function ensure_academic_calendar_for_year(PDO $pdo, string $academicYear): void
{
    if (!preg_match('/^\d{4}$/', $academicYear)) {
        return;
    }

    if (!academic_table_exists($pdo, 'academic_calendar')) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE academic_calendar (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    academic_year TEXT NOT NULL,
                    term_name TEXT NOT NULL,
                    start_date TEXT NOT NULL,
                    end_date TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (academic_year, term_name)
                )"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE academic_calendar (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    academic_year VARCHAR(4) NOT NULL,
                    term_name VARCHAR(20) NOT NULL,
                    start_date DATE NOT NULL,
                    end_date DATE NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)
                ) ENGINE=InnoDB"
            );
        }
    }

    $insertPrefix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $insert = $pdo->prepare(
        "{$insertPrefix} INTO academic_calendar (academic_year, term_name, start_date, end_date)
         VALUES (:academic_year, :term_name, :start_date, :end_date)"
    );

    $existingRows = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_calendar
         WHERE academic_year = :academic_year"
    );
    $existingRows->execute(['academic_year' => $academicYear]);
    if ((int) $existingRows->fetchColumn() > 0) {
        return;
    }

    foreach (default_academic_calendar_rows($academicYear) as $row) {
        $insert->execute($row);
    }
}

function ensure_academic_settings_table(PDO $pdo): void
{
    if (academic_table_exists($pdo, 'academic_settings')) {
        return;
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE academic_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE academic_settings (
            setting_key VARCHAR(60) PRIMARY KEY,
            setting_value VARCHAR(100) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
}

function academic_setting_value(PDO $pdo, string $key): ?string
{
    ensure_academic_settings_table($pdo);

    $statement = $pdo->prepare(
        "SELECT setting_value
         FROM academic_settings
         WHERE setting_key = :setting_key
         LIMIT 1"
    );
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
}

function save_academic_setting(PDO $pdo, string $key, string $value): void
{
    ensure_academic_settings_table($pdo);

    $upsert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
        : 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
    $statement = $pdo->prepare(
        "INSERT INTO academic_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         {$upsert}"
    );
    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function set_current_academic_context(PDO $pdo, string $academicYear, string $term): void
{
    if (!preg_match('/^\d{4}$/', $academicYear)) {
        throw new InvalidArgumentException('Academic year must be four digits.');
    }
    if (!in_array($term, term_options(), true)) {
        throw new InvalidArgumentException('Choose a valid term.');
    }

    ensure_academic_calendar_for_year($pdo, $academicYear);
    $termExists = $pdo->prepare(
        "SELECT COUNT(*)
         FROM academic_calendar
         WHERE academic_year = :academic_year
           AND term_name = :term"
    );
    $termExists->execute([
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    if ((int) $termExists->fetchColumn() === 0) {
        throw new InvalidArgumentException("Add {$academicYear} {$term} to the academic calendar before activating it.");
    }

    save_academic_setting($pdo, 'active_academic_year', $academicYear);
    save_academic_setting($pdo, 'active_term', $term);
}

function active_academic_context_from_settings(PDO $pdo, string $today): ?array
{
    $academicYear = academic_setting_value($pdo, 'active_academic_year');
    $term = academic_setting_value($pdo, 'active_term');

    if (!$academicYear || !$term || !preg_match('/^\d{4}$/', $academicYear) || !in_array($term, term_options(), true)) {
        return null;
    }

    ensure_academic_calendar_for_year($pdo, $academicYear);
    $statement = $pdo->prepare(
        "SELECT start_date, end_date
         FROM academic_calendar
         WHERE academic_year = :academic_year
           AND term_name = :term
         LIMIT 1"
    );
    $statement->execute([
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $calendar = $statement->fetch() ?: [];

    return [
        'academic_year' => $academicYear,
        'term' => $term,
        'start_date' => $calendar['start_date'] ?? null,
        'end_date' => $calendar['end_date'] ?? null,
        'today' => $today,
    ];
}

function active_academic_context(PDO $pdo, ?string $date = null): array
{
    $today = $date ?: date('Y-m-d');
    $academicYear = date('Y', strtotime($today));
    ensure_academic_calendar_for_year($pdo, $academicYear);
    $activeContext = active_academic_context_from_settings($pdo, $today);
    if ($activeContext) {
        return $activeContext;
    }

    $statement = $pdo->prepare(
        "SELECT academic_year, term_name, start_date, end_date
         FROM academic_calendar
         WHERE :today BETWEEN start_date AND end_date
         ORDER BY start_date DESC, id DESC
         LIMIT 1"
    );
    $statement->execute(['today' => $today]);
    $calendar = $statement->fetch();
    if (!$calendar) {
        $calendar = [
            'academic_year' => $academicYear,
            'term_name' => fallback_term_for_date($today),
            'start_date' => null,
            'end_date' => null,
        ];
    }
    return [
        'academic_year' => (string) $calendar['academic_year'],
        'term' => (string) $calendar['term_name'],
        'start_date' => $calendar['start_date'],
        'end_date' => $calendar['end_date'],
        'today' => $today,
    ];
}

function set_academic_view_context(PDO $pdo, string $academicYear, string $term): void
{
    if (!preg_match('/^\d{4}$/', $academicYear) || !in_array($term, term_options(), true)) {
        throw new InvalidArgumentException('Choose a valid academic year and term to view.');
    }
    $period = $pdo->prepare('SELECT COUNT(*) FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term');
    $period->execute(['academic_year' => $academicYear, 'term' => $term]);
    if ((int) $period->fetchColumn() === 0) {
        throw new InvalidArgumentException('That academic period does not exist in the calendar.');
    }

    $active = active_academic_context($pdo);
    if ($active['academic_year'] === $academicYear && $active['term'] === $term) {
        unset($_SESSION['academic_view_year'], $_SESSION['academic_view_term']);
        return;
    }
    $_SESSION['academic_view_year'] = $academicYear;
    $_SESSION['academic_view_term'] = $term;
}

function clear_academic_view_context(): void
{
    unset($_SESSION['academic_view_year'], $_SESSION['academic_view_term']);
}

function academic_view_is_historical(PDO $pdo): bool
{
    $year = (string) ($_SESSION['academic_view_year'] ?? '');
    $term = (string) ($_SESSION['academic_view_term'] ?? '');
    if ($year === '' || $term === '') {
        return false;
    }
    $active = active_academic_context($pdo);
    return $year !== $active['academic_year'] || $term !== $active['term'];
}

function academic_view_year_options(PDO $pdo): array
{
    $rows = $pdo->query('SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC')->fetchAll(PDO::FETCH_COLUMN);
    $active = active_academic_context($pdo);
    $years = array_values(array_unique(array_map('strval', $rows)));
    if (!in_array($active['academic_year'], $years, true)) {
        $years[] = $active['academic_year'];
    }
    rsort($years, SORT_NUMERIC);
    return $years;
}

function academic_view_terms(PDO $pdo, string $academicYear): array
{
    $statement = $pdo->prepare("SELECT term_name FROM academic_calendar WHERE academic_year = :academic_year ORDER BY FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')");
    $statement->execute(['academic_year' => $academicYear]);
    return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
}

function fallback_term_for_date(string $date): string
{
    $month = (int) date('n', strtotime($date));

    if ($month >= 1 && $month <= 4) {
        return 'Term 1';
    }
    if ($month >= 5 && $month <= 8) {
        return 'Term 2';
    }

    return 'Term 3';
}

function valid_date_value(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function valid_money_value(string $value): bool
{
    return preg_match('/^\d+(\.\d{1,2})?$/', trim($value)) === 1;
}

function valid_quantity_value(string $value): bool
{
    return preg_match('/^\d+(\.\d+)?$/', trim($value)) === 1;
}

function current_academic_context(PDO $pdo, ?string $date = null): array
{
    ensure_student_class_history_schema($pdo);
    $active = active_academic_context($pdo, $date);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return $active;
    }

    $viewYear = (string) ($_SESSION['academic_view_year'] ?? '');
    $viewTerm = (string) ($_SESSION['academic_view_term'] ?? '');
    if (!preg_match('/^\d{4}$/', $viewYear) || !in_array($viewTerm, term_options(), true)) {
        clear_academic_view_context();
        return $active;
    }
    $statement = $pdo->prepare(
        'SELECT academic_year, term_name, start_date, end_date
         FROM academic_calendar WHERE academic_year = :academic_year AND term_name = :term LIMIT 1'
    );
    $statement->execute(['academic_year' => $viewYear, 'term' => $viewTerm]);
    $calendar = $statement->fetch();
    if (!$calendar) {
        clear_academic_view_context();
        return $active;
    }
    return [
        'academic_year' => (string) $calendar['academic_year'],
        'term' => (string) $calendar['term_name'],
        'start_date' => $calendar['start_date'],
        'end_date' => $calendar['end_date'],
        'today' => $date ?: date('Y-m-d'),
    ];
}

function permission_definitions(): array
{
    return rbac_permission_definitions();
}

function permission_template_definitions(): array
{
    $templates = [];
    foreach (rbac_role_template_definitions() as $templateName => $template) {
        $templates[$templateName] = rbac_permission_template_keys($templateName);
    }
    return $templates;
}

function get_all_permission_keys(): array
{
    return rbac_all_permission_keys(true);
}

function ensure_permissions_exist(PDO $pdo): void
{
    rbac_ensure_schema($pdo);
}

function get_permission_ids(PDO $pdo, array $permissionKeys): array
{
    return rbac_get_permission_ids($pdo, $permissionKeys);
}

function find_or_create_role(PDO $pdo, string $name, string $description = ''): int
{
    return rbac_find_or_create_role($pdo, $name, $description);
}

function set_role_permissions(PDO $pdo, int $roleId, array $permissionKeys): void
{
    rbac_set_role_permissions($pdo, $roleId, $permissionKeys);
}

function set_user_permissions(PDO $pdo, int $userId, array $permissionKeys): void
{
    rbac_set_user_overrides($pdo, $userId, $permissionKeys);
}

function set_user_role(PDO $pdo, int $userId, int $roleId): void
{
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id")->execute(['user_id' => $userId]);
    $statement = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
    $statement->execute(['user_id' => $userId, 'role_id' => $roleId]);
}

function get_user_role_names(PDO $pdo, int $userId): array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->prepare(
        "SELECT COALESCE(roles.role_name, roles.name) AS name
         FROM roles
         JOIN user_roles ON user_roles.role_id = roles.id
         WHERE user_roles.user_id = :user_id"
    );
    $statement->execute(['user_id' => $userId]);
    return array_column($statement->fetchAll(), 'name');
}

function get_user_permission_keys(PDO $pdo, int $userId): array
{
    return rbac_user_permission_keys($pdo, $userId);
}

function user_has_permission(PDO $pdo, int $userId, string $permissionKey): bool
{
    return rbac_user_has_permission($pdo, $userId, $permissionKey);
}

function get_role_permission_keys(PDO $pdo, int $roleId): array
{
    return rbac_role_permission_keys($pdo, $roleId);
}

function get_role_permissions_by_id(PDO $pdo, int $roleId): array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->prepare(
        "SELECT p.id, p.permission_key, p.module_name, p.permission_name
         FROM permissions p
         JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = :role_id
         ORDER BY p.permission_key ASC"
    );
    $statement->execute(['role_id' => $roleId]);

    return $statement->fetchAll();
}

function find_permission_template_keys(string $templateName): array
{
    return rbac_permission_template_keys($templateName);
}

function ensure_role_templates_exist(PDO $pdo): void
{
    // Keep permission records available without recreating roles an admin has deleted.
    ensure_permissions_exist($pdo);
}

function get_stored_role_templates(PDO $pdo): array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->query("SELECT id, COALESCE(role_name, name) AS name, description
                              FROM roles
                              WHERE LOWER(TRIM(COALESCE(NULLIF(role_name, ''), name))) <> 'parent'
                              ORDER BY COALESCE(role_name, name) ASC");
    return $statement->fetchAll();
}

function get_role_by_name(PDO $pdo, string $name): ?array
{
    ensure_permissions_exist($pdo);
    $statement = $pdo->prepare("SELECT id, COALESCE(role_name, name) AS name, description FROM roles WHERE role_name = :role_name OR name = :legacy_name LIMIT 1");
    $statement->execute(['role_name' => $name, 'legacy_name' => $name]);
    $row = $statement->fetch();
    return $row ?: null;
}

function get_user_by_id(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare("SELECT id, name, username, status, class_level FROM admin WHERE id = :user_id LIMIT 1");
    $statement->execute(['user_id' => $userId]);
    return $statement->fetch() ?: null;
}

function get_admin_users(PDO $pdo): array
{
    ensure_permissions_exist($pdo);
    $parentAccountCondition = parent_account_identity_condition($pdo, 'a');
    $sql = "SELECT a.id, a.name, a.username, a.status, a.class_level,
                   (SELECT COALESCE(r.role_name, r.name) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = a.id LIMIT 1) AS role_name
            FROM admin a
            WHERE NOT {$parentAccountCondition}
            ORDER BY a.name ASC";
    $statement = $pdo->query($sql);
    return $statement->fetchAll();
}

function parent_account_identity_condition(PDO $pdo, string $userAlias = 'a'): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $userAlias) !== 1) {
        throw new InvalidArgumentException('Invalid SQL alias for parent account lookup.');
    }

    $conditions = [
        "LOWER(SUBSTR({$userAlias}.username, 1, 7)) = 'parent_'",
        "EXISTS (
            SELECT 1
            FROM user_roles parent_ur
            JOIN roles parent_role ON parent_role.id = parent_ur.role_id
            WHERE parent_ur.user_id = {$userAlias}.id
              AND LOWER(TRIM(COALESCE(NULLIF(parent_role.role_name, ''), parent_role.name))) = 'parent'
        )",
    ];

    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $tableStatement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('parent_profiles', 'parent_student_links')");
            $parentTables = array_fill_keys(array_column($tableStatement->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        } else {
            $tableStatement = $pdo->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name IN ('parent_profiles', 'parent_student_links')"
            );
            $parentTables = array_fill_keys(array_column($tableStatement->fetchAll(PDO::FETCH_ASSOC), 'table_name'), true);
        }
        if (isset($parentTables['parent_profiles'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM parent_profiles pp WHERE pp.parent_user_id = {$userAlias}.id)";
        }
        if (isset($parentTables['parent_student_links'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM parent_student_links pl WHERE pl.parent_user_id = {$userAlias}.id)";
        }
    } catch (Throwable $exception) {
        // Keep staff administration available if the optional parent tables are not installed.
    }

    return '(' . implode(' OR ', $conditions) . ')';
}

function is_parent_account_user(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $parentAccountCondition = parent_account_identity_condition($pdo, 'a');
    $statement = $pdo->prepare("SELECT 1 FROM admin a WHERE a.id = :user_id AND {$parentAccountCondition} LIMIT 1");
    $statement->execute(['user_id' => $userId]);
    return (bool) $statement->fetchColumn();
}

function get_user_role_template_name(PDO $pdo, int $userId): string
{
    $roleNames = get_user_role_names($pdo, $userId);
    if (!empty($roleNames)) {
        return $roleNames[0];
    }

    // If no explicit role assigned, try to infer from permission templates
    $userPermissions = get_user_permission_keys($pdo, $userId);
    if (!empty($userPermissions)) {
        $templates = permission_template_definitions();
        foreach ($templates as $templateName => $permKeys) {
            // Compare sets
            $permKeysSorted = array_values(array_unique($permKeys));
            sort($permKeysSorted);
            $userPermsSorted = $userPermissions;
            sort($userPermsSorted);
            if ($permKeysSorted === $userPermsSorted) {
                return $templateName;
            }
        }
    }

    return 'Unassigned';
}

function get_user_class_level(PDO $pdo, int $userId): ?string
{
    $user = get_user_by_id($pdo, $userId);
    return $user['class_level'] ?? null;
}

function current_admin_class_level(PDO $pdo): ?string
{
    return get_user_class_level($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function get_active_user_status(PDO $pdo, int $userId): ?string
{
    $statement = $pdo->prepare("SELECT status FROM admin WHERE id = :user_id LIMIT 1");
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();
    return $row['status'] ?? null;
}

function user_is_active(PDO $pdo, int $userId): bool
{
    $status = get_active_user_status($pdo, $userId);
    return $status === 'Active';
}

function current_admin_has_permission(PDO $pdo, string $permissionKey): bool
{
    return rbac_current_admin_has_permission($pdo, $permissionKey);
}

function get_permission_label_by_key(string $permissionKey): string
{
    return rbac_permission_label($permissionKey);
}

function sanitize_user_permissions(array $permissionKeys): array
{
    $available = get_all_permission_keys();
    $normalized = [];
    foreach ($permissionKeys as $permissionKey) {
        $key = rbac_normalize_permission_key((string) $permissionKey);
        if ($key !== null) {
            $normalized[] = $key;
        }
    }
    return array_values(array_intersect($available, array_unique($normalized)));
}

function save_admin_user(PDO $pdo, array $data): int
{
    

    if (!empty($data['id'])) {
        $sql = "UPDATE admin SET name = :name, username = :username, status = :status";
        $params = [
            'name' => $data['name'],
            'username' => $data['username'],
            'status' => $data['status'],
            'id' => $data['id'],
        ];
        if (!empty($data['password_hash'])) {
            $sql .= ", password_hash = :password_hash";
            $params['password_hash'] = $data['password_hash'];
        }
        if (array_key_exists('class_level', $data)) {
            $sql .= ", class_level = :class_level";
            $params['class_level'] = $data['class_level'] !== '' ? $data['class_level'] : null;
        }
        $sql .= " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $data['id'];
    }

    $columns = ['name', 'username', 'password_hash', 'status'];
    $placeholders = [':name', ':username', ':password_hash', ':status'];
    $params = [
        'name' => $data['name'],
        'username' => $data['username'],
        'password_hash' => $data['password_hash'],
        'status' => $data['status'],
    ];
    if (array_key_exists('class_level', $data)) {
        $columns[] = 'class_level';
        $placeholders[] = ':class_level';
        $params['class_level'] = $data['class_level'] !== '' ? $data['class_level'] : null;
    }

    $stmt = $pdo->prepare("INSERT INTO admin (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

function delete_admin_user(PDO $pdo, int $userId): void
{
    ensure_permissions_exist($pdo);
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM user_permission_overrides WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM user_module_overrides WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $pdo->prepare('DELETE FROM admin WHERE id = :id')->execute(['id' => $userId]);
}

function get_user_by_username(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    return $stmt->fetch() ?: null;
}

function get_user_status_options(): array
{
    return ['Active', 'Inactive'];
}

function get_permission_template_labels(): array
{
    return array_keys(permission_template_definitions());
}

function get_user_roles_for_user(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function get_permission_template_permissions(PDO $pdo, string $roleName): array
{
    // Prefer stored role permissions (what admin explicitly assigned).
    // Only fall back to built-in template defaults when there is no stored role.
    $role = get_role_by_name($pdo, $roleName);
    if ($role) {
        return get_role_permission_keys($pdo, (int) $role['id']);
    }

    // No stored role found — use template defaults if defined.
    if (array_key_exists($roleName, permission_template_definitions())) {
        return find_permission_template_keys($roleName);
    }

    return [];
}

function get_permission_lookup(): array
{
    $definitions = [];
    foreach (permission_definitions() as $moduleName => $module) {
        $definitions[$module['permission_key']] = $module['label'];
        foreach ($module['actions'] as $actionKey => $label) {
            $definitions[$actionKey] = $label;
        }
    }
    return $definitions;
}

function get_permission_options(): array
{
    return permission_definitions();
}

function get_permission_template_dropdown(PDO $pdo): array
{
    ensure_permissions_exist($pdo);
    $storedRoles = array_values(array_filter(
        array_column(get_stored_role_templates($pdo), 'name'),
        static fn(string $name): bool => strcasecmp(trim($name), 'Daycare Caregiver') !== 0
    ));
    $builtInRoles = array_values(array_filter(
        array_keys(rbac_role_template_definitions()),
        static fn(string $name): bool => strcasecmp($name, 'Parent') !== 0
            && strcasecmp($name, 'Daycare Caregiver') !== 0
    ));

    return array_values(array_unique(array_merge($builtInRoles, $storedRoles)));
}

function get_permission_module_action_labels(string $moduleName): array
{
    return permission_definitions()[$moduleName]['actions'] ?? [];
}

function get_permission_module_access_keys(): array
{
    $keys = [];
    foreach (permission_definitions() as $module) {
        $keys[] = $module['permission_key'];
    }
    return $keys;
}

function get_permission_action_names(): array
{
    $actions = [];
    foreach (permission_definitions() as $module) {
        foreach ($module['actions'] as $actionKey => $label) {
            $actions[$actionKey] = $label;
        }
    }
    return $actions;
}

function get_permission_template_keys(string $templateName): array
{
    return find_permission_template_keys($templateName);
}

function get_permission_management_link(): string
{
    return 'admin/users_permissions.php';
}

function get_permission_management_label(): string
{
    return 'Users & Permissions';
}

function get_permission_user_management_description(): string
{
    return 'Manage administrator users, role templates, and custom permissions.';
}

function get_permission_settings_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function get_user_permissions_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function get_permission_template_choices(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function current_admin_permission_keys(PDO $pdo): array
{
    return empty($_SESSION['admin_id']) ? [] : get_user_permission_keys($pdo, (int) $_SESSION['admin_id']);
}

function current_admin_is_active(PDO $pdo): bool
{
    return !empty($_SESSION['admin_id']) && user_is_active($pdo, (int) $_SESSION['admin_id']);
}

function current_admin_roles(PDO $pdo): array
{
    return empty($_SESSION['admin_id']) ? [] : get_user_role_names($pdo, (int) $_SESSION['admin_id']);
}

function get_permission_role_by_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function current_admin_displays_all_permissions(PDO $pdo): bool
{
    return get_user_permission_keys($pdo, (int) ($_SESSION['admin_id'] ?? 0)) === get_all_permission_keys();
}

function get_user_role_assignments(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function clear_user_role_assignments(PDO $pdo, int $userId): void
{
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id")->execute(['user_id' => $userId]);
}

function clear_custom_user_permissions(PDO $pdo, int $userId): void
{
    ensure_permissions_exist($pdo);
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :user_id")->execute(['user_id' => $userId]);
    $pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id = :user_id")->execute(['user_id' => $userId]);
    $pdo->prepare("DELETE FROM user_module_overrides WHERE user_id = :user_id")->execute(['user_id' => $userId]);
}

function assign_user_role_by_name(PDO $pdo, int $userId, string $roleName): void
{
    ensure_permissions_exist($pdo);
    $roleId = find_or_create_role($pdo, $roleName, ucfirst($roleName) . ' role template');
    $moduleRows = $pdo->prepare('SELECT COUNT(*) FROM module_access WHERE role_id = :role_id');
    $moduleRows->execute(['role_id' => $roleId]);
    $permissionRows = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = :role_id');
    $permissionRows->execute(['role_id' => $roleId]);
    if ((int) $moduleRows->fetchColumn() === 0 && (int) $permissionRows->fetchColumn() === 0) {
        $templateKeys = rbac_permission_template_keys($roleName);
        if ($templateKeys) {
            set_role_permissions($pdo, $roleId, $templateKeys);
        }
    }
    set_user_role($pdo, $userId, $roleId);
}

function get_permission_template_details(PDO $pdo, string $roleName): array
{
    return get_permission_template_permissions($pdo, $roleName);
}

function get_permission_template_options(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_label(string $templateName): string
{
    return $templateName;
}

function get_permission_lookup_map(): array
{
    return get_permission_lookup();
}

function get_permission_module_permissions_for_template(string $templateName): array
{
    return get_permission_template_permissions($pdo, $templateName);
}

function permission_label(string $permissionKey): string
{
    return get_permission_label_by_key($permissionKey);
}

function get_user_permission_names(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function role_exists(PDO $pdo, string $roleName): bool
{
    return (bool) get_role_by_name($pdo, $roleName);
}

function get_permission_page_title(): string
{
    return 'Users & Permissions';
}

function get_permission_page_description(): string
{
    return 'Create admin users, assign a role template, or configure custom access.';
}

function get_permission_help_text(): string
{
    return 'Use role templates or custom permissions to control module visibility and actions.';
}

function get_user_permission_summary(PDO $pdo, int $userId): string
{
    return implode(', ', get_user_permission_keys($pdo, $userId));
}

function get_user_status_options_list(): array
{
    return get_user_status_options();
}

function get_permission_template_name_list(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_label_list(): array
{
    return get_permission_template_labels();
}

function get_permission_templates_for_form(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_user_template(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_status(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_user_roles_dropdown(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_user_role_template_list(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_user_roles_for_select(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_select_options(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_select_labels(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_template_names(): array
{
    return get_permission_template_labels();
}

function get_user_permission_template_names(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_action_template_labels(): array
{
    return get_permission_action_names();
}

function get_permission_module_template_labels(): array
{
    return get_permission_access_labels();
}

function get_permission_template_help(): string
{
    return 'Role templates automatically assign module and action permissions.';
}

function get_permission_template_role_help(): string
{
    return 'Select a role template to assign common permissions quickly.';
}

function get_permission_custom_help(): string
{
    return 'Choose Custom to assign permissions per module and action.';
}

function get_permission_user_help(): string
{
    return 'Administrators may be active or inactive, and can be assigned roles or custom permissions.';
}

function get_permission_status_help(): string
{
    return 'Inactive users cannot log in until reactivated.';
}

function get_permission_section_help_text(): string
{
    return 'Permissions are split into module visibility plus action-level access.';
}

function get_permission_page_help_text(): string
{
    return 'Use this page to manage users, permission templates, and module access.';
}

function get_permission_section_title_text(): string
{
    return 'Permission Sections';
}

function get_permission_template_title_text(): string
{
    return 'Role Templates';
}

function get_permission_user_title_text(): string
{
    return 'Admin Users';
}

function get_permission_status_title_text(): string
{
    return 'User Status';
}

function get_permission_role_title_text(): string
{
    return 'Assigned Role';
}

function get_permission_custom_title_text(): string
{
    return 'Custom Permission Set';
}

function get_permission_module_title_text(): string
{
    return 'Module Access';
}

function get_permission_action_title_text(): string
{
    return 'Action Access';
}

function get_permission_template_section_title_text(): string
{
    return 'Role Template Selection';
}

function get_permission_user_section_title_text(): string
{
    return 'User Details';
}

function get_permission_template_section_description(): string
{
    return 'Choose a template or save a custom set as a template.';
}

function get_permission_section_summary(): string
{
    return 'Permission sections control module access and actions.';
}

function get_permission_action_summary(): string
{
    return 'Action permissions are assigned within each visible module.';
}

function get_permission_template_summary(): string
{
    return 'Built-in role templates make common assignments simple.';
}

function get_permission_user_summary_text(): string
{
    return 'Users are administrators with role assignments and custom permissions.';
}

function get_permission_status_summary(): string
{
    return 'Active users may log in; inactive users are blocked.';
}

function get_permission_configuration_summary(): string
{
    return 'Permissions are stored in tables and evaluated at runtime.';
}

function get_permission_system_summary_text(): string
{
    return 'Permissions apply to sidebar visibility and page actions.';
}

function get_permission_template_system_summary(): string
{
    return 'Templates are reusable permission sets.';
}

function get_permission_user_system_summary(): string
{
    return 'Users are assigned templates or custom permissions.';
}

function get_permission_module_system_summary(): string
{
    return 'Modules are shown based on access permissions.';
}

function get_permission_action_system_summary(): string
{
    return 'Actions are enabled only when permitted.';
}

function current_admin_can_access_module(PDO $pdo, string $moduleKeyOrName): bool
{
    return rbac_current_admin_can_access_module($pdo, $moduleKeyOrName);
}

function current_admin_module_permissions(PDO $pdo): array
{
    return current_admin_permission_keys($pdo);
}

function current_admin_permission_map(PDO $pdo): array
{
    return get_permission_lookup();
}

function current_admin_role_permissions(PDO $pdo): array
{
    return get_user_permission_keys($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function current_admin_permission_role_names(PDO $pdo): array
{
    return current_admin_roles($pdo);
}

function current_admin_permission_template_name(PDO $pdo): string
{
    return get_user_role_template_name($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function current_admin_permission_status(PDO $pdo): string
{
    return current_admin_is_active($pdo) ? 'Active' : 'Inactive';
}

function get_permission_action_label(string $permissionKey): string
{
    return get_permission_label_by_key($permissionKey);
}

function get_permission_module_list_for_display(): array
{
    return permission_definitions();
}

function get_permission_action_list_for_display_row(): array
{
    return get_permission_action_names();
}

function get_permission_template_list_for_display_row(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_user_list_for_display_row(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_user_permissions_details(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_roles_details(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function get_permission_user_status_details(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_info_details(PDO $pdo, int $userId): array
{
    return get_user_by_id($pdo, $userId) ?: [];
}

function get_permission_templates_for_user(PDO $pdo, int $userId): array
{
    return get_permission_template_labels();
}

function get_permission_actions_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_modules_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_template_for_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_status_for_user(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_name_for_user(PDO $pdo, int $userId): string
{
    return get_user_by_id($pdo, $userId)['name'] ?? '';
}

function get_permission_user_username_for_user(PDO $pdo, int $userId): string
{
    return get_user_by_id($pdo, $userId)['username'] ?? '';
}

function get_permission_user_role_template_for_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_permission_list_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_role_list_for_user(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function get_permission_user_status_list_for_user(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_info_list_for_user(PDO $pdo, int $userId): array
{
    return get_user_by_id($pdo, $userId) ?: [];
}

function get_permission_user_management_overview_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_details($pdo, $userId);
}

function get_permission_settings_access_for_user(PDO $pdo, int $userId): bool
{
    return user_has_permission($pdo, $userId, 'settings.view');
}

function get_permission_user_management_access_for_user(PDO $pdo, int $userId): bool
{
    return user_has_permission($pdo, $userId, 'users_permissions.access');
}

function student_fee_sponsorship_status(PDO $pdo, int $studentId, string $academicYear): string
{
    if ($studentId <= 0 || !preg_match('/^\d{4}$/', $academicYear)) {
        return 'Regular Student';
    }

    try {
        $statement = $pdo->prepare(
            'SELECT sponsorship_status
             FROM student_fee_sponsorship_history
             WHERE student_id = :student_id AND academic_year <= :academic_year
             ORDER BY academic_year DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['student_id' => $studentId, 'academic_year' => $academicYear]);
        $status = $statement->fetchColumn();
    } catch (PDOException $exception) {
        $status = false;
    }

    if ($status !== false && $status !== null && $status !== '') {
        return $status === 'Sponsored Student' ? 'Sponsored Student' : 'Regular Student';
    }

    try {
        $statement = $pdo->prepare('SELECT fee_sponsorship_status FROM students WHERE id = :student_id LIMIT 1');
        $statement->execute(['student_id' => $studentId]);
        return $statement->fetchColumn() === 'Sponsored Student' ? 'Sponsored Student' : 'Regular Student';
    } catch (PDOException $exception) {
        // Databases predating sponsorship use the regular-student default.
        return 'Regular Student';
    }
}

function student_fee_sponsorship_status_sql(string $studentAlias, string $yearExpression): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $studentAlias)
        || !preg_match('/^(?::[a-zA-Z_][a-zA-Z0-9_]*|[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*)$/', $yearExpression)) {
        throw new InvalidArgumentException('Invalid sponsorship query reference.');
    }

    return "COALESCE((SELECT sponsorship.sponsorship_status
        FROM student_fee_sponsorship_history sponsorship
        WHERE sponsorship.student_id = {$studentAlias}.id
          AND sponsorship.academic_year <= {$yearExpression}
        ORDER BY sponsorship.academic_year DESC, sponsorship.id DESC
        LIMIT 1), NULLIF({$studentAlias}.fee_sponsorship_status, ''), 'Regular Student')";
}

function student_fee_not_sponsored_sql(string $studentAlias, string $yearExpression): string
{
    return '(' . student_fee_sponsorship_status_sql($studentAlias, $yearExpression) . " <> 'Sponsored Student')";
}

function fee_balance_sync_assessment(PDO $pdo, int $studentId, string $academicYear, string $term, float $originalFee): bool
{
    if (student_fee_sponsorship_status($pdo, $studentId, $academicYear) === 'Sponsored Student') {
        return false;
    }

    $discount = fetch_student_fee_discount($pdo, $studentId, $academicYear, $term);
    $discountPercentage = $discount ? min(100.0, max(0.0, (float) $discount['discount_percentage'])) : 0.0;
    $discountedFee = $originalFee;
    if ($discount) {
        $savedOriginalFee = (float) ($discount['original_fee'] ?? 0);
        $savedDiscountedFee = (float) ($discount['discounted_fee'] ?? 0);
        $discountedFee = abs($savedOriginalFee - $originalFee) <= 0.005
            ? min($originalFee, max(0.0, $savedDiscountedFee))
            : default_discounted_fee($originalFee, $discountPercentage);
    }

    $balanceStmt = $pdo->prepare(
        "SELECT id, original_fee, discount_percentage, discounted_fee, required_amount, paid_amount, balance
         FROM fee_balances
         WHERE student_id = :student_id AND academic_year = :academic_year AND term = :term
         LIMIT 1"
    );
    $balanceStmt->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $existing = $balanceStmt->fetch();

    if (!$existing) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO fee_balances
                (student_id, academic_year, term, original_fee, discount_percentage, discounted_fee, required_amount, paid_amount, balance)
             VALUES
                (:student_id, :academic_year, :term, :original_fee, :discount_percentage, :discounted_fee, :required_amount, 0.00, :balance)"
        );
        $insertStmt->execute([
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'term' => $term,
            'original_fee' => $originalFee,
            'discount_percentage' => $discountPercentage,
            'discounted_fee' => $discountedFee,
            'required_amount' => $discountedFee,
            'balance' => $discountedFee,
        ]);
        return true;
    }

    $hasExistingDiscount = (float) ($existing['discount_percentage'] ?? 0) > 0.005;
    $hasCurrentDiscount = $discountPercentage > 0.005;
    if ($hasExistingDiscount || $hasCurrentDiscount) {
        $paidAmount = (float) $existing['paid_amount'];
        $updatedBalance = max($discountedFee - $paidAmount, 0.0);
        $discountUpdate = $pdo->prepare(
            "UPDATE fee_balances
             SET original_fee = :original_fee,
                 discount_percentage = :discount_percentage,
                 discounted_fee = :discounted_fee,
                 required_amount = :required_amount,
                 balance = :balance
             WHERE id = :id
               AND ABS(required_amount - :old_required) <= 0.005
               AND ABS(paid_amount - :old_paid) <= 0.005
               AND ABS(balance - :old_balance) <= 0.005"
        );
        $discountUpdate->execute([
            'original_fee' => $originalFee,
            'discount_percentage' => $discountPercentage,
            'discounted_fee' => $discountedFee,
            'required_amount' => $discountedFee,
            'balance' => $updatedBalance,
            'id' => $existing['id'],
            'old_required' => $existing['required_amount'],
            'old_paid' => $existing['paid_amount'],
            'old_balance' => $existing['balance'],
        ]);
        return $discountUpdate->rowCount() > 0;
    }

    if (abs((float) $existing['required_amount']) > 0.005
        || abs((float) $existing['paid_amount']) > 0.005
        || abs((float) $existing['balance']) > 0.005) {
        return false;
    }

    // Only repair an untouched zero snapshot. The conditional update also protects against a payment being recorded concurrently.
    $updateStmt = $pdo->prepare(
        "UPDATE fee_balances
         SET original_fee = :original_fee,
             discount_percentage = :discount_percentage,
             discounted_fee = :discounted_fee,
             required_amount = :required_amount,
             paid_amount = 0.00,
             balance = :balance
         WHERE id = :id
           AND ABS(required_amount) <= 0.005
           AND ABS(paid_amount) <= 0.005
           AND ABS(balance) <= 0.005
           AND NOT EXISTS (
               SELECT 1 FROM fees recorded_fee
               WHERE recorded_fee.student_id = :ledger_student_id
                 AND recorded_fee.year = :ledger_year
                 AND recorded_fee.term = :ledger_term
                 AND recorded_fee.payment_status = 'Recorded'
           )"
    );
    $updateStmt->execute([
        'original_fee' => $originalFee,
        'discount_percentage' => $discountPercentage,
        'discounted_fee' => $discountedFee,
        'required_amount' => $discountedFee,
        'balance' => $discountedFee,
        'id' => $existing['id'],
        'ledger_student_id' => $studentId,
        'ledger_year' => $academicYear,
        'ledger_term' => $term,
    ]);

    return $updateStmt->rowCount() > 0;
}

function sync_fee_balance_for_student(PDO $pdo, int $studentId, string $classLevel, string $academicYear, string $term): void
{
    $activeCheck = $pdo->prepare("SELECT enrollment_status FROM students WHERE id = :student_id LIMIT 1");
    $activeCheck->execute(['student_id' => $studentId]);
    if ($activeCheck->fetchColumn() !== 'Active') {
        return;
    }
    if (student_fee_sponsorship_status($pdo, $studentId, $academicYear) === 'Sponsored Student') {
        return;
    }

    $feeStructure = get_fee_structure($pdo, $classLevel, $academicYear);
    if (!$feeStructure) {
        return;
    }

    $requiredFee = fee_required_amount_for_term($feeStructure, $term);
    fee_balance_sync_assessment($pdo, $studentId, $academicYear, $term, $requiredFee);
}

function get_fee_balance(PDO $pdo, int $studentId, string $academicYear, string $term, bool $createIfMissing = false): ?array
{
    if ($createIfMissing && student_fee_sponsorship_status($pdo, $studentId, $academicYear) === 'Sponsored Student') {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM fee_balances WHERE student_id = :student_id AND academic_year = :academic_year AND term = :term LIMIT 1"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    if (!$createIfMissing) {
        return null;
    }

    // Try to create a fee balance for the student using their class level and fee structure
    $s = $pdo->prepare("SELECT class_level, enrollment_status FROM students WHERE id = :id LIMIT 1");
    $s->execute(['id' => $studentId]);
    $student = $s->fetch();
    if (!$student || !isset($student['class_level']) || $student['enrollment_status'] !== 'Active') {
        return null;
    }

    $classLevel = student_class_for_year($pdo, $studentId, $academicYear, (string) $student['class_level']);
    $feeStructure = get_fee_structure($pdo, (string) $classLevel, $academicYear);
    if (!$feeStructure) {
        return null;
    }

    $requiredFee = fee_required_amount_for_term($feeStructure, $term);

    $ins = $pdo->prepare(
        "INSERT INTO fee_balances (student_id, academic_year, term, required_amount, paid_amount, balance) VALUES (:student_id, :academic_year, :term, :required_amount, 0.00, :balance)"
    );
    $ins->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
        'required_amount' => $requiredFee,
        'balance' => $requiredFee,
    ]);

    $stmt->execute([
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ]);
    return $stmt->fetch() ?: null;
}

function sync_fee_balances_for_class_year(PDO $pdo, string $classLevel, string $academicYear, string $term): int
{
    $feeStructure = get_fee_structure($pdo, $classLevel, $academicYear);
    if (!$feeStructure) {
        return 0;
    }

    $classLevelSql = student_class_sql_for_year('students', $academicYear);
    $sponsorshipFilter = student_fee_not_sponsored_sql('students', ':sponsorship_year');
    $studentsStmt = $pdo->prepare("SELECT id FROM students WHERE {$classLevelSql} = :class_level AND enrollment_status = 'Active' AND {$sponsorshipFilter}");
    $studentsStmt->execute(['class_level' => $classLevel, 'sponsorship_year' => $academicYear]);
    $students = $studentsStmt->fetchAll();

    $originalFee = fee_required_amount_for_term($feeStructure, $term);
    $synced = 0;
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        if (fee_balance_sync_assessment($pdo, $studentId, $academicYear, $term, $originalFee)) {
            $synced++;
        }
    }

    return $synced;
}

function sync_current_term_fee_balances(PDO $pdo): void
{
    // Get the current academic context
    $context = current_academic_context($pdo);
    $academicYear = $context['academic_year'] ?? '';
    $term = $context['term'] ?? '';

    if ($academicYear === '' || $term === '') {
        return;
    }

    // Get all class levels
    $classLevels = class_level_options();
    
    // For each class level, sync fee balances with the current term fee structure
    foreach ($classLevels as $classLevel) {
        sync_fee_balances_for_class_year($pdo, $classLevel, $academicYear, $term);
    }
}

function fetch_fee_term_report(PDO $pdo, array $filters): array
{
    $year = $filters['year'] ?? '';
    $term = $filters['term'] ?? '';
    $classYear = preg_match('/^\d{4}$/', (string) $year)
        ? (string) $year
        : (string) current_academic_context($pdo)['academic_year'];
    $classLevelSql = student_class_sql_for_year('s', $classYear);
    $params = [
        'fees_paid_year' => $year,
        'fees_paid_term' => $term,
        'last_payment_year' => $year,
        'last_payment_term' => $term,
        'balance_year' => $year,
        'balance_term' => $term,
        'structure_year' => $year,
        'sponsorship_year' => $year,
    ];

    $sponsorshipStatusSql = student_fee_sponsorship_status_sql('s', ':sponsorship_year');
    $sql = "SELECT s.id AS student_id, s.registration_no, s.full_name, s.gender, {$classLevelSql} AS class_level, s.enrollment_status,
                   {$sponsorshipStatusSql} AS fee_sponsorship_status,
                   fb.id AS fee_balance_id, fb.required_amount AS fb_required, fb.paid_amount AS fb_paid, fb.balance AS fb_balance,
                   fs.term1_total, fs.term2_total, fs.term3_total,
                   (SELECT COALESCE(SUM(amount_paid),0) FROM fees WHERE student_id = s.id AND year = :fees_paid_year AND term = :fees_paid_term AND payment_status = 'Recorded') AS fees_paid,
                   (SELECT payment_date FROM fees WHERE student_id = s.id AND year = :last_payment_year AND term = :last_payment_term AND payment_status = 'Recorded' ORDER BY payment_date DESC LIMIT 1) AS last_payment_date,
                   fs.id IS NOT NULL AS has_fee_structure
            FROM students s
            LEFT JOIN fee_balances fb ON fb.student_id = s.id AND fb.academic_year = :balance_year AND fb.term = :balance_term
            LEFT JOIN fee_structures fs ON fs.class_level = {$classLevelSql} AND fs.academic_year = :structure_year";

    // Apply optional filters
    $where = [];
    $enrollmentStatus = (string) ($filters['enrollment_status'] ?? 'Active');
    if ($enrollmentStatus !== 'All') {
        $where[] = 's.enrollment_status = :enrollment_status';
        $params['enrollment_status'] = $enrollmentStatus;
    }
    if (!empty($filters['class_level'])) {
        $where[] = "{$classLevelSql} = :class_level";
        $params['class_level'] = $filters['class_level'];
    }
    if (!empty($filters['gender'])) {
        $where[] = "s.gender = :gender";
        $params['gender'] = $filters['gender'];
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY s.full_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $isSponsored = ($r['fee_sponsorship_status'] ?? 'Regular Student') === 'Sponsored Student';
        $structure = [
            'term1_total' => $r['term1_total'] ?? 0,
            'term2_total' => $r['term2_total'] ?? 0,
            'term3_total' => $r['term3_total'] ?? 0,
        ];

        try {
            $required = $isSponsored ? 0.0 : ($r['fb_required'] !== null ? (float) $r['fb_required'] : (isset($r['term1_total']) ? fee_required_amount_for_term($structure, $term) : 0.0));
        } catch (Throwable $ex) {
            $required = 0.0;
        }

        $paid = $r['fb_paid'] !== null ? (float) $r['fb_paid'] : (float) $r['fees_paid'];
        $balance = $isSponsored ? 0.0 : max($required - $paid, 0.0);
        $hasFeeStructure = (bool) ($r['has_fee_structure'] ?? false);

        if ($isSponsored) {
            $status = 'Sponsored';
        } elseif (!$hasFeeStructure) {
            if ($balance <= 0.005) {
                $status = 'Paid';
            } elseif ($paid > 0.005) {
                $status = 'Partial';
            } else {
                $status = 'Unpaid';
            }
        } else {
            $status = fee_payment_status($required, $paid, $balance);
        }

        $out[] = [
            'student_id' => (int) $r['student_id'],
            'registration_no' => $r['registration_no'] ?? '',
            'full_name' => $r['full_name'] ?? '',
            'gender' => $r['gender'] ?? '',
            'class_level' => $r['class_level'] ?? '',
            'enrollment_status' => $r['enrollment_status'] ?? 'Active',
            'fee_sponsorship_status' => $isSponsored ? 'Sponsored Student' : 'Regular Student',
            'academic_year' => $year,
            'term' => $term,
            'required_amount' => $required,
            'paid_amount' => $paid,
            'balance' => $balance,
            'status' => $status,
            'last_payment_date' => $r['last_payment_date'] ?? null,
        ];
    }

    return $out;
}

function get_permission_user_status_display(string $status): string
{
    return $status;
}

function get_permission_user_template_display(string $templateName): string
{
    return $templateName;
}

function get_permission_user_permissions_display(array $permissionKeys): string
{
    return implode(', ', $permissionKeys);
}

function get_permission_template_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_user_permissions_keys(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_template_keys_for_current_admin(PDO $pdo): array
{
    return current_admin_permission_keys($pdo);
}

function get_permission_user_template_keys(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_access_keys_for_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_editable_roles(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_editable_permissions(PDO $pdo): array
{
    return get_all_permission_keys();
}

function get_permission_editable_permission_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function get_permission_editable_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_management_page_title(): string
{
    return 'Users & Permissions';
}

function get_permission_management_page_heading(): string
{
    return 'Users & Permissions';
}

function get_permission_management_page_subtitle(): string
{
    return 'Assign roles and control admin access';
}

function get_permission_management_actions(): array
{
    return permission_definitions();
}

function get_permission_management_templates(): array
{
    return permission_template_definitions();
}

function get_permission_management_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_management_roles(PDO $pdo): array
{
    return get_stored_role_templates($pdo);
}

function get_permission_management_templates_list(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_management_permissions(PDO $pdo): array
{
    return get_all_permission_keys();
}

function get_permission_management_permission_labels(): array
{
    return get_permission_lookup();
}

function get_permission_management_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_management_user_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function get_permission_management_page_info(): string
{
    return 'A new module to manage admin users and roles.';
}

function get_permission_management_page_help_text(): string
{
    return 'Use the controls to configure user access quickly.';
}

function get_permission_management_page_summary(): string
{
    return 'Manage admin users, role templates, and custom permissions.';
}

function current_admin_is_permission_manager(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function current_admin_has_settings_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function current_admin_has_users_permissions_access(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function current_admin_permission_summary_text(PDO $pdo): string
{
    return implode(', ', current_admin_permission_keys($pdo));
}

function current_admin_role_template_name(PDO $pdo): string
{
    return get_user_role_template_name($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}

function get_permission_user_template_by_user(PDO $pdo, int $userId): string
{
    return get_user_role_template_name($pdo, $userId);
}

function get_permission_user_status_by_user(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_user_permissions_by_user(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function get_permission_user_role_names_by_user(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function permission_settings_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function permission_user_management_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function permission_admin_access_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'users_permissions.access');
}

function permission_settings_access_enabled(PDO $pdo): bool
{
    return current_admin_has_permission($pdo, 'settings.view');
}

function permission_page_enabled(PDO $pdo): bool
{
    return true;
}

function get_permission_active_user_status(PDO $pdo, int $userId): string
{
    return get_active_user_status($pdo, $userId) ?? 'Active';
}

function get_permission_management_active_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function get_permission_management_active_roles(PDO $pdo): array
{
    return get_stored_role_templates($pdo);
}

function get_permission_management_active_templates(PDO $pdo): array
{
    return get_permission_template_dropdown($pdo);
}

function get_permission_management_active_permissions(PDO $pdo): array
{
    return get_all_permission_keys();
}

function get_permission_management_active_permission_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function get_permission_management_active_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function get_permission_management_active_user_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function permission_role_exists(PDO $pdo, string $roleName): bool
{
    return role_exists($pdo, $roleName);
}

function permission_user_exists(PDO $pdo, string $username): bool
{
    return (bool) get_user_by_username($pdo, $username);
}

function permission_module_exists(string $moduleName): bool
{
    return array_key_exists($moduleName, permission_definitions());
}

function permission_key_is_defined(string $permissionKey): bool
{
    return permission_key_exists($permissionKey);
}

function permission_action_is_defined(string $permissionKey): bool
{
    return permission_key_exists($permissionKey);
}

function permission_template_is_defined(string $templateName): bool
{
    return array_key_exists($templateName, permission_template_definitions());
}

function permission_mapping(): array
{
    return get_permission_lookup();
}

function permission_access_mapping(): array
{
    return get_permission_to_module_map();
}

function permission_template_mapping(): array
{
    return get_permission_template_map($pdo);
}

function permission_options(): array
{
    return permission_definitions();
}

function permission_templates(): array
{
    return permission_template_definitions();
}

function permission_users(PDO $pdo): array
{
    return get_admin_users($pdo);
}

function permission_roles(PDO $pdo): array
{
    return get_stored_role_templates($pdo);
}

function permission_template_roles(PDO $pdo): array
{
    return get_permission_template_labels();
}

function permission_user_roles(PDO $pdo, int $userId): array
{
    return get_user_role_names($pdo, $userId);
}

function permission_user_permissions(PDO $pdo, int $userId): array
{
    return get_user_permission_keys($pdo, $userId);
}

function permission_role_permissions(PDO $pdo, string $roleName): array
{
    return get_permission_template_permissions($pdo, $roleName);
}

function permission_action_labels(): array
{
    return get_permission_action_names();
}

function permission_module_labels(): array
{
    return get_permission_access_labels();
}

function permission_template_labels(): array
{
    return get_permission_template_labels();
}

function permission_user_labels(PDO $pdo): array
{
    return get_permission_lookup();
}

function permission_role_labels(PDO $pdo): array
{
    return get_permission_template_labels();
}

function permission_setting_labels(): array
{
    return get_permission_lookup();
}

/**
 * Log a permission check to the audit log.
 * @param PDO $pdo Database connection
 * @param int|null $userId Admin user ID
 * @param string $action Permission action
 * @param string|null $resource Resource being accessed
 * @param bool $granted Whether permission was granted
 * @param string|null $level Log level (INFO, WARN, DENY, ERROR)
 * @param array|null $details Additional context
 */
function audit_permission_check(
    PDO $pdo,
    ?int $userId,
    string $action,
    ?string $resource = null,
    bool $granted = false,
    ?string $level = null,
    ?array $details = null
): void {
    rbac_audit_log($pdo, $userId, $action, $resource, $granted, $level, $details);
}



