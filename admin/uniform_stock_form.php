<?php
$pageTitle = 'Adjust Uniform Stock';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_has_permission($pdo, 'school_uniform.inventory')) {
    flash('error', 'You do not have permission to adjust uniform stock.');
    redirect('admin/uniform_stock.php');
}

$uniformId = (int) ($_GET['id'] ?? 0);
$variantId = (int) ($_GET['variant_id'] ?? 0);
if ($uniformId <= 0) redirect('admin/uniform_stock.php');

$productStmt = $pdo->prepare('SELECT * FROM uniforms WHERE id = :id LIMIT 1');
$productStmt->execute(['id' => $uniformId]);
$uniform = $productStmt->fetch();
if (!$uniform) { flash('error', 'Uniform not found.'); redirect('admin/uniform_stock.php'); }
$variantsStmt = $pdo->prepare('SELECT * FROM uniform_variants WHERE uniform_id = :uniform_id ORDER BY size');
$variantsStmt->execute(['uniform_id' => $uniformId]);
$variants = $variantsStmt->fetchAll();
if (!$variantId && $variants) $variantId = (int) $variants[0]['id'];
$selected = null;
foreach ($variants as $variant) if ((int)$variant['id'] === $variantId) $selected = $variant;
if (!$selected) { flash('error', 'Uniform size not found.'); redirect('admin/uniform_stock.php'); }

$error = flash('error');
$success = flash('success');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $movementType = trim((string) ($_POST['movement_type'] ?? 'Add'));
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $note = trim((string) ($_POST['note'] ?? ''));
    $selectedVariant = (int) ($_POST['variant_id'] ?? 0);
    if (!in_array($movementType, ['Add', 'Returned', 'Adjustment', 'Damaged'], true) || $quantity === false || $quantity <= 0 || $selectedVariant !== $variantId) {
        $error = 'Choose a size and enter a positive whole quantity.';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id, opening_stock FROM uniform_variants WHERE id = :variant_id AND uniform_id = :uniform_id FOR UPDATE');
            $lock->execute(['variant_id' => $variantId, 'uniform_id' => $uniformId]);
            $lockedVariant = $lock->fetch();
            if (!$lockedVariant) throw new RuntimeException('The selected size no longer exists.');
            $currentStmt = $pdo->prepare('SELECT :opening_stock + COALESCE(SUM(quantity),0) FROM uniform_stock_movements WHERE uniform_variant_id = :variant_id');
            $currentStmt->execute(['opening_stock' => (int)$lockedVariant['opening_stock'], 'variant_id' => $variantId]);
            $currentStock = (int) $currentStmt->fetchColumn();
            $delta = in_array($movementType, ['Damaged', 'Adjustment'], true) ? -$quantity : $quantity;
            if ($currentStock + $delta < 0) throw new RuntimeException('This adjustment would make stock negative.');
            $insert = $pdo->prepare('INSERT INTO uniform_stock_movements (uniform_id, uniform_variant_id, movement_type, quantity, reference_id, note) VALUES (:uniform_id, :variant_id, :movement_type, :quantity, NULL, :note)');
            $insert->execute(['uniform_id' => $uniformId, 'variant_id' => $variantId, 'movement_type' => $movementType, 'quantity' => $delta, 'note' => $note]);
            $pdo->commit();
            flash('success', 'Stock updated for size ' . $selected['size'] . '.');
            redirect('admin/uniform_stock_form.php?id=' . $uniformId . '&variant_id=' . $variantId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to update stock. Please try again.';
        }
    }
}

$stockStmt = $pdo->prepare('SELECT uv.opening_stock + COALESCE(SUM(sm.quantity),0) AS available_stock FROM uniform_variants uv LEFT JOIN uniform_stock_movements sm ON sm.uniform_variant_id = uv.id WHERE uv.id = :variant_id GROUP BY uv.id');
$stockStmt->execute(['variant_id' => $variantId]);
$availableStock = (int) $stockStmt->fetchColumn();
$movementsStmt = $pdo->prepare('SELECT * FROM uniform_stock_movements WHERE uniform_variant_id = :variant_id ORDER BY created_at DESC, id DESC');
$movementsStmt->execute(['variant_id' => $variantId]);
$movements = $movementsStmt->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title"><div><p class="eyebrow">Inventory</p><h1>Adjust Stock</h1><p class="mb-0 text-muted"><?= h($uniform['uniform_name']) ?> · <?= h($selected['size']) ?></p></div><div class="action-row"><a class="btn btn-outline-primary" href="<?= url('admin/uniform_stock.php?uniform_id=' . $uniformId) ?>">Back to Stock</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<section class="panel">
    <div class="row g-3"><div class="col-md-3"><span class="text-muted">Size</span><div class="fw-semibold"><?= h($selected['size']) ?></div></div><div class="col-md-3"><span class="text-muted">Opening stock</span><div class="fw-semibold"><?= number_format((int)$selected['opening_stock']) ?></div></div><div class="col-md-3"><span class="text-muted">Available stock</span><div class="fw-semibold"><?= number_format($availableStock) ?></div></div><div class="col-md-3"><span class="text-muted">Reorder level</span><div class="fw-semibold"><?= number_format((int)$selected['reorder_level']) ?></div></div></div>
    <hr>
    <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="variant_id" value="<?= $variantId ?>">
        <div class="col-md-3"><label class="form-label" for="movement-type">Movement</label><select class="form-select" id="movement-type" name="movement_type"><option>Add</option><option>Returned</option><option>Adjustment</option><option>Damaged</option></select></div>
        <div class="col-md-3"><label class="form-label" for="quantity">Quantity</label><input class="form-control" id="quantity" name="quantity" type="number" min="1" step="1" required></div>
        <div class="col-md-4"><label class="form-label" for="note">Note</label><input class="form-control" id="note" name="note" maxlength="500"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Save Movement</button></div>
    </form>
    <hr><h2 class="h5">Movement History · <?= h($selected['size']) ?></h2>
    <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Date</th><th>Type</th><th>Quantity Change</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($movements as $movement): ?><tr><td><?= h($movement['created_at']) ?></td><td><?= h($movement['movement_type']) ?></td><td><?= (int)$movement['quantity'] > 0 ? '+' : '' ?><?= number_format((int)$movement['quantity']) ?></td><td><?= h($movement['note'] ?? '') ?></td></tr><?php endforeach; ?>
        <?php if (!$movements): ?><tr><td colspan="4" class="text-muted">No stock movements recorded for this size.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
