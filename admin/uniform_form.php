<?php
$pageTitle = 'Uniform Item';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access School Uniform.');
    redirect('admin/dashboard.php');
}

$canEditCatalog = current_admin_has_permission($pdo, 'school_uniform.edit_catalog');
$id = (int) ($_GET['id'] ?? 0);
$error = flash('error');
$success = flash('success');
$uniform = null;
$variants = [];

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM uniforms WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $uniform = $stmt->fetch();
    if (!$uniform) {
        flash('error', 'Uniform not found.');
        redirect('admin/uniforms.php');
    }
    // Load inactive sizes too; changing a product's status must not drop its configured variants.
    $variantStmt = $pdo->prepare('SELECT * FROM uniform_variants WHERE uniform_id = :uniform_id ORDER BY id');
    $variantStmt->execute(['uniform_id' => $id]);
    $variants = $variantStmt->fetchAll();
    if (!$variants) {
        $variants[] = [
            'id' => 0,
            'size' => $uniform['size'],
            'selling_price' => $uniform['selling_price'],
            'opening_stock' => $uniform['opening_stock'],
            'reorder_level' => $uniform['reorder_level'],
        ];
    }
}

if (!$variants) {
    $variants[] = ['id' => 0, 'size' => '', 'selling_price' => '', 'opening_stock' => 0, 'reorder_level' => 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEditCatalog) {
        flash('error', 'You do not have permission to edit the uniform catalog.');
        redirect('admin/uniforms.php');
    }

    if (isset($_POST['delete']) && $id > 0) {
        $used = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM uniform_sale_items WHERE uniform_id = :sale_uniform) OR EXISTS(SELECT 1 FROM uniform_stock_movements WHERE uniform_id = :movement_uniform)');
        $used->execute(['sale_uniform' => $id, 'movement_uniform' => $id]);
        if ((int) $used->fetchColumn() === 1) {
            flash('error', 'This uniform has sales or stock history and cannot be deleted. Set it to Inactive to preserve its records.');
        } else {
            $pdo->prepare('DELETE FROM uniforms WHERE id = :id')->execute(['id' => $id]);
            flash('success', 'Uniform deleted.');
        }
        redirect('admin/uniforms.php');
    }

    if (isset($_POST['save'])) {
        $uniformName = trim((string) ($_POST['uniform_name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $gender = trim((string) ($_POST['gender'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'Active')) === 'Inactive' ? 'Inactive' : 'Active';
        $description = trim((string) ($_POST['description'] ?? ''));
        $postedVariants = $_POST['variants'] ?? [];
        $cleanVariants = [];
        $seenSizes = [];

        foreach ($postedVariants as $variant) {
            $size = trim((string) ($variant['size'] ?? ''));
            if ($size === '__custom__') {
                $size = trim((string) ($variant['custom_size'] ?? ''));
            }
            if ($size === '' && trim((string) ($variant['selling_price'] ?? '')) === '') {
                continue;
            }
            $priceValue = trim((string) ($variant['selling_price'] ?? ''));
            $stockValue = trim((string) ($variant['opening_stock'] ?? ''));
            $reorderValue = trim((string) ($variant['reorder_level'] ?? '0'));
            if ($size === '' || !is_numeric($priceValue) || !is_numeric($stockValue) || !is_numeric($reorderValue)) {
                $error = 'Every size row needs a size, valid price, opening stock and reorder level.';
                break;
            }
            $sizeKey = mb_strtolower($size, 'UTF-8');
            if (isset($seenSizes[$sizeKey])) {
                $error = 'Each size can only be listed once for a uniform.';
                break;
            }
            if ((float) $priceValue < 0 || (int) $stockValue < 0 || (int) $reorderValue < 0 || (string) (int) $stockValue !== $stockValue || (string) (int) $reorderValue !== $reorderValue) {
                $error = 'Prices and stock values must be zero or greater, and stock values must be whole numbers.';
                break;
            }
            $seenSizes[$sizeKey] = true;
            $cleanVariants[] = [
                'id' => (int) ($variant['id'] ?? 0),
                'size' => $size,
                'selling_price' => round((float) $priceValue, 2),
                'opening_stock' => (int) $stockValue,
                'reorder_level' => (int) $reorderValue,
            ];
        }

        if ($uniformName === '' || $category === '' || !in_array($gender, ['Boys', 'Girls', 'Unisex'], true)) {
            $error = 'Uniform name, category and gender are required.';
        } elseif (!$cleanVariants) {
            $error = 'Add at least one size and price row.';
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();
                if ($id > 0) {
                    $stockHistory = $pdo->prepare(
                        "SELECT uv.size, uv.opening_stock, EXISTS(
                            SELECT 1 FROM uniform_stock_movements sm WHERE sm.uniform_variant_id = uv.id
                        ) AS has_movements
                         FROM uniform_variants uv WHERE uv.uniform_id = :uniform_id"
                    );
                    $stockHistory->execute(['uniform_id' => $id]);
                    $historyBySize = [];
                    foreach ($stockHistory->fetchAll() as $historyRow) {
                        $historyBySize[mb_strtolower((string) $historyRow['size'], 'UTF-8')] = $historyRow;
                    }
                    foreach ($cleanVariants as $variant) {
                        $history = $historyBySize[mb_strtolower($variant['size'], 'UTF-8')] ?? null;
                        if ($history && (int) $history['has_movements'] === 1 && (int) $history['opening_stock'] !== $variant['opening_stock']) {
                            throw new InvalidArgumentException('Opening stock for size ' . $variant['size'] . ' cannot be changed after stock history exists. Use Adjust Stock to record the change.');
                        }
                    }
                }
                $first = $cleanVariants[0];
                if ($id > 0) {
                    $saveProduct = $pdo->prepare('UPDATE uniforms SET uniform_name = :uniform_name, category = :category, gender = :gender, size = :size, selling_price = :selling_price, opening_stock = :opening_stock, reorder_level = :reorder_level, status = :status, description = :description WHERE id = :id');
                    $saveProduct->execute([
                        'uniform_name' => $uniformName, 'category' => $category, 'gender' => $gender,
                        'size' => $first['size'], 'selling_price' => $first['selling_price'],
                        'opening_stock' => $first['opening_stock'], 'reorder_level' => $first['reorder_level'],
                        'status' => $status, 'description' => $description, 'id' => $id,
                    ]);
                } else {
                    $saveProduct = $pdo->prepare('INSERT INTO uniforms (uniform_name, category, gender, size, selling_price, opening_stock, reorder_level, status, description) VALUES (:uniform_name, :category, :gender, :size, :selling_price, :opening_stock, :reorder_level, :status, :description)');
                    $saveProduct->execute([
                        'uniform_name' => $uniformName, 'category' => $category, 'gender' => $gender,
                        'size' => $first['size'], 'selling_price' => $first['selling_price'],
                        'opening_stock' => $first['opening_stock'], 'reorder_level' => $first['reorder_level'],
                        'status' => $status, 'description' => $description,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                }

                $existingStmt = $pdo->prepare('SELECT id FROM uniform_variants WHERE uniform_id = :uniform_id');
                $existingStmt->execute(['uniform_id' => $id]);
                $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
                $savedIds = [];
                $variantSave = $pdo->prepare(
                    "INSERT INTO uniform_variants (uniform_id, size, selling_price, opening_stock, reorder_level, status)
                     VALUES (:uniform_id, :size, :selling_price, :opening_stock, :reorder_level, :status)
                     ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), opening_stock = VALUES(opening_stock), reorder_level = VALUES(reorder_level), status = VALUES(status)"
                );
                foreach ($cleanVariants as $variant) {
                    $variantSave->execute([
                        'uniform_id' => $id, 'size' => $variant['size'], 'selling_price' => $variant['selling_price'],
                        'opening_stock' => $variant['opening_stock'], 'reorder_level' => $variant['reorder_level'], 'status' => $status,
                    ]);
                    $findVariant = $pdo->prepare('SELECT id FROM uniform_variants WHERE uniform_id = :uniform_id AND size = :size LIMIT 1');
                    $findVariant->execute(['uniform_id' => $id, 'size' => $variant['size']]);
                    $savedIds[] = (int) $findVariant->fetchColumn();
                }

                $removedIds = array_values(array_diff($existingIds, $savedIds));
                if ($removedIds) {
                    $hasHistory = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM uniform_sale_items WHERE uniform_variant_id = :sale_variant) OR EXISTS(SELECT 1 FROM uniform_stock_movements WHERE uniform_variant_id = :movement_variant)');
                    $deactivate = $pdo->prepare("UPDATE uniform_variants SET status = 'Inactive' WHERE id = :id");
                    $deleteVariant = $pdo->prepare('DELETE FROM uniform_variants WHERE id = :id');
                    foreach ($removedIds as $removedId) {
                        $hasHistory->execute(['sale_variant' => $removedId, 'movement_variant' => $removedId]);
                        if ((int) $hasHistory->fetchColumn() === 1) {
                            $deactivate->execute(['id' => $removedId]);
                        } else {
                            $deleteVariant->execute(['id' => $removedId]);
                        }
                    }
                }

                $pdo->commit();
                flash('success', $uniform ? 'Uniform and sizes updated.' : 'Uniform and sizes created.');
                redirect('admin/uniforms.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Uniform save failed: ' . $exception->getMessage());
                $error = $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'Unable to save this uniform. Check for duplicate sizes and try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && $error) {
    $uniform = array_merge($uniform ?? [], [
        'uniform_name' => $_POST['uniform_name'] ?? '', 'category' => $_POST['category'] ?? '',
        'gender' => $_POST['gender'] ?? 'Girls', 'status' => $_POST['status'] ?? 'Active',
        'description' => $_POST['description'] ?? '',
    ]);
    $variants = array_values($_POST['variants'] ?? []);
}

$genderValue = (string) ($uniform['gender'] ?? 'Girls');
$genderValue = ['Female' => 'Girls', 'Male' => 'Boys'][ $genderValue ] ?? $genderValue;
$sizeOptions = ['Small', 'Medium', 'Large', 'All sizes', 'XS', 'S', 'M', 'L', 'XL', 'XXL'];
for ($sizeNumber = 10; $sizeNumber <= 44; $sizeNumber++) {
    $sizeOptions[] = (string) $sizeNumber;
}
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div class="uniform-form-title">
        <span class="uniform-form-title-icon"><i class="fa-solid fa-shirt" aria-hidden="true"></i></span>
        <div><p class="eyebrow">Inventory</p>
        <h1><?= $id ? 'Edit Uniform' : 'New Uniform' ?></h1>
        <p class="mb-0 text-muted">Set up one uniform and manage each size, price and stock separately.</p></div>
    </div>
    <div class="action-row"><a class="btn btn-outline-primary" href="<?= url('admin/uniforms.php') ?>">Back to Catalog</a></div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<section class="panel uniform-inventory-form-panel">
    <form method="post" id="uniform-form">
        <div class="uniform-form-section-heading"><span><i class="fa-solid fa-shirt" aria-hidden="true"></i></span><div><h2><?= $id ? 'Uniform details' : 'Add New Uniform' ?></h2><p>Register a uniform item with size-specific prices and stock counts.</p></div></div>

        <div class="row g-3 uniform-product-fields">
            <div class="col-lg-4">
                <label class="form-label" for="uniform-name">Uniform Name <span class="required-mark">*</span></label>
                <input class="form-control" id="uniform-name" name="uniform_name" value="<?= h($uniform['uniform_name'] ?? '') ?>" required>
            </div>
            <div class="col-lg-4">
                <label class="form-label" for="uniform-category">Category <span class="required-mark">*</span></label>
                <input class="form-control" id="uniform-category" name="category" list="uniform-categories" value="<?= h($uniform['category'] ?? '') ?>" required>
                <datalist id="uniform-categories"><option value="Dresses"><option value="Tracksuits"><option value="Sweaters"><option value="T-Shirts"><option value="Jumpers"><option value="Shirts"><option value="Trousers"></datalist>
            </div>
            <fieldset class="col-lg-4">
                <legend class="form-label">Gender <span class="required-mark">*</span></legend>
                <div class="uniform-gender-options">
                    <?php foreach (['Boys', 'Girls', 'Unisex'] as $gender): ?>
                        <label class="uniform-gender-choice"><input type="radio" name="gender" value="<?= h($gender) ?>" <?= $genderValue === $gender ? 'checked' : '' ?> required><span><?= h($gender) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>

        <div class="uniform-variant-heading">
            <div><h2>Available sizes</h2><p>Set a separate selling price and stock quantity for each size.</p></div>
            <div class="uniform-price-tools">
                <div class="uniform-price-select"><label class="form-label" for="official-price-list">Official price list</label><select class="form-select" id="official-price-list"><option value="">Choose a price list</option><option value="girls-dresses">Girls' dresses</option><option value="girls-tracksuits">Girls' tracksuits</option><option value="girls-sweaters">Girls' sweaters</option><option value="girls-shirts">Girls' T-shirts</option><option value="girls-jumpers">Girls' jumpers</option><option value="boys-shirts">Boys' shirts</option><option value="boys-trousers">Boys' trousers</option></select></div>
                <button class="btn btn-outline-primary" type="button" id="apply-price-list">Load prices</button>
                <button class="btn btn-primary" type="button" id="add-size"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Size</button>
            </div>
        </div>

        <div class="table-responsive uniform-variant-scroll">
            <table class="table align-middle uniform-variant-table">
                <thead><tr><th>Size</th><th>Selling Price (KES) <span class="required-mark">*</span></th><th>Opening Stock <span class="required-mark">*</span></th><th>Reorder Level</th><th>Action</th></tr></thead>
                <tbody id="variant-rows">
                    <?php foreach ($variants as $index => $variant): ?>
                        <?php $variantSize = (string) ($variant['size'] ?? ''); $knownSize = in_array($variantSize, $sizeOptions, true); $customVariantSize = $variantSize === '__custom__' ? (string) ($variant['custom_size'] ?? '') : (!$knownSize ? $variantSize : ''); ?>
                        <tr>
                            <td>
                                <select class="form-select size-select" name="variants[<?= (int)$index ?>][size]" required>
                                    <option value="">Choose size</option>
                                    <?php foreach ($sizeOptions as $sizeOption): ?><option value="<?= h($sizeOption) ?>" <?= $variantSize === $sizeOption ? 'selected' : '' ?>><?= h($sizeOption) ?></option><?php endforeach; ?>
                                    <option value="__custom__" <?= $customVariantSize !== '' ? 'selected' : '' ?>>Other size...</option>
                                </select>
                                <input class="form-control custom-size-input" name="variants[<?= (int)$index ?>][custom_size]" value="<?= h($customVariantSize) ?>" placeholder="Enter size" <?= $customVariantSize === '' ? 'hidden' : 'required' ?>>
                            </td>
                            <td><input class="form-control" type="number" name="variants[<?= (int)$index ?>][selling_price]" value="<?= h($variant['selling_price'] ?? '') ?>" min="0" step="0.01" placeholder="0.00" required></td>
                            <td><input class="form-control" type="number" name="variants[<?= (int)$index ?>][opening_stock]" value="<?= h($variant['opening_stock'] ?? 0) ?>" min="0" step="1" placeholder="0" required></td>
                            <td><input class="form-control" type="number" name="variants[<?= (int)$index ?>][reorder_level]" value="<?= h($variant['reorder_level'] ?? 0) ?>" min="0" step="1" placeholder="0" required></td>
                            <td><input type="hidden" name="variants[<?= (int)$index ?>][id]" value="<?= (int)($variant['id'] ?? 0) ?>"><button class="btn btn-outline-danger remove-size" type="button" aria-label="Remove size row" title="Remove size"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row g-3 uniform-form-footer-fields">
            <div class="col-lg-7"><label class="form-label" for="uniform-description">Description</label><textarea class="form-control" id="uniform-description" name="description" rows="3"><?= h($uniform['description'] ?? '') ?></textarea></div>
            <div class="col-lg-3">
                <span class="form-label d-block">Status</span>
                <input type="hidden" name="status" id="uniform-status-value" value="<?= (($uniform['status'] ?? 'Active') === 'Active') ? 'Active' : 'Inactive' ?>">
                <label class="uniform-status-toggle"><input type="checkbox" id="uniform-status" <?= (($uniform['status'] ?? 'Active') === 'Active') ? 'checked' : '' ?>><span class="uniform-toggle-track" aria-hidden="true"></span><span id="uniform-status-label"><?= (($uniform['status'] ?? 'Active') === 'Active') ? 'Active' : 'Inactive' ?></span></label>
            </div>
            <div class="col-lg-2 uniform-form-actions">
                <button class="btn btn-primary" type="submit" name="save" value="1" <?= $canEditCatalog ? '' : 'disabled' ?>><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save Uniform</button>
                <button class="btn btn-outline-secondary" type="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset</button>
            </div>
        </div>
    </form>
    <?php if ($id > 0): ?>
        <form method="post" class="mt-3" onsubmit="return confirm('Delete this uniform? Uniforms with sales or stock history are kept to protect records.');">
            <button class="btn btn-outline-danger" type="submit" name="delete" value="1" <?= $canEditCatalog ? '' : 'disabled' ?>>Delete Uniform</button>
        </form>
    <?php endif; ?>
</section>

<style>
.uniform-form-title{display:flex;align-items:center;gap:1rem}.uniform-form-title-icon{width:58px;height:58px;display:grid;place-items:center;flex:0 0 auto;border-radius:14px;background:#e7f3fc;color:#1689c8;font-size:1.55rem}.uniform-inventory-form-panel{overflow:hidden;padding:1.25rem}.uniform-form-section-heading{display:flex;align-items:center;gap:.9rem;padding:.25rem 0 1rem;margin-bottom:1.2rem;border-bottom:1px solid #d8e6f1}.uniform-form-section-heading>span{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:#e7f3fc;color:#1689c8;font-size:1.1rem;flex:0 0 auto}.uniform-form-section-heading h2{font-size:1.15rem;margin:0 0 .2rem;color:#173d60}.uniform-form-section-heading p{font-size:.85rem;color:#738291;margin:0}.uniform-product-fields{align-items:end}.uniform-product-fields fieldset{min-width:0;border:0;padding:0;margin:0}.uniform-product-fields legend{float:none;width:auto;margin:0 0 .5rem}.required-mark{color:#d33b45}.uniform-gender-options{display:flex;align-items:center;gap:1.1rem;min-height:42px}.uniform-gender-choice{display:inline-flex;align-items:center;gap:.45rem;font-weight:600;color:#344b60;cursor:pointer}.uniform-gender-choice input{width:17px;height:17px;accent-color:#1689c8}.uniform-variant-heading{display:flex;justify-content:space-between;align-items:end;gap:1rem;margin:1.25rem 0 .55rem;padding:.85rem 1rem;background:#f0f7fd;border:1px solid #cfe2f3;border-radius:7px}.uniform-variant-heading h2{font-size:1rem;font-weight:700;color:#173d60;margin:0 0 .15rem}.uniform-variant-heading p{font-size:.82rem;color:#738291;margin:0}.uniform-price-tools{display:flex;align-items:end;gap:.5rem;flex-wrap:wrap}.uniform-price-select{min-width:180px}.uniform-price-select .form-label{margin-bottom:.3rem}.uniform-variant-scroll{border:1px solid #cbd9e7;border-radius:6px;margin-top:.55rem}.uniform-variant-table{min-width:900px;margin:0}.uniform-variant-table thead th{white-space:nowrap;background:#0752b5;color:#fff;border-color:#3978ac;padding:.7rem .75rem;font-size:.78rem;text-transform:uppercase}.uniform-variant-table tbody td{padding:.45rem .55rem;vertical-align:middle;background:#fff;border-color:#e1e8f0}.uniform-variant-table .form-control,.uniform-variant-table .form-select{min-height:39px}.uniform-variant-table .remove-size{width:38px;height:38px;display:grid;place-items:center}.uniform-inventory-form-panel .form-label{font-weight:650;color:#344b60}.uniform-inventory-form-panel .form-control,.uniform-inventory-form-panel .form-select{min-height:42px}.uniform-form-footer-fields{align-items:end;margin-top:.9rem}.uniform-form-footer-fields textarea{min-height:76px}.uniform-status-toggle{display:flex;align-items:center;gap:.7rem;min-height:42px;font-weight:650;color:#344b60;cursor:pointer}.uniform-status-toggle input{position:absolute;opacity:0}.uniform-toggle-track{width:48px;height:27px;background:#aab6c2;border-radius:20px;position:relative;transition:background .18s;flex:0 0 auto}.uniform-toggle-track:after{content:"";position:absolute;left:3px;top:3px;width:21px;height:21px;background:white;border-radius:50%;box-shadow:0 1px 3px #0003;transition:transform .18s}.uniform-status-toggle input:checked+.uniform-toggle-track{background:#159b68}.uniform-status-toggle input:checked+.uniform-toggle-track:after{transform:translateX(21px)}.uniform-status-toggle input:focus-visible+.uniform-toggle-track{outline:3px solid #1689c866;outline-offset:2px}.uniform-form-actions{display:grid;gap:.5rem}.uniform-form-actions .btn{white-space:nowrap}.custom-size-input{margin-top:.4rem}.custom-size-input[hidden]{display:none}.uniform-variant-table .form-select{min-width:135px}@media(max-width:900px){.uniform-variant-heading{align-items:flex-start;flex-direction:column}.uniform-price-tools{width:100%}.uniform-price-select{flex:1;min-width:200px}.uniform-price-tools>button{flex:0 0 auto}}@media(max-width:700px){.uniform-form-title{align-items:flex-start}.uniform-inventory-form-panel{padding:.9rem}.uniform-price-tools{display:grid;grid-template-columns:1fr 1fr}.uniform-price-select{grid-column:1/-1;min-width:0}.uniform-price-tools .form-select{width:100%}.uniform-price-tools button{width:100%}.uniform-form-actions{grid-template-columns:1fr 1fr}.uniform-form-actions .btn{width:100%}.uniform-gender-options{gap:.75rem;flex-wrap:wrap}}
</style>
<script>
(() => {
    const tbody = document.getElementById('variant-rows');
    const getNextIndex = () => Math.max(-1, ...Array.from(tbody.querySelectorAll('[name$="[size]"]')).map(field => Number(field.name.match(/variants\[(\d+)\]/)?.[1] ?? -1))) + 1;
    let nextIndex = getNextIndex();
    const form = document.getElementById('uniform-form');
    const initialRows = Array.from(tbody.children, row => row.cloneNode(true));
    const standardSizes = <?= json_encode($sizeOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const priceLists = {
        'girls-dresses': {name: "Girls' Dresses", category: 'Dresses', gender: 'Girls', sizes: [['Small',550],['Medium',600],['Large',650]]},
        'girls-tracksuits': {name: "Girls' Tracksuits", category: 'Tracksuits', gender: 'Girls', sizes: [['20',1500],['22',1500],['24',1600],['26',1600],['28',1600]]},
        'girls-sweaters': {name: "Girls' Sweaters", category: 'Sweaters', gender: 'Girls', sizes: [['22',650],['24',700],['26',750]]},
        'girls-shirts': {name: "Girls' T-Shirts", category: 'T-Shirts', gender: 'Girls', sizes: [['All sizes',500]]},
        'girls-jumpers': {name: "Girls' Jumpers", category: 'Jumpers', gender: 'Girls', sizes: [['22',1600],['23',1600],['24',1600],['25',1600],['26',1600],['27',1600],['28',1600]]},
        'boys-shirts': {name: "Boys' Shirts", category: 'Shirts', gender: 'Boys', sizes: [['Small',400],['Medium',450],['Large',550]]},
        'boys-trousers': {name: "Boys' Trousers", category: 'Trousers', gender: 'Boys', sizes: [['Small',500],['Medium',600],['Large',700]]}
    };
    function addRow(variant = {}) {
        const row = document.createElement('tr');
        const index = nextIndex++;
        const sizeCell = document.createElement('td');
        const sizeSelect = document.createElement('select');
        sizeSelect.className = 'form-select size-select';
        sizeSelect.name = `variants[${index}][size]`;
        sizeSelect.required = true;
        sizeSelect.add(new Option('Choose size', ''));
        standardSizes.forEach(size => sizeSelect.add(new Option(size, size)));
        sizeSelect.add(new Option('Other size...', '__custom__'));
        const customSize = document.createElement('input');
        customSize.className = 'form-control custom-size-input';
        customSize.name = `variants[${index}][custom_size]`;
        customSize.placeholder = 'Enter size';
        customSize.hidden = true;
        sizeCell.append(sizeSelect, customSize);
        row.appendChild(sizeCell);

        const values = [variant.selling_price ?? '', variant.opening_stock ?? 0, variant.reorder_level ?? 0];
        const names = ['selling_price', 'opening_stock', 'reorder_level'];
        values.forEach((value, fieldIndex) => {
            const cell = document.createElement('td');
            const input = document.createElement('input');
            input.className = 'form-control';
            input.name = `variants[${index}][${names[fieldIndex]}]`;
            input.value = value;
            input.type = 'number'; input.min = '0'; input.step = fieldIndex === 0 ? '0.01' : '1';
            input.required = true;
            input.placeholder = fieldIndex === 0 ? '0.00' : '0';
            cell.appendChild(input); row.appendChild(cell);
        });
        const action = document.createElement('td');
        const id = document.createElement('input'); id.type = 'hidden'; id.name = `variants[${index}][id]`; id.value = variant.id ?? 0;
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline-danger remove-size'; remove.setAttribute('aria-label','Remove size row'); remove.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
        action.append(id, remove); row.appendChild(action); tbody.appendChild(row);
        if (variant.size) {
            const option = Array.from(sizeSelect.options).find(item => item.value === String(variant.size));
            if (option) sizeSelect.value = String(variant.size);
            else { sizeSelect.value = '__custom__'; customSize.hidden = false; customSize.required = true; customSize.value = variant.size; }
        }
    }
    document.getElementById('add-size').addEventListener('click', () => addRow());
    tbody.addEventListener('click', event => {
        const button = event.target.closest('.remove-size');
        if (!button) return;
        if (tbody.querySelectorAll('tr').length <= 1) { alert('A uniform must have at least one size.'); return; }
        button.closest('tr').remove();
    });
    document.getElementById('apply-price-list').addEventListener('click', () => {
        const preset = priceLists[document.getElementById('official-price-list').value];
        if (!preset) { alert('Choose an official price list first.'); return; }
        const hasEntries = Array.from(tbody.querySelectorAll('tr')).some(row => row.querySelector('.size-select')?.value || row.querySelector('[name$="[selling_price]"]')?.value);
        if (hasEntries && !confirm('Replace the current size rows with the selected price list?')) return;
        document.getElementById('uniform-name').value = preset.name;
        document.getElementById('uniform-category').value = preset.category;
        document.querySelector(`input[name="gender"][value="${preset.gender}"]`).checked = true;
        tbody.replaceChildren();
        preset.sizes.forEach(([size, price]) => addRow({size, selling_price: price, opening_stock: 0, reorder_level: 0}));
    });
    tbody.addEventListener('change', event => {
        const sizeSelect = event.target.closest('.size-select');
        if (!sizeSelect) return;
        const customSize = sizeSelect.parentElement.querySelector('.custom-size-input');
        customSize.hidden = sizeSelect.value !== '__custom__';
        customSize.required = sizeSelect.value === '__custom__';
        if (!customSize.hidden) customSize.focus();
    });
    document.getElementById('uniform-status').addEventListener('change', event => {
        const active = event.target.checked;
        document.getElementById('uniform-status-value').value = active ? 'Active' : 'Inactive';
        document.getElementById('uniform-status-label').textContent = active ? 'Active' : 'Inactive';
    });
    form.addEventListener('reset', () => window.setTimeout(() => {
        tbody.replaceChildren(...initialRows.map(row => row.cloneNode(true)));
        nextIndex = getNextIndex();
        document.getElementById('uniform-status-value').value = document.getElementById('uniform-status').checked ? 'Active' : 'Inactive';
        document.getElementById('uniform-status-label').textContent = document.getElementById('uniform-status').checked ? 'Active' : 'Inactive';
    }, 0));
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
