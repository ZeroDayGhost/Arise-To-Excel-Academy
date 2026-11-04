<?php
$pageTitle = 'Uniform Stock Management';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access uniform stock.');
    redirect('admin/dashboard.php');
}
$canManageUniform = current_admin_has_permission($pdo, 'school_uniform.inventory');
$uniformFilter = (int) ($_GET['uniform_id'] ?? 0);
$sql = "SELECT u.id AS product_id, u.uniform_name, u.category, u.gender, uv.id AS variant_id, uv.size,
               uv.opening_stock, uv.reorder_level, uv.status,
               uv.opening_stock + COALESCE(SUM(sm.quantity),0) AS available_stock
        FROM uniforms u
        INNER JOIN uniform_variants uv ON uv.uniform_id = u.id
        LEFT JOIN uniform_stock_movements sm ON sm.uniform_variant_id = uv.id";
if ($uniformFilter > 0) $sql .= ' WHERE u.id = :uniform_id';
$sql .= ' GROUP BY u.id, uv.id ORDER BY available_stock ASC, u.uniform_name, uv.size';
$statement = $pdo->prepare($sql);
$statement->execute($uniformFilter > 0 ? ['uniform_id' => $uniformFilter] : []);
$variants = $statement->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Inventory</p><h1>Uniform Stock</h1><p class="mb-0 text-muted">Track available quantities separately for every uniform size.</p></div>
    <div class="action-row"><a class="btn btn-outline-primary" href="<?= url('admin/uniforms.php') ?>">Catalog</a></div>
</div>
<section class="panel">
    <h2>Stock by Size</h2>
    <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Uniform</th><th>Gender</th><th>Category</th><th>Size</th><th>Opening</th><th>Available</th><th>Reorder</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($variants as $variant): $isLow = strtolower(trim($variant['status'])) === 'active' && (int)$variant['available_stock'] <= (int)$variant['reorder_level']; ?>
            <tr class="<?= $isLow ? 'table-warning' : '' ?>"><td><?= h($variant['uniform_name']) ?></td><td><?= h(['Female' => 'Girls', 'female' => 'Girls', 'Male' => 'Boys', 'male' => 'Boys'][$variant['gender']] ?? $variant['gender']) ?></td><td><?= h($variant['category']) ?></td><td><?= h($variant['size']) ?></td><td><?= number_format((int)$variant['opening_stock']) ?></td><td><strong><?= number_format((int)$variant['available_stock']) ?></strong><?= $isLow ? ' <span class="badge text-bg-warning">Low</span>' : '' ?></td><td><?= number_format((int)$variant['reorder_level']) ?></td><td><?= h($variant['status']) ?></td><td><?php if ($canManageUniform): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/uniform_stock_form.php?id=' . (int)$variant['product_id'] . '&variant_id=' . (int)$variant['variant_id']) ?>">Adjust</a><?php else: ?><span class="text-muted">View only</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$variants): ?><tr><td colspan="9" class="text-center text-muted py-4">No uniform sizes found.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
