<?php

function rbac_module_definitions(): array
{
    return [
        'dashboard' => [
            'name' => 'Dashboard',
            'access_key' => 'dashboard.view',
            'icon' => 'fa-gauge-high',
            'actions' => [],
        ],
        'students' => [
            'name' => 'Students',
            'access_key' => 'students.access',
            'icon' => 'fa-user-graduate',
            'actions' => [
                'students.view' => ['label' => 'View Students', 'bulk' => 'view'],
                'students.add' => ['label' => 'Add Student', 'bulk' => 'save'],
                'students.edit' => ['label' => 'Edit Student', 'bulk' => 'update'],
                'students.delete' => ['label' => 'Delete Student', 'bulk' => 'delete'],
                'students.import' => ['label' => 'Import Students', 'bulk' => 'import'],
                'students.export' => ['label' => 'Export Students', 'bulk' => 'export'],
                'students.pay_balance' => ['label' => 'Pay Fee Balance', 'bulk' => 'save'],
                'students.transfer' => ['label' => 'Manage Enrollment Status', 'bulk' => 'update'],
                'students.manage_historical_fees' => ['label' => 'Manage Historical Student Balances', 'bulk' => 'save'],
            ],
        ],
        'fees' => [
            'name' => 'Fees',
            'access_key' => 'fees.access',
            'icon' => 'fa-wallet',
            'actions' => [
                'fees.record' => ['label' => 'Record Payment', 'bulk' => 'save'],
                'fees.view' => ['label' => 'View Payments', 'bulk' => 'view'],
                'fees.print' => ['label' => 'View Receipt', 'bulk' => 'receipt'],
                'fees.correct' => ['label' => 'Correct Payment', 'bulk' => 'update'],
                'fees.export' => ['label' => 'Export Excel', 'bulk' => 'export'],
                'fees.import' => ['label' => 'Import Historical Payments', 'bulk' => 'import'],
            ],
        ],
        'payment_audit' => [
            'name' => 'Payment Audit',
            'access_key' => 'payment_audit.access',
            'icon' => 'fa-file-circle-check',
            'actions' => [],
        ],
        'transport_fee_structure' => [
            'name' => 'Transport Fee Structure',
            'access_key' => 'transport_fee_structure.access',
            'icon' => 'fa-route',
            'actions' => [
                'transport_fee_structure.view' => ['label' => 'View Transport Fee Structures', 'bulk' => 'view'],
                'transport_fee_structure.save' => ['label' => 'Add Transport Fee Location', 'bulk' => 'save'],
                'transport_fee_structure.update' => ['label' => 'Edit Transport Fee Location', 'bulk' => 'update'],
                'transport_fee_structure.delete' => ['label' => 'Delete Transport Fee Location', 'bulk' => 'delete'],
                'transport_fee_structure.manage_routes' => ['label' => 'Manage Routes', 'bulk' => 'update'],
            ],
        ],
        'transport' => [
            'name' => 'Transport',
            'access_key' => 'transport.access',
            'icon' => 'fa-bus',
            'actions' => [
                'transport.view' => ['label' => 'View Transport Records', 'bulk' => 'view'],
                'transport.save' => ['label' => 'Add Account or Record Payment', 'bulk' => 'save'],
                'transport.update' => ['label' => 'Edit Transport Account', 'bulk' => 'update'],
                'transport.delete' => ['label' => 'Delete Transport Account', 'bulk' => 'delete'],
                'transport.view_receipt' => ['label' => 'View Receipt', 'bulk' => 'receipt'],
                'transport.reports' => ['label' => 'View Transport Reports', 'bulk' => 'view'],
            ],
        ],
        'feeding' => [
            'name' => 'Feeding',
            'access_key' => 'feeding.access',
            'icon' => 'fa-utensils',
            'actions' => [
                'feeding.save' => ['label' => 'Add Feeding Account', 'bulk' => 'save'],
                'feeding.update' => ['label' => 'Edit Feeding Account', 'bulk' => 'update'],
                'feeding.delete' => ['label' => 'Delete Feeding Account', 'bulk' => 'delete'],
                'feeding.record_payment' => ['label' => 'Record Feeding Payment', 'bulk' => 'save'],
                'feeding.view_receipt' => ['label' => 'View Receipt', 'bulk' => 'receipt'],
                'feeding.reports' => ['label' => 'View Feeding Records and Reports', 'bulk' => 'view'],
            ],
        ],
        'daycare' => [
            'name' => 'Daycare',
            'access_key' => 'daycare.access',
            'icon' => 'fa-baby',
            'actions' => [
                'daycare.children.view' => ['label' => 'View Daycare Children', 'bulk' => 'view'],
                'daycare.health.view' => ['label' => 'View Daycare Health Information', 'bulk' => 'view'],
                'daycare.children.manage' => ['label' => 'Manage Daycare Children', 'bulk' => 'update'],
                'daycare.guardians.manage' => ['label' => 'Manage Daycare Guardians', 'bulk' => 'update'],
                'daycare.payments.view' => ['label' => 'View Daycare Payments', 'bulk' => 'view'],
                'daycare.payments.record' => ['label' => 'Record Daycare Payments', 'bulk' => 'save'],
                'daycare.fees.manage' => ['label' => 'Manage Daycare Fee Plans', 'bulk' => 'update'],
                'daycare.reports.view' => ['label' => 'View Child Registration Report', 'bulk' => 'view'],
                'daycare.settings.manage' => ['label' => 'Manage Daycare Settings', 'bulk' => 'update'],
            ],
        ],
        'activities_trips' => [
            'name' => 'Activities & Trips',
            'access_key' => 'activities.access',
            'icon' => 'fa-person-hiking',
            'actions' => [
                'activities.view' => ['label' => 'View Activities & Trips', 'bulk' => 'view'],
                'activities.manage' => ['label' => 'Create, Edit, or Cancel Activities', 'bulk' => 'update'],
                'activities.payments.collect' => ['label' => 'Record Activity Payments', 'bulk' => 'save'],
                'activities.payments.reverse' => ['label' => 'Reverse or Mark Activity Payments Refunded', 'bulk' => 'update'],
            ],
        ],
        'school_uniform' => [
            'name' => 'School Uniform',
            'access_key' => 'school_uniform.access',
            'icon' => 'fa-shirt',
            'actions' => [
                'school_uniform.edit_catalog' => ['label' => 'Edit Uniform Catalog', 'bulk' => 'update'],
                'school_uniform.sell' => ['label' => 'Sell Uniform', 'bulk' => 'save'],
                'school_uniform.pay_balance' => ['label' => 'Pay Balance', 'bulk' => 'save'],
                'school_uniform.view_receipt' => ['label' => 'View Receipt', 'bulk' => 'receipt'],
                'school_uniform.view_reports' => ['label' => 'View Uniform Reports', 'bulk' => 'view'],
                'school_uniform.export_reports' => ['label' => 'Export Uniform Reports', 'bulk' => 'export'],
            ],
        ],
        'school_van_fuel' => [
            'name' => 'School Van Fuel',
            'access_key' => 'school_van_fuel.access',
            'icon' => 'fa-gas-pump',
            'actions' => [
                'school_van_fuel.add_fuel' => ['label' => 'Add Fuel', 'bulk' => 'save'],
                'school_van_fuel.add_vehicle' => ['label' => 'Add Vehicle', 'bulk' => 'save'],
                'school_van_fuel.view_vehicles' => ['label' => 'View Vehicles', 'bulk' => 'view'],
                'school_van_fuel.view_records' => ['label' => 'View Fuel Records', 'bulk' => 'view'],
                'school_van_fuel.edit_vehicle' => ['label' => 'Edit Vehicles', 'bulk' => 'update'],
                'school_van_fuel.delete_vehicle' => ['label' => 'Delete Vehicles', 'bulk' => 'delete'],
                'school_van_fuel.view_reports' => ['label' => 'View Fuel Reports', 'bulk' => 'view'],
                'school_van_fuel.export_reports' => ['label' => 'Export Fuel Reports', 'bulk' => 'export'],
            ],
        ],
        'kitchen' => [
            'name' => 'Kitchen',
            'access_key' => 'kitchen.access',
            'icon' => 'fa-kitchen-set',
            'actions' => [
                'kitchen.view' => ['label' => 'View Kitchen Dashboard', 'bulk' => 'view'],
                'kitchen.entry.add' => ['label' => 'Add Kitchen Expense', 'bulk' => 'save'],
                'kitchen.expenditure.view' => ['label' => 'View Kitchen Expenditure', 'bulk' => 'view'],
                'kitchen.export' => ['label' => 'Export Kitchen Expenditure', 'bulk' => 'export'],
            ],
        ],
        'expenses' => [
            'name' => 'Expenses',
            'access_key' => 'expenses.access',
            'icon' => 'fa-coins',
            'actions' => [
                'expenses.save' => ['label' => 'Save Expense', 'bulk' => 'save'],
                'expenses.update' => ['label' => 'Update Expense', 'bulk' => 'update'],
                'expenses.delete' => ['label' => 'Delete Expense', 'bulk' => 'delete'],
                'expenses.view' => ['label' => 'View Expense Records', 'bulk' => 'view'],
            ],
        ],
        'reports' => [
            'name' => 'Reports',
            'access_key' => 'reports.access',
            'icon' => 'fa-chart-line',
            'actions' => [
                'reports.view' => ['label' => 'View Reports', 'bulk' => 'view'],
                'reports.export' => ['label' => 'Export Reports', 'bulk' => 'export'],
            ],
        ],
        'academic_calendar' => [
            'name' => 'Academic Calendar',
            'access_key' => 'academic_calendar.manage',
            'icon' => 'fa-calendar-days',
            'actions' => [
                'academic_calendar.view' => ['label' => 'View Calendar', 'bulk' => 'view'],
                'academic_calendar.add' => ['label' => 'Add Calendar Entry', 'bulk' => 'save'],
                'academic_calendar.edit' => ['label' => 'Edit Calendar Entry', 'bulk' => 'update'],
                'academic_calendar.delete' => ['label' => 'Delete Calendar Entry', 'bulk' => 'delete'],
                'academic_calendar.change_period' => ['label' => 'Change Active Academic Period', 'bulk' => 'update'],
                'academic_calendar.generate_year' => ['label' => 'Create Academic Year Calendar', 'bulk' => 'save'],
            ],
        ],
        'settings' => [
            'name' => 'Settings',
            'access_key' => 'settings.view',
            'icon' => 'fa-gear',
            'actions' => [
                'settings.fee_structure' => ['label' => 'Manage Fee Structures', 'bulk' => 'update'],
                'settings.manage_users' => ['label' => 'Users & Permissions', 'bulk' => 'update'],
                'settings.manage_class_levels' => ['label' => 'Manage Class Levels', 'bulk' => 'update'],
            ],
        ],
    ];
}

