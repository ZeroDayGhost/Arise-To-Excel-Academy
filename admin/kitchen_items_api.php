<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = get_pdo();
kitchen_require_access($pdo);
http_response_code(410);
echo json_encode(['error' => 'This endpoint has been replaced by Kitchen Stock Taking.']);
exit;
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!current_admin_has_permission($pdo, 'kitchen.access')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $q = trim($_GET['q'] ?? '');
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($q === '') {
        echo json_encode([]);
        exit;
    }

    $like = '%' . $q . '%';
    $norm = '%' . mb_strtolower($q) . '%';

    // Get items from Item Master
    $stmt1 = $pdo->prepare("SELECT id, item_name, unit, min_stock_level, category, status FROM kitchen_items WHERE status = 'active' AND (item_name LIKE :like OR LOWER(item_name) LIKE :norm OR item_name_norm LIKE :norm OR SOUNDEX(item_name) = SOUNDEX(:q)) ORDER BY item_name ASC LIMIT :limit");
    $stmt1->bindValue(':like', $like, PDO::PARAM_STR);
    $stmt1->bindValue(':norm', $norm, PDO::PARAM_STR);
    $stmt1->bindValue(':q', $q, PDO::PARAM_STR);
    $stmt1->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt1->execute();
    $masterItems = $stmt1->fetchAll();
    
    // Get recently used purchase item names from all kitchen activity tables that are not yet in Item Master.
    $stmt2 = $pdo->prepare(
        "SELECT DISTINCT item_name, unit FROM ("
        . "SELECT item_name, unit FROM weekly_shopping_items "
        . "UNION ALL SELECT item_name, unit FROM kitchen_daily_purchases "
        . "UNION ALL SELECT item_name, unit FROM kitchen_inventory "
        . ") AS recent_items "
        . "WHERE (item_name LIKE :like OR LOWER(item_name) LIKE :norm OR SOUNDEX(item_name) = SOUNDEX(:q)) "
        . "AND LOWER(TRIM(item_name)) NOT IN (SELECT LOWER(TRIM(ki.item_name)) FROM kitchen_items ki WHERE ki.status = 'active') "
        . "ORDER BY item_name ASC "
        . "LIMIT :limit"
    );
    $stmt2->bindValue(':like', $like, PDO::PARAM_STR);
    $stmt2->bindValue(':norm', $norm, PDO::PARAM_STR);
    $stmt2->bindValue(':q', $q, PDO::PARAM_STR);
    $stmt2->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt2->execute();
    $historyItems = $stmt2->fetchAll();
    
    // Convert history items to Item Master items for consistency
    $results = $masterItems;
    foreach ($historyItems as $hitem) {
        $item = kitchen_get_or_create_item($pdo, $hitem['item_name'], 'Kitchen', 0.0, 0.0, kitchen_validate_unit($hitem['unit'] ?? 'kg'));
        $results[] = [
            'id' => $item['id'],
            'item_name' => $item['item_name'],
            'unit' => $item['unit'],
            'min_stock_level' => $item['min_stock_level'],
            'category' => $item['category'],
            'status' => $item['status']
        ];
    }
    
    echo json_encode($results);
    exit;
}

if ($method === 'POST') {
    if (!current_admin_has_permission($pdo, 'kitchen.inventory')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['item_name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'item_name required']);
        exit;
    }
    $unit = $data['unit'] ?? 'kg';
    $category = $data['category'] ?? 'Kitchen';
    $opening = isset($data['opening_stock']) ? (float)$data['opening_stock'] : 0.0;
    $min = isset($data['min_stock_level']) ? (float)$data['min_stock_level'] : 0.0;

    // use helper to create or fetch existing
    $item = kitchen_get_or_create_item($pdo, $name, $category, $opening, $min, $unit);
    echo json_encode($item);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
