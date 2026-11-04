<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen_stock_taking.php#stock-records');

// Module access
if (!current_admin_has_permission($pdo, 'kitchen.access')) {
    flash('error', 'You do not have permission to access Kitchen items.');
    redirect('admin/dashboard.php');
}

$canManageInventory = current_admin_has_permission($pdo, 'kitchen.inventory');

$pdo = get_pdo();
ensure_kitchen_tables($pdo);

$action = $_POST['action'] ?? '';
if ($action === 'save') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim($_POST['item_name'] ?? '');
    $unit = $_POST['unit'] ?? 'kg';
    $category = $_POST['category'] ?? 'Kitchen';
    $min = isset($_POST['min_stock_level']) ? (float)$_POST['min_stock_level'] : 0.0;
    $status = $_POST['status'] ?? 'active';

    if (!$canManageInventory) {
        flash('error', 'You do not have permission to manage kitchen inventory items.');
        header('Location: ' . url('admin/kitchen_items.php'));
        exit;
    }

    if ($name === '' || !array_key_exists($unit, kitchen_available_unit_options($pdo))) {
        flash('error', 'Enter an item name and choose a valid quantity unit.');
        header('Location: ' . url('admin/kitchen_items.php'));
        exit;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE kitchen_items SET item_name = :item_name, unit = :unit, category = :category, min_stock_level = :min_stock_level, status = :status WHERE id = :id");
        $stmt->execute(['item_name' => $name, 'unit' => $unit, 'category' => $category, 'min_stock_level' => $min, 'status' => $status, 'id' => $id]);
        flash('success', 'Item updated');
    } else {
        $item = kitchen_get_or_create_item($pdo, $name, $category, 0.0, $min, $unit);
        flash('success', 'Item created');
    }
    header('Location: ' . url('admin/kitchen_items.php'));
    exit;
}

$q = trim($_GET['q'] ?? '');
$stmt = $pdo->prepare("SELECT * FROM kitchen_items WHERE item_name LIKE :like ORDER BY item_name ASC");
$stmt->execute(['like' => '%' . $q . '%']);
$items = $stmt->fetchAll();
require_once __DIR__ . '/../includes/admin_header.php';

?>
<div class="container-fluid">
    <h1>Kitchen Item Master</h1>
    <div class="mb-3">
        <form method="get" class="row g-2">
            <div class="col-md-4"><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search items"></div>
            <div class="col-md-2"><button class="btn btn-outline-primary">Search</button></div>
            <div class="col-md-6 text-end"><a class="btn btn-primary" href="<?= url('admin/kitchen_items.php') ?>">Refresh</a></div>
        </form>
    </div>

    <div class="row">
        <div class="col-md-6">
            <h3>Items</h3>
            <table class="table table-sm table-striped">
                <thead><tr><th>Item</th><th>Unit</th><th>Min Level</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= h($it['item_name']) ?></td>
                            <td><?= h($it['unit']) ?></td>
                            <td><?= h((string)$it['min_stock_level']) ?></td>
                            <td><?= h($it['category']) ?></td>
                            <td><?= h($it['status']) ?></td>
                            <td>
                                <form method="post" style="display:inline-block">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary">Edit</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="col-md-6">
            <h3><?= isset($_POST['action']) && $_POST['action'] === 'edit' ? 'Edit Item' : 'Create Item' ?></h3>
            <?php
            $edit = null;
            if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'])) {
                $edit = $pdo->prepare('SELECT * FROM kitchen_items WHERE id = :id');
                $edit->execute(['id' => (int)$_POST['id']]);
                $edit = $edit->fetch();
            }
            $kitchenUnits = kitchen_available_unit_options($pdo);
            if (!empty($edit['unit']) && !array_key_exists($edit['unit'], $kitchenUnits)) {
                $kitchenUnits[$edit['unit']] = $edit['unit'];
            }
            ?>
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="mb-2"><label class="form-label">Item Name</label><input class="form-control" name="item_name" value="<?= h($edit['item_name'] ?? '') ?>" required></div>
                <div class="mb-2"><label class="form-label">Quantity Unit</label><select class="form-select" name="unit"><?php foreach ($kitchenUnits as $v=>$l): ?><option value="<?= h($v) ?>" <?= (isset($edit['unit']) && $edit['unit']===$v)?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Min Stock Level</label><input class="form-control" name="min_stock_level" type="number" step="0.01" value="<?= h((string)($edit['min_stock_level'] ?? 0)) ?>"></div>
                <div class="mb-2"><label class="form-label">Category</label><input class="form-control" name="category" value="<?= h($edit['category'] ?? 'Kitchen') ?>"></div>
                <div class="mb-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?= (isset($edit['status']) && $edit['status']==='active')?'selected':'' ?>>Active</option><option value="disabled" <?= (isset($edit['status']) && $edit['status']==='disabled')?'selected':'' ?>>Disabled</option></select></div>
                <div class="mb-2">
                    <?php if ($canManageInventory): ?>
                        <button class="btn btn-primary">Save</button>
                    <?php else: ?>
                        <button class="btn btn-primary" type="button" disabled>Save</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php';