function rbac_permission_definitions(): array
{
    $definitions = [];
    foreach (rbac_module_definitions() as $module) {
        $actions = [];
        foreach ($module['actions'] as $key => $action) {
            $actions[$key] = $action['label'];
        }

        $definitions[$module['name']] = [
            'permission_key' => $module['access_key'],
            'label' => $module['name'],
            'icon' => $module['icon'],
            'actions' => $actions,
        ];
    }

    return $definitions;
}

function rbac_permission_aliases(): array
{
    return [
        'fees.add' => 'fees.record',
        'fees.edit' => 'fees.record',
        'fees.delete' => 'fees.record',
        'transport.add' => 'transport.save',
        'transport.edit' => 'transport.update',
        'feeding.view' => 'feeding.reports',
        'feeding.edit' => 'feeding.update',
        'school_uniform.view' => 'school_uniform.access',
        'school_uniform.inventory' => 'school_uniform.edit_catalog',
        'school_van_fuel.view' => 'school_van_fuel.view_records',
        'kitchen.inventory' => 'kitchen.view',
        'kitchen.stock_taking' => 'kitchen.entry.add',
        'expenses.add' => 'expenses.save',
        'users_permissions.access' => 'settings.manage_users',
        'users_permissions.manage' => 'settings.manage_users',
        'transport_fee_structure.edit' => 'transport_fee_structure.update',
        'transport_fee_structure.manage' => 'transport_fee_structure.manage_routes',
        'settings.academic_calendar' => 'academic_calendar.manage',
    ];
}

function rbac_module_access_keys(): array
{
    $keys = [];
    foreach (rbac_module_definitions() as $moduleKey => $module) {
        $keys[$module['access_key']] = $moduleKey;
    }
    return $keys;
}

function rbac_module_key_from_name(string $moduleName): ?string
{
    $normalized = strtolower(trim($moduleName));
    $normalized = str_replace([' ', '-'], '_', $normalized);
    foreach (rbac_module_definitions() as $moduleKey => $module) {
        if (
            $moduleKey === $normalized
            || strtolower($module['name']) === strtolower(trim($moduleName))
            || strtolower($module['access_key']) === strtolower(trim($moduleName))
        ) {
            return $moduleKey;
        }
    }
    return null;
}

function rbac_permission_keys_with_module_access(array $permissionKeys, array $moduleKeys): array
{
    foreach ($moduleKeys as $moduleName) {
        $moduleKey = rbac_module_key_from_name((string) $moduleName);
        if ($moduleKey !== null) {
            $permissionKeys[] = rbac_module_definitions()[$moduleKey]['access_key'];
        }
    }

    return array_values(array_unique($permissionKeys));
}

function rbac_module_name(string $moduleKeyOrName): string
{
    $moduleKey = rbac_module_key_from_name($moduleKeyOrName);
    $modules = rbac_module_definitions();
    return $moduleKey && isset($modules[$moduleKey]) ? $modules[$moduleKey]['name'] : $moduleKeyOrName;
}

function rbac_normalize_permission_key(string $permissionKey): ?string
{
    $permissionKey = trim($permissionKey);
    if ($permissionKey === '') {
        return null;
    }
    $aliases = rbac_permission_aliases();
    return $aliases[$permissionKey] ?? $permissionKey;
}

