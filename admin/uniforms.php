<?php
$pageTitle = 'Uniform Catalog';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access School Uniform.');
    redirect('admin/dashboard.php');
}

$canSellUniform = current_admin_has_permission($pdo, 'school_uniform.sell');
$canManageUniform = current_admin_has_permission($pdo, 'school_uniform.inventory');
$search = trim((string) ($_GET['q'] ?? ''));
$genderFilter = trim((string) ($_GET['gender'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$lowOnly = ($_GET['low_stock'] ?? '') === '1';
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(u.uniform_name LIKE :search_name OR u.category LIKE :search_category)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_category'] = '%' . $search . '%';
}
if (in_array($genderFilter, ['Boys', 'Girls', 'Unisex'], true)) {
    $where[] = "CASE
        WHEN LOWER(TRIM(u.gender)) IN ('female', 'girl', 'girls') THEN 'Girls'
        WHEN LOWER(TRIM(u.gender)) IN ('male', 'boy', 'boys') THEN 'Boys'
        ELSE 'Unisex' END = :gender";
    $params['gender'] = $genderFilter;
}
if ($categoryFilter !== '') {
    $where[] = 'u.category = :category';
    $params['category'] = $categoryFilter;
}
$sql = "SELECT u.id AS product_id, u.uniform_name, u.category, u.gender, u.status AS product_status, u.description,
               uv.id AS variant_id, uv.size, uv.selling_price, uv.opening_stock, uv.reorder_level, uv.status AS variant_status,
               uv.opening_stock + COALESCE(SUM(sm.quantity), 0) AS available_stock
        FROM uniforms u
        LEFT JOIN uniform_variants uv ON uv.uniform_id = u.id
        LEFT JOIN uniform_stock_movements sm ON sm.uniform_variant_id = uv.id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' GROUP BY u.id, uv.id ORDER BY u.category, u.uniform_name, uv.id';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$products = [];
foreach ($statement->fetchAll() as $row) {
    $productId = (int) $row['product_id'];
    if (!isset($products[$productId])) {
        $products[$productId] = [
            'id' => $productId, 'uniform_name' => $row['uniform_name'], 'category' => $row['category'],
            'gender' => $row['gender'], 'status' => $row['product_status'], 'variants' => [],
        ];
    }
    if ($row['variant_id'] !== null) {
        $products[$productId]['variants'][] = [
            'id' => (int) $row['variant_id'], 'size' => $row['size'], 'selling_price' => (float) $row['selling_price'],
            'opening_stock' => (int) $row['opening_stock'], 'reorder_level' => (int) $row['reorder_level'],
            'status' => $row['variant_status'], 'available_stock' => (int) $row['available_stock'],
        ];
    }
}
if ($lowOnly) {
    $products = array_filter($products, static function (array $product): bool {
        foreach ($product['variants'] as $variant) {
            if (strtolower(trim($variant['status'])) === 'active' && $variant['available_stock'] <= $variant['reorder_level']) return true;
        }
        return false;
    });
}
$categories = $pdo->query('SELECT DISTINCT category FROM uniforms ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div class="uniform-hero-copy">
        <span class="uniform-hero-icon"><i class="fa-solid fa-shirt" aria-hidden="true"></i></span>
        <div><p class="eyebrow">Inventory</p><h1>Uniform Catalog</h1><p class="mb-0 text-muted">Manage uniform items, size pricing and available stock.</p></div>
    </div>
    <div class="uniform-hero-actions">
        <?php if ($canManageUniform): ?><a class="btn btn-primary" href="<?= url('admin/uniform_form.php') ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Uniform</a><?php endif; ?>
        <?php if ($canSellUniform): ?><a class="btn btn-outline-primary" href="<?= url('admin/uniform_sales.php') ?>"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i> Sell Uniform</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= url('admin/uniform_stock.php') ?>"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Stock</a>
    </div>
</div>

<section class="panel">
    <form method="get" class="uniform-filters mb-4">
        <div><label class="form-label" for="uniform-gender-filter">Gender</label><select class="form-select" id="uniform-gender-filter" name="gender"><option value="">All genders</option><?php foreach (['Girls','Boys','Unisex'] as $gender): ?><option value="<?= h($gender) ?>" <?= $genderFilter === $gender ? 'selected' : '' ?>><?= h($gender) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="uniform-category-filter">Category</label><select class="form-select" id="uniform-category-filter" name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= h($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= h($category) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="uniform-search">Uniform name</label><input class="form-control" id="uniform-search" name="q" value="<?= h($search) ?>" placeholder="Search uniforms"></div>
        <label class="uniform-low-stock"><input type="checkbox" name="low_stock" value="1" <?= $lowOnly ? 'checked' : '' ?>><span class="uniform-switch" aria-hidden="true"></span><span>Low stock only</span></label>
        <div class="uniform-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</button><a class="btn btn-outline-secondary" href="<?= url('admin/uniforms.php') ?>"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset</a></div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped align-middle uniform-catalog-table">
            <thead><tr><th>#</th><th>Uniform name</th><th>Category</th><th>Gender</th><th>Sizes &amp; prices</th><th>Stock details</th><th>Reorder level</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($products as $productIndex => $product): ?>
                    <?php
                    $activeVariants = array_values(array_filter($product['variants'], static fn(array $variant): bool => strtolower(trim($variant['status'])) === 'active'));
                    $lowCount = count(array_filter($activeVariants, static fn(array $variant): bool => strtolower(trim($variant['status'])) === 'active' && $variant['available_stock'] <= $variant['reorder_level']));
                    $genderKey = strtolower(trim((string) $product['gender']));
                    $displayGender = in_array($genderKey, ['female', 'girl', 'girls'], true) ? 'Girls' : (in_array($genderKey, ['male', 'boy', 'boys'], true) ? 'Boys' : 'Unisex');
                    $sizePriceSummary = array_map(static fn(array $variant): string => $variant['size'] . ': ' . money($variant['selling_price']), $activeVariants);
                    $reorderLevels = array_map(static fn(array $variant): int => $variant['reorder_level'], $activeVariants);
                    $statusActive = strtolower(trim((string) $product['status'])) === 'active';
                    ?>
                    <tr>
                        <td><?= $productIndex + 1 ?></td>
                        <td><strong><?= h($product['uniform_name']) ?></strong><?php if ($lowCount): ?><div><span class="badge text-bg-warning mt-1"><?= $lowCount ?> low-stock size<?= $lowCount === 1 ? '' : 's' ?></span></div><?php endif; ?></td>
                        <td><?= h($product['category']) ?></td>
                        <td><span class="uniform-gender-badge <?= $displayGender === 'Girls' ? 'girls' : ($displayGender === 'Boys' ? 'boys' : 'unisex') ?>"><?= h($displayGender) ?></span></td>
                        <td>
                            <?php if ($sizePriceSummary): ?><?= h(implode(' · ', $sizePriceSummary)) ?><?php else: ?><span class="text-muted">No active sizes</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($product['variants']): ?>
                                <details class="uniform-variant-details" id="uniform-sizes-<?= $product['id'] ?>">
                                    <summary><i class="fa-solid fa-chevron-down" aria-hidden="true"></i> View sizes</summary>
                                    <div class="table-responsive mt-2"><table class="table table-sm mb-0"><thead><tr><th>Size</th><th>Price</th><th>Stock</th><th>Reorder</th><th>Status</th></tr></thead><tbody>
                                        <?php foreach ($product['variants'] as $variant): ?>
                                            <tr class="<?= strtolower(trim($variant['status'])) === 'active' && $variant['available_stock'] <= $variant['reorder_level'] ? 'table-warning' : '' ?>"><td><?= h($variant['size']) ?></td><td><?= money($variant['selling_price']) ?></td><td><?= number_format($variant['available_stock']) ?></td><td><?= number_format($variant['reorder_level']) ?></td><td><?= h($variant['status']) ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody></table></div>
                                </details>
                            <?php else: ?><span class="text-muted">No sizes</span><?php endif; ?>
                        </td>
                        <td><?= $reorderLevels ? number_format(min($reorderLevels)) : '—' ?></td>
                        <td><span class="uniform-status-badge <?= $statusActive ? 'active' : 'inactive' ?>"><?= h($product['status']) ?></span></td>
                        <td><div class="uniform-row-actions">
                            <?php if ($canManageUniform): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/uniform_form.php?id=' . $product['id']) ?>" title="Edit uniform" aria-label="Edit <?= h($product['uniform_name']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/uniform_stock.php?uniform_id=' . $product['id']) ?>" title="Adjust stock" aria-label="Adjust stock for <?= h($product['uniform_name']) ?>"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></a>
                                <form method="post" action="<?= url('admin/uniform_form.php?id=' . $product['id']) ?>" onsubmit="return confirm('Delete this uniform? Any uniform with sales or stock history will be retained.');"><button class="btn btn-sm btn-outline-danger" type="submit" name="delete" value="1" title="Delete uniform" aria-label="Delete <?= h($product['uniform_name']) ?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button></form>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr><td colspan="9" class="text-center text-muted py-4">No matching uniform products.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="uniform-list-footer"><span>Showing <?= count($products) ?> uniform item<?= count($products) === 1 ? '' : 's' ?></span><span><?= $lowOnly ? 'Low-stock filter active' : 'All stock levels' ?></span></div>
</section>
<style>
.uniform-hero-copy{display:flex;align-items:center;gap:1rem}.uniform-hero-icon{width:64px;height:64px;display:grid;place-items:center;border-radius:14px;background:#e8f4fc;color:#1689c8;font-size:1.7rem;flex:0 0 auto}.uniform-hero-actions,.uniform-filter-actions,.uniform-row-actions{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.uniform-filters{display:grid;grid-template-columns:minmax(140px,.8fr) minmax(160px,.9fr) minmax(200px,1.1fr) minmax(155px,.75fr) auto;gap:1rem;align-items:end}.uniform-filters .form-label{font-size:.82rem;font-weight:650;color:#586a7a;margin-bottom:.4rem}.uniform-low-stock{min-height:40px;display:flex;align-items:center;gap:.55rem;cursor:pointer;font-weight:600;white-space:nowrap}.uniform-low-stock input{position:absolute;opacity:0}.uniform-switch{width:40px;height:23px;border-radius:20px;background:#cbd5df;position:relative;transition:background .18s}.uniform-switch:after{content:"";position:absolute;width:17px;height:17px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px #0002;transition:transform .18s}.uniform-low-stock input:checked+.uniform-switch{background:#1689c8}.uniform-low-stock input:checked+.uniform-switch:after{transform:translateX(17px)}.uniform-low-stock input:focus-visible+.uniform-switch{outline:3px solid #1689c866;outline-offset:2px}.uniform-filter-actions{justify-content:flex-end}.uniform-catalog-table{min-width:1120px}.uniform-catalog-table thead th{background:#e9f3fc;color:#18456b;white-space:nowrap;font-size:.76rem}.uniform-catalog-table td{vertical-align:middle}.uniform-gender-badge,.uniform-status-badge{display:inline-flex;align-items:center;justify-content:center;padding:.3rem .65rem;border-radius:20px;font-size:.78rem;font-weight:650;white-space:nowrap}.uniform-gender-badge.girls{color:#9c315f;background:#fce4ef}.uniform-gender-badge.boys{color:#245a98;background:#e2efff}.uniform-gender-badge.unisex{color:#4c6074;background:#edf1f4}.uniform-status-badge.active{color:#13764e;background:#e0f5ea}.uniform-status-badge.inactive{color:#59636d;background:#edf0f2}.uniform-variant-details summary{list-style:none;color:#087fb8;cursor:pointer;font-weight:650;white-space:nowrap}.uniform-variant-details summary::-webkit-details-marker{display:none}.uniform-variant-details summary i{font-size:.7rem;margin-right:.25rem}.uniform-variant-details[open] summary i{transform:rotate(180deg)}.uniform-variant-details .table{min-width:440px;font-size:.84rem}.uniform-variant-details .table th{background:#f4f8fb}.uniform-row-actions{flex-wrap:nowrap}.uniform-row-actions form{margin:0}.uniform-row-actions .btn{width:34px;height:34px;display:grid;place-items:center;padding:0}.uniform-list-footer{display:flex;justify-content:space-between;gap:1rem;padding-top:1rem;color:#697a89;font-size:.86rem}@media(max-width:1050px){.uniform-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.uniform-filter-actions{justify-content:flex-start}}@media(max-width:700px){.page-title{align-items:flex-start;flex-direction:column}.uniform-hero-actions{width:100%}.uniform-filters{grid-template-columns:1fr}.uniform-filter-actions>*{flex:1}.uniform-list-footer{flex-direction:column;gap:.25rem}}
</style>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
