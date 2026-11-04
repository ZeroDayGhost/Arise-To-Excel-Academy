<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
// Simple API to list and add kitchen units. GET returns JSON list, POST adds a unit.
$pdo = get_pdo();
kitchen_require_access($pdo);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT code, label FROM kitchen_units ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        // fall back to defaults from includes if table empty or not present
        require_once __DIR__ . '/../includes/fee_helpers.php';
        $defaults = kitchen_unit_options();
        $out = [];
        foreach ($defaults as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }
        echo json_encode($out);
        exit;
    }
    echo json_encode($rows);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kitchen_require_access($pdo, true);
    $payload = json_decode(file_get_contents('php://input'), true);
    $value = trim((string) ($payload['value'] ?? ''));
    if ($value === '') {
        http_response_code(400);
        echo json_encode(['error' => 'empty_value']);
        exit;
    }
    // create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_units (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(80) NOT NULL UNIQUE,
        label VARCHAR(120) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $code = preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($value));
    $label = $value;
    // attempt insert, ignore duplicates
    try {
        $stmt = $pdo->prepare('INSERT INTO kitchen_units (code, label) VALUES (:code, :label)');
        $stmt->execute(['code' => $code, 'label' => $label]);
    } catch (PDOException $ex) {
        // ignore duplicate key
    }
    echo json_encode(['code' => $code, 'label' => $label]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