function rbac_permission_module_key(string $permissionKey): ?string
{
    $permissionKey = rbac_normalize_permission_key($permissionKey);
    if ($permissionKey === null) {
        return null;
    }

    $accessKeys = rbac_module_access_keys();
    if (isset($accessKeys[$permissionKey])) {
        return $accessKeys[$permissionKey];
    }

    foreach (rbac_module_definitions() as $moduleKey => $module) {
        if (isset($module['actions'][$permissionKey])) {
            return $moduleKey;
        }
    }

    return null;
}

function rbac_action_permission_keys(): array
{
    $keys = [];
    foreach (rbac_module_definitions() as $module) {
        foreach ($module['actions'] as $permissionKey => $action) {
            $keys[] = $permissionKey;
        }
    }
    return $keys;
}

function rbac_all_permission_keys(bool $includeModuleAccess = true): array
{
    $keys = $includeModuleAccess ? array_keys(rbac_module_access_keys()) : [];
    return array_values(array_unique(array_merge($keys, rbac_action_permission_keys())));
}

function rbac_permission_label(string $permissionKey): string
{
    $permissionKey = rbac_normalize_permission_key($permissionKey) ?? $permissionKey;
    foreach (rbac_module_definitions() as $module) {
        if ($module['access_key'] === $permissionKey) {
            return $module['name'];
        }
        if (isset($module['actions'][$permissionKey])) {
            return $module['actions'][$permissionKey]['label'];
        }
    }
    return $permissionKey;
}

function rbac_permission_bulk_group(string $permissionKey): string
{
    $permissionKey = rbac_normalize_permission_key($permissionKey) ?? $permissionKey;
    foreach (rbac_module_definitions() as $module) {
        if (isset($module['actions'][$permissionKey])) {
            return $module['actions'][$permissionKey]['bulk'] ?? '';
        }
    }
    return '';
}

function rbac_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function rbac_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function rbac_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name");
    $stmt->execute(['table' => $table, 'index_name' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function rbac_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(50) NULL UNIQUE,
            description TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    if (!rbac_column_exists($pdo, 'roles', 'role_name')) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN role_name VARCHAR(50) NULL AFTER id");
        if (rbac_column_exists($pdo, 'roles', 'name')) {
            $pdo->exec("UPDATE roles SET role_name = name WHERE role_name IS NULL OR role_name = ''");
        }
        $pdo->exec("UPDATE roles SET role_name = CONCAT('Role ', id) WHERE role_name IS NULL OR role_name = ''");
        $pdo->exec("ALTER TABLE roles MODIFY role_name VARCHAR(50) NOT NULL");
        if (!rbac_index_exists($pdo, 'roles', 'uniq_roles_role_name')) {
            $pdo->exec("ALTER TABLE roles ADD UNIQUE KEY uniq_roles_role_name (role_name)");
        }
    }
    if (!rbac_column_exists($pdo, 'roles', 'name')) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN name VARCHAR(50) NULL AFTER role_name");
        $pdo->exec("UPDATE roles SET name = role_name WHERE name IS NULL OR name = ''");
        if (!rbac_index_exists($pdo, 'roles', 'uniq_roles_name')) {
            $pdo->exec("ALTER TABLE roles ADD UNIQUE KEY uniq_roles_name (name)");
        }
    } else {
        $pdo->exec("UPDATE roles SET role_name = name WHERE (role_name IS NULL OR role_name = '') AND name IS NOT NULL AND name <> ''");
        $pdo->exec("UPDATE roles SET name = role_name WHERE (name IS NULL OR name = '') AND role_name IS NOT NULL AND role_name <> ''");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS permissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            label VARCHAR(150) NOT NULL,
            module VARCHAR(60) NULL,
            action VARCHAR(80) NULL,
            module_name VARCHAR(80) NULL,
            permission_name VARCHAR(150) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    foreach ([
        'permission_key' => "ALTER TABLE permissions ADD COLUMN permission_key VARCHAR(100) NULL AFTER id",
        'label' => "ALTER TABLE permissions ADD COLUMN label VARCHAR(150) NULL AFTER permission_key",
        'module' => "ALTER TABLE permissions ADD COLUMN module VARCHAR(60) NULL AFTER label",
        'action' => "ALTER TABLE permissions ADD COLUMN action VARCHAR(80) NULL AFTER module",
        'module_name' => "ALTER TABLE permissions ADD COLUMN module_name VARCHAR(80) NULL AFTER action",
        'permission_name' => "ALTER TABLE permissions ADD COLUMN permission_name VARCHAR(150) NULL AFTER module_name",
    ] as $column => $sql) {
        if (!rbac_column_exists($pdo, 'permissions', $column)) {
            $pdo->exec($sql);
        }
    }
    if (!rbac_index_exists($pdo, 'permissions', 'permission_key')) {
        try {
            $pdo->exec("ALTER TABLE permissions ADD UNIQUE KEY permission_key (permission_key)");
        } catch (Throwable $ignored) {
        }
    }

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
        "CREATE TABLE IF NOT EXISTS module_access (
            role_id INT UNSIGNED NOT NULL,
            module_name VARCHAR(80) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 0,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, module_name),
            CONSTRAINT fk_module_access_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_permission_overrides (
            user_id INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 1,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, permission_id),
            CONSTRAINT fk_user_permission_overrides_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_user_permission_overrides_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_module_overrides (
            user_id INT UNSIGNED NOT NULL,
            module_name VARCHAR(80) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 1,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, module_name),
            CONSTRAINT fk_user_module_overrides_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    );

    rbac_seed_permissions($pdo);
    rbac_migrate_legacy_permissions($pdo);
    rbac_prune_retired_permissions($pdo);
    $done = true;
}

function rbac_seed_permissions(PDO $pdo): void
{
    $statement = $pdo->prepare(
        "INSERT INTO permissions (permission_key, label, module, action, module_name, permission_name)
         VALUES (:permission_key, :label, :module, :action, :module_name, :permission_name)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            module = VALUES(module),
            action = VALUES(action),
            module_name = VALUES(module_name),
            permission_name = VALUES(permission_name)"
    );

    foreach (rbac_module_definitions() as $moduleKey => $module) {
        foreach ($module['actions'] as $permissionKey => $action) {
            $statement->execute([
                'permission_key' => $permissionKey,
                'label' => $action['label'],
                'module' => $moduleKey,
                'action' => $permissionKey,
                'module_name' => $module['name'],
                'permission_name' => $action['label'],
            ]);
        }
    }

}

function rbac_prune_retired_permissions(PDO $pdo): void
{
    // Keep existing grants intact. Permission cleanup must be an explicit,
    // reviewed migration rather than a side effect of opening a page.
}

