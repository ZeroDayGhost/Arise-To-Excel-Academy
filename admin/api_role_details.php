<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Check permission
if (!current_admin_has_permission($pdo, 'users_permissions.access')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$roleId = (int) ($_GET['role_id'] ?? 0);

if ($roleId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid role ID']);
    exit;
}

// Get role details
$roleStmt = $pdo->prepare('SELECT id, name, description FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute(['id' => $roleId]);
$role = $roleStmt->fetch();

if (!$role) {
    http_response_code(404);
    echo json_encode(['error' => 'Role not found']);
    exit;
}

// Get role permissions
$permStmt = $pdo->prepare(
    'SELECT rp.permission_id, p.permission_key
     FROM role_permissions rp 
     JOIN permissions p ON p.id = rp.permission_id 
     WHERE rp.role_id = :role_id 
     ORDER BY p.permission_key ASC'
);
$permStmt->execute(['role_id' => $roleId]);
$dbPermissions = $permStmt->fetchAll();

// Build permission list with descriptions from permission_definitions()
$permDefs = permission_definitions();
$permDefFlat = [];

// Flatten permission definitions to map keys to descriptions
foreach ($permDefs as $moduleName => $moduleDef) {
    if (isset($moduleDef['permission_key'])) {
        $permDefFlat[$moduleDef['permission_key']] = $moduleDef['label'] ?? $moduleName;
    }
    if (isset($moduleDef['actions']) && is_array($moduleDef['actions'])) {
        foreach ($moduleDef['actions'] as $actionKey => $actionLabel) {
            $permDefFlat[$actionKey] = $actionLabel;
        }
    }
}

// Build final permissions array with descriptions
$permissions = [];
foreach ($dbPermissions as $perm) {
    if (!array_key_exists($perm['permission_key'], $permDefFlat)) {
        continue;
    }
    $permissions[] = [
        'permission_id' => $perm['permission_id'],
        'permission_key' => $perm['permission_key'],
        'description' => $permDefFlat[$perm['permission_key']] ?? '',
    ];
}

// Extract unique modules from permissions
$modules = [];
foreach ($permissions as $perm) {
    $moduleName = explode('.', $perm['permission_key'])[0];
    if (!in_array($moduleName, $modules, true)) {
        $modules[] = $moduleName;
    }
}
sort($modules);

echo json_encode([
    'role' => $role,
    'modules' => $modules,
    'permissions' => $permissions,
]);
exit;
?>
