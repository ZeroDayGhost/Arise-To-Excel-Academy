<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = in_array('--apply', $argv, true);
$host = getenv('ARISE_DB_HOST') ?: 'localhost';
$name = getenv('ARISE_DB_NAME') ?: 'arise_to_excel';
$user = getenv('ARISE_DB_USER') ?: 'root';
$pass = getenv('ARISE_DB_PASS') ?: '';
$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sqlFile = __DIR__ . '/20261024_daycare.sql';
$sql = file_get_contents($sqlFile);
if (!is_string($sql)) {
    throw new RuntimeException('Unable to read the daycare schema file.');
}
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn($part) => $part !== ''));
$existingTables = [];
foreach ($pdo->query("SHOW TABLES LIKE 'daycare_%'")->fetchAll(PDO::FETCH_NUM) as $row) {
    $existingTables[] = (string) $row[0];
}

if (!$apply) {
    echo 'Daycare schema preflight for database ' . $name . ".\n";
    echo count($statements) . ' idempotent table statements found; ' . count($existingTables) . " daycare tables currently exist.\n";
    echo "No database changes were made. Back up the database, then run this script with --apply.\n";
    exit(0);
}

$lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 30)');
$lock->execute(['lock_name' => 'arise_daycare_schema_migration']);
if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Another daycare migration is running. Try again later.\n");
    exit(1);
}

try {
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    require_once __DIR__ . '/../../includes/rbac.php';
    rbac_ensure_schema($pdo);
    $roleGrants = [
        'Director' => array_merge(['daycare.access'], array_keys(rbac_module_definitions()['daycare']['actions'])),
        'Accountant' => ['daycare.access', 'daycare.children.view', 'daycare.payments.view', 'daycare.payments.record', 'daycare.reports.view'],
        'Receptionist' => ['daycare.access', 'daycare.children.view', 'daycare.children.manage', 'daycare.guardians.manage', 'daycare.payments.view', 'daycare.payments.record'],
        'Headteacher' => ['daycare.access', 'daycare.children.view', 'daycare.health.view', 'daycare.reports.view'],
        'Teacher' => ['daycare.access', 'daycare.children.view'],
    ];
    $moduleInsert = $pdo->prepare('INSERT IGNORE INTO module_access (role_id,module_name,allowed) VALUES (:role,:module,1)');
    $permissionLookup = $pdo->prepare('SELECT id FROM permissions WHERE permission_key=:key');
    $permissionInsert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id) VALUES (:role,:permission)');

    foreach ($roleGrants as $roleName => $keys) {
        $role = $pdo->prepare('SELECT id FROM roles WHERE LOWER(TRIM(COALESCE(NULLIF(role_name,\'\'),name)))=:name LIMIT 1');
        $role->execute(['name' => strtolower($roleName)]);
        $roleId = (int) $role->fetchColumn();
        if (!$roleId) continue;
        $moduleInsert->execute(['role' => $roleId, 'module' => 'Daycare']);
        foreach (array_filter($keys, static fn($key) => $key !== 'daycare.access') as $permissionKey) {
            $permissionLookup->execute(['key' => $permissionKey]);
            $permissionId = (int) $permissionLookup->fetchColumn();
            if ($permissionId) $permissionInsert->execute(['role' => $roleId, 'permission' => $permissionId]);
        }
    }

    echo 'Daycare schema applied. ' . count($statements) . " idempotent schema statements completed.\n";
    echo "Only known staff role grants were extended; existing user overrides and financial records were left unchanged.\n";
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arise_daycare_schema_migration')");
}