function rbac_migrate_legacy_permissions(PDO $pdo): void
{
    $moduleAccessKeys = rbac_module_access_keys();
    if ($moduleAccessKeys) {
        $placeholders = implode(',', array_fill(0, count($moduleAccessKeys), '?'));
        $stmt = $pdo->prepare(
            "SELECT rp.role_id, p.permission_key
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_key IN ({$placeholders})"
        );
        $stmt->execute(array_keys($moduleAccessKeys));
        $insert = $pdo->prepare(
            "INSERT IGNORE INTO module_access (role_id, module_name, allowed)
             VALUES (:role_id, :module_name, 1)"
        );
        foreach ($stmt->fetchAll() as $row) {
            $moduleKey = $moduleAccessKeys[$row['permission_key']] ?? null;
            if ($moduleKey !== null) {
                $insert->execute([
                    'role_id' => (int) $row['role_id'],
                    'module_name' => rbac_module_name($moduleKey),
                ]);
            }
        }

    }

    $overrideCount = (int) $pdo->query("SELECT COUNT(*) FROM user_permission_overrides")->fetchColumn();
    if ($overrideCount === 0 && rbac_table_exists($pdo, 'user_permissions')) {
        $pdo->exec(
            "INSERT IGNORE INTO user_permission_overrides (user_id, permission_id, allowed)
             SELECT user_id, permission_id, 1 FROM user_permissions"
        );
    }
}

function rbac_role_template_definitions(): array
{
    $allModules = array_keys(rbac_module_definitions());
    $allActions = rbac_action_permission_keys();

    return [
        'Director' => [
            'description' => 'Full access to all school management modules.',
            'modules' => $allModules,
            'permissions' => $allActions,
        ],
        'Accountant' => [
            'description' => 'Finance, billing, expenses, and reporting access.',
            'modules' => ['dashboard', 'students', 'fees', 'transport_fee_structure', 'transport', 'feeding', 'school_uniform', 'expenses', 'reports', 'settings', 'academic_calendar', 'daycare', 'activities_trips'],
            'permissions' => [
                'students.view', 'students.export', 'students.pay_balance',
                'students.manage_historical_fees',
                'fees.record', 'fees.view', 'fees.print', 'fees.export', 'fees.import',
                'transport_fee_structure.view', 'transport_fee_structure.save', 'transport_fee_structure.update', 'transport_fee_structure.manage_routes',
                'transport.view', 'transport.save', 'transport.update', 'transport.view_receipt', 'transport.reports',
                'feeding.save', 'feeding.update', 'feeding.record_payment', 'feeding.view_receipt', 'feeding.reports',
                'school_uniform.sell', 'school_uniform.pay_balance', 'school_uniform.view_receipt', 'school_uniform.view_reports', 'school_uniform.export_reports',
                'expenses.save', 'expenses.update', 'expenses.view',
                'reports.view', 'reports.export',
                'settings.fee_structure', 'settings.manage_class_levels',
                'academic_calendar.view', 'academic_calendar.add', 'academic_calendar.edit', 'academic_calendar.delete',
                'daycare.children.view', 'daycare.payments.view', 'daycare.payments.record',
                'daycare.reports.view',
                'activities.view', 'activities.manage', 'activities.payments.collect', 'activities.payments.reverse',
            ],
        ],
        'Headteacher' => [
            'description' => 'Student records, academic calendar, and staff oversight.',
            'modules' => ['dashboard', 'students', 'feeding', 'reports', 'academic_calendar', 'settings', 'daycare'],
            'permissions' => [
                'students.view', 'students.add', 'students.edit', 'students.export',
                'students.transfer',
                'feeding.reports',
                'reports.view',
                'academic_calendar.view', 'academic_calendar.add', 'academic_calendar.edit', 'academic_calendar.delete', 'academic_calendar.change_period', 'academic_calendar.generate_year',
                'settings.manage_users',
                'daycare.children.view', 'daycare.health.view', 'daycare.reports.view',
            ],
        ],
        'Secretary' => [
            'description' => 'Front-office, fee history, and kitchen expense recording and reporting.',
            'modules' => ['fees', 'payment_audit', 'kitchen'],
            'permissions' => ['fees.view', 'kitchen.view', 'kitchen.entry.add', 'kitchen.expenditure.view', 'kitchen.export'],
        ],
        'Teacher' => [
            'description' => 'Class-level student visibility and read-only reports.',
            'modules' => ['dashboard', 'students', 'feeding', 'reports', 'daycare'],
            'permissions' => [
                'students.view',
                'feeding.reports',
                'reports.view',
                'daycare.children.view',
            ],
        ],
        'Parent' => [
            'description' => 'Restricted access to the parent portal for linked children only.',
            'modules' => [],
            'permissions' => [],
        ],
        'Receptionist' => [
            'description' => 'Front-office student registration and payment support.',
            'modules' => ['dashboard', 'students', 'fees', 'transport', 'feeding', 'school_uniform', 'reports', 'daycare'],
            'permissions' => [
                'students.view', 'students.add', 'students.edit', 'students.import', 'students.pay_balance',
                'fees.record', 'fees.view', 'fees.print',
                'transport.view', 'transport.save', 'transport.update', 'transport.view_receipt',
                'feeding.save', 'feeding.update', 'feeding.record_payment', 'feeding.view_receipt', 'feeding.reports',
                'school_uniform.sell', 'school_uniform.pay_balance', 'school_uniform.view_receipt',
                'reports.view',
                'daycare.children.view', 'daycare.health.view', 'daycare.children.manage', 'daycare.guardians.manage',
                'daycare.payments.view', 'daycare.payments.record',
            ],
        ],
        'Kitchen Manager' => [
            'description' => 'No Kitchen access is granted by default; assign specific Kitchen permissions as needed.',
            'modules' => ['dashboard'],
            'permissions' => [],
        ],
        'Kitchen Staff' => [
            'description' => 'No Kitchen access is granted by default; assign specific Kitchen permissions as needed.',
            'modules' => ['dashboard'],
            'permissions' => [],
        ],
        'Transport Officer' => [
            'description' => 'Transport routes, transport payments, vehicles, and fuel records.',
            'modules' => ['dashboard', 'transport_fee_structure', 'transport', 'school_van_fuel', 'reports'],
            'permissions' => [
                'transport_fee_structure.view', 'transport_fee_structure.save', 'transport_fee_structure.update', 'transport_fee_structure.delete', 'transport_fee_structure.manage_routes',
                'transport.view', 'transport.save', 'transport.update', 'transport.delete', 'transport.view_receipt', 'transport.reports',
                'school_van_fuel.add_fuel', 'school_van_fuel.add_vehicle', 'school_van_fuel.view_vehicles', 'school_van_fuel.edit_vehicle', 'school_van_fuel.delete_vehicle', 'school_van_fuel.view_records', 'school_van_fuel.view_reports', 'school_van_fuel.export_reports',
                'reports.view',
            ],
        ],
    ];
}

function rbac_permission_template_keys(string $templateName): array
{
    $templates = rbac_role_template_definitions();
    $template = $templates[$templateName] ?? null;
    if (!$template) {
        return [];
    }

    $keys = [];
    foreach ($template['modules'] as $moduleKey) {
        $modules = rbac_module_definitions();
        if (isset($modules[$moduleKey])) {
            $keys[] = $modules[$moduleKey]['access_key'];
        }
    }
    return array_values(array_unique(array_merge($keys, $template['permissions'])));
}

function rbac_find_or_create_role(PDO $pdo, string $roleName, string $description = ''): int
{
    rbac_ensure_schema($pdo);
    $statement = $pdo->prepare("SELECT id FROM roles WHERE role_name = :role_name OR name = :legacy_name LIMIT 1");
    $statement->execute(['role_name' => $roleName, 'legacy_name' => $roleName]);
    $role = $statement->fetch();
    if ($role) {
        $update = $pdo->prepare("UPDATE roles SET role_name = :role_name, name = :legacy_name, description = COALESCE(NULLIF(description, ''), :description) WHERE id = :id");
        $update->execute(['role_name' => $roleName, 'legacy_name' => $roleName, 'description' => $description, 'id' => (int) $role['id']]);
        return (int) $role['id'];
    }

    $create = $pdo->prepare("INSERT INTO roles (role_name, name, description) VALUES (:role_name, :name, :description)");
    $create->execute(['role_name' => $roleName, 'name' => $roleName, 'description' => $description]);
    return (int) $pdo->lastInsertId();
}

function rbac_get_permission_ids(PDO $pdo, array $permissionKeys): array
{
    rbac_ensure_schema($pdo);
    $permissionKeys = array_values(array_unique(array_filter(array_map(function ($key) {
        $normalized = rbac_normalize_permission_key((string) $key);
        return $normalized && in_array($normalized, rbac_action_permission_keys(), true) ? $normalized : null;
    }, $permissionKeys))));

    if (!$permissionKeys) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $statement = $pdo->prepare("SELECT id, permission_key FROM permissions WHERE permission_key IN ({$placeholders})");
    $statement->execute($permissionKeys);

    $ids = [];
    foreach ($statement->fetchAll() as $row) {
        $ids[$row['permission_key']] = (int) $row['id'];
    }
    return $ids;
}

function rbac_split_permission_keys(array $permissionKeys): array
{
    $moduleAccessKeys = rbac_module_access_keys();
    $modules = [];
    $actions = [];

    foreach ($permissionKeys as $permissionKey) {
        $normalized = rbac_normalize_permission_key((string) $permissionKey);
        if ($normalized === null) {
            continue;
        }
        if (isset($moduleAccessKeys[$normalized])) {
            $modules[] = $moduleAccessKeys[$normalized];
        } elseif (in_array($normalized, rbac_action_permission_keys(), true)) {
            $actions[] = $normalized;
        }
    }

    return [
        'modules' => array_values(array_unique($modules)),
        'actions' => array_values(array_unique($actions)),
    ];
}

function rbac_set_role_permissions(PDO $pdo, int $roleId, array $permissionKeys): void
{
    rbac_ensure_schema($pdo);
    $split = rbac_split_permission_keys($permissionKeys);
    $selectedModules = array_flip($split['modules']);
    $split['actions'] = array_values(array_filter($split['actions'], function (string $permissionKey) use ($selectedModules): bool {
        $moduleKey = rbac_permission_module_key($permissionKey);
        return $moduleKey !== null && isset($selectedModules[$moduleKey]);
    }));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id = :role_id")->execute(['role_id' => $roleId]);
        $pdo->prepare("DELETE FROM module_access WHERE role_id = :role_id")->execute(['role_id' => $roleId]);

        $moduleStatement = $pdo->prepare(
            "INSERT INTO module_access (role_id, module_name, allowed)
             VALUES (:role_id, :module_name, 1)
             ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)"
        );
        foreach ($split['modules'] as $moduleKey) {
            $moduleStatement->execute([
                'role_id' => $roleId,
                'module_name' => rbac_module_name($moduleKey),
            ]);
        }

        $permissionIds = rbac_get_permission_ids($pdo, $split['actions']);
        $permissionStatement = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");
        foreach ($permissionIds as $permissionId) {
            $permissionStatement->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        $pdo->prepare(
            "DELETE FROM user_module_overrides
             WHERE user_id IN (SELECT user_id FROM user_roles WHERE role_id = :role_id)"
        )->execute(['role_id' => $roleId]);
        $pdo->prepare(
            "DELETE FROM user_permission_overrides
             WHERE user_id IN (SELECT user_id FROM user_roles WHERE role_id = :role_id)"
        )->execute(['role_id' => $roleId]);
        $pdo->prepare(
            "DELETE FROM user_permissions
             WHERE user_id IN (SELECT user_id FROM user_roles WHERE role_id = :role_id)"
        )->execute(['role_id' => $roleId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function rbac_set_user_overrides(PDO $pdo, int $userId, array $selectedKeys): void
{
    rbac_ensure_schema($pdo);
    $selected = rbac_split_permission_keys($selectedKeys);
    $selectedModules = array_flip($selected['modules']);
    $selectedActions = array_flip(array_filter($selected['actions'], function (string $permissionKey) use ($selectedModules): bool {
        $moduleKey = rbac_permission_module_key($permissionKey);
        return $moduleKey !== null && isset($selectedModules[$moduleKey]);
    }));
    $permissionIds = rbac_get_permission_ids($pdo, rbac_action_permission_keys());

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM user_module_overrides WHERE user_id = :user_id")->execute(['user_id' => $userId]);
        $pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id = :user_id")->execute(['user_id' => $userId]);
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :user_id")->execute(['user_id' => $userId]);

        $moduleStatement = $pdo->prepare(
            "INSERT INTO user_module_overrides (user_id, module_name, allowed)
             VALUES (:user_id, :module_name, :allowed)"
        );
        foreach (rbac_module_definitions() as $moduleKey => $module) {
            $moduleStatement->execute([
                'user_id' => $userId,
                'module_name' => $module['name'],
                'allowed' => isset($selectedModules[$moduleKey]) ? 1 : 0,
            ]);
        }

        $overrideStatement = $pdo->prepare(
            "INSERT INTO user_permission_overrides (user_id, permission_id, allowed)
             VALUES (:user_id, :permission_id, :allowed)"
        );
        $legacyStatement = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id) VALUES (:user_id, :permission_id)");
        foreach ($permissionIds as $permissionKey => $permissionId) {
            $allowed = isset($selectedActions[$permissionKey]) ? 1 : 0;
            $overrideStatement->execute([
                'user_id' => $userId,
                'permission_id' => $permissionId,
                'allowed' => $allowed,
            ]);
            if ($allowed) {
                $legacyStatement->execute(['user_id' => $userId, 'permission_id' => $permissionId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function rbac_seed_role_templates(PDO $pdo): void
{
    rbac_ensure_schema($pdo);
    foreach (rbac_role_template_definitions() as $roleName => $template) {
        $roleId = rbac_find_or_create_role($pdo, $roleName, $template['description']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM module_access WHERE role_id = :role_id");
        $stmt->execute(['role_id' => $roleId]);
        $moduleRows = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = :role_id");
        $stmt->execute(['role_id' => $roleId]);
        $permissionRows = (int) $stmt->fetchColumn();

        if ($moduleRows === 0 && $permissionRows === 0) {
            rbac_set_role_permissions($pdo, $roleId, rbac_permission_template_keys($roleName));
        }
    }
}

function rbac_sync_role_template_permissions(PDO $pdo): void
{
    // Role permissions are admin-managed. Do not recreate template modules or
    // overwrite saved role access on refresh.
    rbac_ensure_schema($pdo);
}

function rbac_role_permission_keys(PDO $pdo, int $roleId): array
{
    rbac_ensure_schema($pdo);
    $keys = [];

    $stmt = $pdo->prepare("SELECT module_name FROM module_access WHERE role_id = :role_id AND allowed = 1");
    $stmt->execute(['role_id' => $roleId]);
    foreach ($stmt->fetchAll() as $row) {
        $moduleKey = rbac_module_key_from_name((string) $row['module_name']);
        if ($moduleKey !== null) {
            $keys[] = rbac_module_definitions()[$moduleKey]['access_key'];
        }
    }

    $stmt = $pdo->prepare(
        "SELECT p.permission_key
         FROM permissions p
         JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = :role_id"
    );
    $stmt->execute(['role_id' => $roleId]);
    foreach ($stmt->fetchAll() as $row) {
        $normalized = rbac_normalize_permission_key($row['permission_key']);
        if ($normalized && in_array($normalized, rbac_action_permission_keys(), true)) {
            $keys[] = $normalized;
        }
    }

    return array_values(array_unique($keys));
}

function rbac_user_role_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'role_id'));
}

function rbac_user_has_role_name(PDO $pdo, int $userId, string $roleName): bool
{
    if ($userId <= 0 || trim($roleName) === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND LOWER(TRIM(COALESCE(NULLIF(r.role_name, ''), r.name))) = LOWER(TRIM(:role_name))
         LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId, 'role_name' => $roleName]);
    return (bool) $stmt->fetchColumn();
}

function rbac_user_is_administrator(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    foreach (['Administrator', 'Admin', 'System Administrator', 'Director'] as $roleName) {
        if (rbac_user_has_role_name($pdo, $userId, $roleName)) {
            return true;
        }
    }

    $roleCount = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = :user_id');
    $roleCount->execute(['user_id' => $userId]);
    if ((int) $roleCount->fetchColumn() > 0) {
        return false;
    }

    $firstAdmin = $pdo->query('SELECT id, username FROM admin ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $firstAdmin
        && (int) $firstAdmin['id'] === $userId
        && strtolower((string) $firstAdmin['username']) === 'admin';
}

function rbac_user_kitchen_access_level(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return 'none';
    }

    if (rbac_user_is_administrator($pdo, $userId) || rbac_user_is_bootstrap_admin($pdo, $userId)) {
        return 'manage';
    }

    $moduleMap = rbac_user_module_access_map($pdo, $userId);
    if (empty($moduleMap['kitchen'])) {
        return 'none';
    }

    $permissions = rbac_user_permission_map($pdo, $userId);
    if (!empty($permissions['kitchen.entry.add'])) {
        return 'manage';
    }
    if (!empty($permissions['kitchen.view']) || !empty($permissions['kitchen.expenditure.view']) || !empty($permissions['kitchen.export'])) {
        return 'view';
    }

    return 'none';
}

function rbac_user_has_kitchen_staff_only_role(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT LOWER(TRIM(COALESCE(NULLIF(r.role_name, ''), r.name))) AS role_name
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = :user_id"
    );
    $stmt->execute(['user_id' => $userId]);
    $roles = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'role_name');
    $isKitchenStaff = (bool) array_intersect(['kitchen staff', 'kitchen manager'], $roles);
    $hasSeparateFinanceRole = (bool) array_intersect(['director', 'accountant', 'system administrator'], $roles);

    return $isKitchenStaff && !$hasSeparateFinanceRole;
}

function rbac_user_is_bootstrap_admin(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $roleCount = (int) $pdo->query("SELECT COUNT(*) FROM user_roles")->fetchColumn();
    if ($roleCount === 0) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT id, username FROM admin ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $firstAdmin = $stmt->fetch();
    if (!$firstAdmin || (int) $firstAdmin['id'] !== $userId) {
        return false;
    }

    return strtolower((string) $firstAdmin['username']) === 'admin';
}

function rbac_user_module_access_map(PDO $pdo, int $userId): array
{
    rbac_ensure_schema($pdo);
    $map = [];
    foreach (rbac_module_definitions() as $moduleKey => $module) {
        $map[$moduleKey] = false;
    }

    if (rbac_user_is_administrator($pdo, $userId) || rbac_user_is_bootstrap_admin($pdo, $userId)) {
        foreach ($map as $moduleKey => $allowed) {
            $map[$moduleKey] = true;
        }
        return $map;
    }

    $roleIds = rbac_user_role_ids($pdo, $userId);
    if ($roleIds) {
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $pdo->prepare("SELECT module_name, MAX(allowed) AS allowed FROM module_access WHERE role_id IN ({$placeholders}) GROUP BY module_name");
        $stmt->execute($roleIds);
        foreach ($stmt->fetchAll() as $row) {
            $moduleKey = rbac_module_key_from_name($row['module_name']);
            if ($moduleKey !== null) {
                $map[$moduleKey] = (int) $row['allowed'] === 1;
            }
        }
    }

    $stmt = $pdo->prepare("SELECT module_name, allowed FROM user_module_overrides WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    foreach ($stmt->fetchAll() as $row) {
        $moduleKey = rbac_module_key_from_name($row['module_name']);
        if ($moduleKey !== null) {
            $map[$moduleKey] = (int) $row['allowed'] === 1;
        }
    }

    return $map;
}

function rbac_user_permission_map(PDO $pdo, int $userId): array
{
    rbac_ensure_schema($pdo);
    $map = [];
    foreach (rbac_action_permission_keys() as $permissionKey) {
        $map[$permissionKey] = false;
    }

    if (rbac_user_is_administrator($pdo, $userId) || rbac_user_is_bootstrap_admin($pdo, $userId)) {
        foreach ($map as $permissionKey => $allowed) {
            $map[$permissionKey] = true;
        }
        return $map;
    }

    $roleIds = rbac_user_role_ids($pdo, $userId);
    if ($roleIds) {
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT p.permission_key
             FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id IN ({$placeholders})"
        );
        $stmt->execute($roleIds);
        foreach ($stmt->fetchAll() as $row) {
            $permissionKey = rbac_normalize_permission_key($row['permission_key']);
            if ($permissionKey && array_key_exists($permissionKey, $map)) {
                $map[$permissionKey] = true;
            }
        }
    }

    $stmt = $pdo->prepare(
        "SELECT p.permission_key, upo.allowed
         FROM user_permission_overrides upo
         JOIN permissions p ON p.id = upo.permission_id
         WHERE upo.user_id = :user_id"
    );
    $stmt->execute(['user_id' => $userId]);
    foreach ($stmt->fetchAll() as $row) {
        $permissionKey = rbac_normalize_permission_key($row['permission_key']);
        if ($permissionKey && array_key_exists($permissionKey, $map)) {
            $map[$permissionKey] = (int) $row['allowed'] === 1;
        }
    }

    $moduleMap = rbac_user_module_access_map($pdo, $userId);
    foreach ($map as $permissionKey => $allowed) {
        $moduleKey = rbac_permission_module_key($permissionKey);
        if ($moduleKey !== null && empty($moduleMap[$moduleKey])) {
            $map[$permissionKey] = false;
        }
    }

    return $map;
}

function rbac_user_permission_keys(PDO $pdo, int $userId): array
{
    $keys = [];
    $moduleMap = rbac_user_module_access_map($pdo, $userId);
    foreach (rbac_module_definitions() as $moduleKey => $module) {
        if (!empty($moduleMap[$moduleKey])) {
            $keys[] = $module['access_key'];
        }
    }

    foreach (rbac_user_permission_map($pdo, $userId) as $permissionKey => $allowed) {
        if ($allowed) {
            $keys[] = $permissionKey;
        }
    }

    return array_values(array_unique($keys));
}

function rbac_user_can_access_module(PDO $pdo, int $userId, string $moduleKeyOrName): bool
{
    $moduleKey = rbac_module_key_from_name($moduleKeyOrName);
    if ($moduleKey === null) {
        $permissionModule = rbac_permission_module_key($moduleKeyOrName);
        $moduleKey = $permissionModule;
    }
    if ($moduleKey === null) {
        return false;
    }

    if ($moduleKey === 'kitchen') {
        return rbac_user_kitchen_access_level($pdo, $userId) !== 'none';
    }
    if (in_array($moduleKey, ['expenses', 'reports'], true) && rbac_user_has_kitchen_staff_only_role($pdo, $userId)) {
        return false;
    }

    $moduleMap = rbac_user_module_access_map($pdo, $userId);
    return !empty($moduleMap[$moduleKey]);
}

function rbac_user_has_permission(PDO $pdo, int $userId, string $permissionKey): bool
{
    $permissionKey = rbac_normalize_permission_key($permissionKey);
    if ($permissionKey === null) {
        return false;
    }

    $moduleAccessKeys = rbac_module_access_keys();
    if (isset($moduleAccessKeys[$permissionKey])) {
        return rbac_user_can_access_module($pdo, $userId, $moduleAccessKeys[$permissionKey]);
    }

    $moduleKey = rbac_permission_module_key($permissionKey);
    if ($moduleKey === null) {
        return false;
    }
    if (!rbac_user_can_access_module($pdo, $userId, $moduleKey)) {
        return false;
    }

    $permissionMap = rbac_user_permission_map($pdo, $userId);
    return !empty($permissionMap[$permissionKey]);
}

function rbac_current_admin_has_permission(PDO $pdo, string $permissionKey): bool
{
    $userId = (int) ($_SESSION['admin_id'] ?? 0);
    return $userId > 0 && rbac_user_has_permission($pdo, $userId, $permissionKey);
}

function rbac_current_admin_can_access_module(PDO $pdo, string $moduleKeyOrName): bool
{
    $userId = (int) ($_SESSION['admin_id'] ?? 0);
    return $userId > 0 && rbac_user_can_access_module($pdo, $userId, $moduleKeyOrName);
}

function rbac_deny(PDO $pdo, string $message = 'You do not have permission to access this resource.'): void
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJson = stripos($accept, 'application/json') !== false
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1')
        || preg_match('/_api|api_/', basename($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($isJson) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Forbidden', 'message' => $message]);
        exit;
    }

    flash('error', $message);
    $fallback = rbac_first_accessible_page($pdo);
    redirect($fallback ?: 'admin/logout.php');
}

function rbac_first_accessible_page(PDO $pdo): string
{
    $candidates = [
        'Dashboard' => 'admin/dashboard.php',
        'Students' => 'admin/students.php',
        'Fees' => 'admin/fees.php',
        'Transport Fee Structure' => 'admin/transport_fee_structures.php',
        'Transport' => 'admin/transport.php',
        'Feeding' => 'admin/feeding.php',
        'School Uniform' => 'admin/uniforms.php',
        'School Van Fuel' => 'admin/fuel.php',
        'Kitchen' => 'admin/kitchen.php',
        'Expenses' => 'admin/school_expenses.php',
        'Reports' => 'admin/reports.php',
        'Settings' => 'admin/settings.php',
    ];

    foreach ($candidates as $moduleName => $path) {
        if (rbac_current_admin_can_access_module($pdo, $moduleName)) {
            return $path;
        }
    }

    return 'admin/profile.php';
}

function require_module_access(PDO $pdo, string $moduleKeyOrName, string $message = ''): void
{
    if (!rbac_current_admin_can_access_module($pdo, $moduleKeyOrName)) {
        rbac_deny($pdo, $message !== '' ? $message : 'You do not have access to ' . rbac_module_name($moduleKeyOrName) . '.');
    }
}

function require_action_permission(PDO $pdo, string $permissionKey, string $message = ''): void
{
    if (!rbac_current_admin_has_permission($pdo, $permissionKey)) {
        rbac_deny($pdo, $message !== '' ? $message : 'You do not have permission to ' . rbac_permission_label($permissionKey) . '.');
    }
}

function rbac_page_requirements(string $scriptName): ?array
{
    $map = [
        'dashboard.php' => ['module' => 'Dashboard'],
        'students.php' => ['module' => 'Students', 'permission' => 'students.view'],
        'student_form.php' => ['module' => 'Students'],
        'import_students.php' => ['module' => 'Students', 'permission' => 'students.import'],
        'fees.php' => ['module' => 'Fees'],
        'payment_allocation.php' => ['module' => null],
        'payment_transaction_receipt.php' => ['module' => null],
        'fee_payment_audit.php' => ['module' => 'Payment Audit'],
        'fee_correction_request.php' => ['module' => 'Payment Audit'],
        'fee_corrections.php' => ['module' => 'Payment Audit'],
        'fee_correct.php' => ['module' => 'Payment Audit'],
        'reverse_fee.php' => ['module' => 'Fees'],
        'import_fee_payments.php' => ['module' => 'Fees', 'permission' => 'fees.import'],
        'fee_payment_import_detail.php' => ['module' => 'Fees', 'permission' => 'fees.import'],
        'receipt.php' => ['module' => 'Fees', 'permission' => 'fees.print'],
        'export.php' => ['module' => null],
        'fee_structures.php' => ['module' => 'Settings', 'permission' => 'settings.fee_structure'],
        'class_levels.php' => ['module' => 'Settings', 'permission' => 'settings.manage_class_levels'],
        'transport_fee_structures.php' => ['module' => 'Transport Fee Structure', 'permission' => 'transport_fee_structure.view'],
        'transport.php' => ['module' => 'Transport', 'permission' => 'transport.view'],
        'transport_receipt.php' => ['module' => 'Transport', 'permission' => 'transport.view_receipt'],
        'feeding.php' => ['module' => 'Feeding', 'permission' => 'feeding.reports'],
        'daycare.php' => ['module' => 'Daycare'],
        'daycare_receipt.php' => ['module' => 'Daycare', 'permission' => 'daycare.payments.view'],
        'activities.php' => ['module' => 'Activities & Trips', 'permission' => 'activities.view'],
        'activity_form.php' => ['module' => 'Activities & Trips', 'permission' => 'activities.manage'],
        'activity_reports.php' => ['module' => 'Reports', 'permission' => 'reports.view'],
        'activities_export.php' => ['module' => 'Reports', 'permission' => 'reports.export'],
        'activity_receipt.php' => ['module' => 'Activities & Trips', 'permission' => 'activities.view'],
        'feeding_receipt.php' => ['module' => 'Feeding', 'permission' => 'feeding.view_receipt'],
        'uniforms.php' => ['module' => 'School Uniform'],
        'uniform_form.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.edit_catalog'],
        'uniform_sales.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.sell'],
        'uniform_receipt.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.view_receipt'],
        'uniform_stock.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.edit_catalog'],
        'uniform_stock_form.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.edit_catalog'],
        'uniform_reports.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.view_reports'],
        'uniform_reports_export.php' => ['module' => 'School Uniform', 'permission' => 'school_uniform.export_reports'],
        'fuel.php' => ['module' => 'School Van Fuel', 'permission' => 'school_van_fuel.view_records'],
        'fuel_form.php' => ['module' => 'School Van Fuel', 'permission' => 'school_van_fuel.add_fuel'],
        'vehicles.php' => ['module' => 'School Van Fuel', 'permission' => 'school_van_fuel.view_vehicles'],
        'fuel_reports.php' => ['module' => 'School Van Fuel', 'permission' => 'school_van_fuel.view_reports'],
        'fuel_reports_export.php' => ['module' => 'School Van Fuel', 'permission' => 'school_van_fuel.export_reports'],
        'kitchen.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_stock_taking.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_expenditure.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.expenditure.view'],
        'kitchen_export.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.export'],
        'kitchen_inventory.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_items.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_items_api.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_usage.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_weekly_shopping.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_daily_purchase.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_reports.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.expenditure.view'],
        'kitchen_reports_export.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'kitchen_consumption_report.php' => ['module' => 'Kitchen', 'permission' => 'kitchen.view'],
        'school_expenses.php' => ['module' => 'Expenses', 'permission' => 'expenses.view'],
        'reports.php' => ['module' => 'Reports', 'permission' => 'reports.view'],
        'reports_export.php' => ['module' => 'Reports', 'permission' => 'reports.export'],
        'bulk_term_receipts.php' => ['module' => 'Reports', 'permission' => 'reports.export'],
        'academic_calendar.php' => ['module' => 'Academic Calendar', 'permission' => 'academic_calendar.manage'],
        'settings.php' => ['module' => 'Settings'],
        'users.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'parent_accounts.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'roles.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'users_permissions.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'user_permissions_detail.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'role_permissions.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'api_role_details.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'migrate_kitchen.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
        'migrate_wholesale.php' => ['module' => 'Settings', 'permission' => 'settings.manage_users'],
    ];

    return $map[$scriptName] ?? null;
}

/**
 * Log a permission check or denial to the audit log table.
 * @param PDO $pdo Database connection
 * @param int|null $userId Admin user ID (null for anonymous)
 * @param string $action Permission action being checked (e.g., 'students.view', 'fees.record')
 * @param string|null $resource Resource being accessed (e.g., module name, API endpoint)
 * @param bool $granted Whether the permission was granted
 * @param string|null $level Log level: 'INFO' (default), 'WARN', 'DENY', 'ERROR'
 * @param array|null $details Additional context as JSON
 * @return void
 */
function rbac_audit_log(
    PDO $pdo,
    ?int $userId,
    string $action,
    ?string $resource = null,
    bool $granted = false,
    ?string $level = null,
    ?array $details = null
): void {
    try {
        rbac_ensure_schema($pdo);
        
        $level = $level ?: ($granted ? 'INFO' : 'DENY');
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, resource, granted, level, details, ip_address, user_agent)
             VALUES (:user_id, :action, :resource, :granted, :level, :details, :ip_address, :user_agent)"
        );
        
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'granted' => (int) $granted,
            'level' => $level,
            'details' => $details ? json_encode($details) : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    } catch (Throwable $e) {
        // Silently fail logging - don't break the app if audit logging fails
    }
}

/**
 * Require a specific permission. If user doesn't have it, deny access with 403.
 * Returns JSON error for API requests, redirects for page requests.
 * 
 * @param PDO $pdo Database connection
 * @param string $permissionKey Permission to check
 * @param string|null $message Custom error message
 * @return void
 */
function rbac_require_permission(PDO $pdo, string $permissionKey, ?string $message = null): void
{
    $userId = (int) ($_SESSION['admin_id'] ?? 0);
    
    if (!($userId > 0 && rbac_user_has_permission($pdo, $userId, $permissionKey))) {
        $label = rbac_permission_label($permissionKey);
        $message = $message ?: "You do not have permission to {$label}.";
        
        rbac_audit_log($pdo, $userId, $permissionKey, null, false, 'DENY', [
            'type' => 'permission_check',
            'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
        ]);
        
        rbac_deny($pdo, $message);
    }
    
    rbac_audit_log($pdo, $userId, $permissionKey, null, true, 'INFO', [
        'type' => 'permission_check',
        'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
    ]);
}

function require_permission(PDO $pdo, string $permissionKey, ?string $message = null): void
{
    rbac_require_permission($pdo, $permissionKey, $message);
}

/**
 * Require module access. If user doesn't have it, deny access with 403.
 * Returns JSON error for API requests, redirects for page requests.
 * 
 * @param PDO $pdo Database connection
 * @param string $moduleKeyOrName Module name or key
 * @param string|null $message Custom error message
 * @return void
 */
function rbac_require_module_access(PDO $pdo, string $moduleKeyOrName, ?string $message = null): void
{
    $userId = (int) ($_SESSION['admin_id'] ?? 0);
    
    if (!($userId > 0 && rbac_user_can_access_module($pdo, $userId, $moduleKeyOrName))) {
        $moduleName = rbac_module_name($moduleKeyOrName);
        $message = $message ?: "You do not have access to {$moduleName}.";
        
        rbac_audit_log($pdo, $userId, 'module_access', $moduleKeyOrName, false, 'DENY', [
            'type' => 'module_access_check',
            'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
        ]);
        
        rbac_deny($pdo, $message);
    }
    
    rbac_audit_log($pdo, $userId, 'module_access', $moduleKeyOrName, true, 'INFO', [
        'type' => 'module_access_check',
        'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
    ]);
}

function rbac_enforce_page_access(PDO $pdo): void
{
    rbac_ensure_schema($pdo);
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($scriptName, ['login.php', 'logout.php', 'profile.php'], true)) {
        return;
    }

    $requirements = rbac_page_requirements($scriptName);
    if (!$requirements) {
        return;
    }

    if (!empty($requirements['module'])) {
        require_module_access($pdo, $requirements['module']);
    }
    if (!empty($requirements['permission'])) {
        require_action_permission($pdo, $requirements['permission']);
    }
}
