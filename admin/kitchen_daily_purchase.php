<?php
$pageTitle = 'Daily Purchase';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

kitchen_require_access($pdo);
redirect('admin/kitchen_stock_taking.php#stock-taking');

// Module access
if (!current_admin_has_permission($pdo, 'kitchen.access')) {
    flash('error', 'You do not have permission to access Kitchen.');
    redirect('admin/dashboard.php');
}

$canDailyPurchase = current_admin_has_permission($pdo, 'kitchen.daily_purchase');

$errors = [];
ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);
$formToken = $_SESSION['daily_purchase_form_token'] ?? '';

$postTokenValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim($_POST['form_token'] ?? '');
    if (!$postedToken || !$formToken || !hash_equals($formToken, $postedToken)) {
        $errors[] = 'Duplicate or invalid form submission detected. Please refresh and try again.';
        $postTokenValid = false;
        unset($_SESSION['daily_purchase_form_token']);
        $formToken = '';
    }
}

if (!$formToken) {
    $formToken = bin2hex(random_bytes(16));
    $_SESSION['daily_purchase_form_token'] = $formToken;
}

$defaultDate = date('Y-m-d');
$paymentOptions = ['Cash', 'Bank Transfer', 'MPESA', 'Mobile Payment', 'Credit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postTokenValid) {
    $purchaseDate = trim($_POST['purchase_date'] ?? $defaultDate);
    $supplier = trim($_POST['supplier'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $items = [];

    $names = $_POST['item_name'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $units = $_POST['unit'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $unitOptions = kitchen_available_unit_options($pdo);
    for ($i = 0; $i < count($names); $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $qty = (float)($qtys[$i] ?? 0);
        $unit = kitchen_validate_unit($units[$i] ?? '');
        $price = (float)($prices[$i] ?? 0);
        if ($name !== '' && $qty > 0 && $price >= 0) {
            if (!array_key_exists($unit, $unitOptions)) {
                $errors[] = 'Choose a valid quantity unit for ' . $name . '.';
                continue;
            }
            $items[] = ['item_name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $price];
        }
    }

    if (count($items) === 0) {
        $errors[] = 'Add at least one purchase item to continue.';
    }
    if (!valid_date_value($purchaseDate)) {
        $errors[] = 'Enter a valid purchase date.';
    }

    if (!$errors) {
        if (!$canDailyPurchase) {
            flash('error', 'You do not have permission to record daily kitchen purchases.');
            redirect('admin/kitchen_daily_purchase.php');
        }
        $saved = 0;
        foreach ($items as $item) {
            $itemAmount = $item['quantity'] * $item['unit_price'];
            record_daily_purchase(
                $pdo,
                $item['item_name'],
                $item['quantity'],
                $item['unit'],
                $itemAmount,
                'Kitchen Purchases',
                $supplier,
                $description,
                $paymentMethod,
                $purchaseDate
            );
            $saved++;
        }
        flash('success', 'Daily purchase recorded for ' . $saved . ' item(s).');
        unset($_SESSION['daily_purchase_form_token']);
        redirect('admin/kitchen_daily_purchase.php');
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Kitchen Management</p>
        <h1>Daily Purchase</h1>
        <p class="mb-0 text-muted">Record small kitchen purchases quickly and automatically push them to expenses.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_inventory.php') ?>">Inventory</a>
        <a class="btn btn-outline-secondary" href="<?= url('admin/kitchen_weekly_shopping.php') ?>">Weekly Shopping</a>
    </div>
</div>
<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<section class="panel mb-4">
    <form method="post" id="dailyPurchaseForm">
        <input type="hidden" name="form_token" value="<?= h($formToken) ?>">
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label">Purchase Date</label>
                <input class="form-control form-control-lg" type="date" name="purchase_date" value="<?= h($purchaseDate ?? $defaultDate) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Supplier</label>
                <input class="form-control form-control-lg" type="text" name="supplier" value="<?= h($supplier ?? '') ?>" placeholder="XYZ Store">
            </div>
            <div class="col-md-3">
                <label class="form-label">Payment Method</label>
                <select class="form-select form-select-lg" name="payment_method">
                    <?php foreach ($paymentOptions as $option): ?>
                        <option value="<?= h($option) ?>" <?= (isset($paymentMethod) && $paymentMethod === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Purpose / Description</label>
                <input class="form-control form-control-lg" type="text" name="description" value="<?= h($description ?? '') ?>" placeholder="e.g. Daily kitchen supplies">
            </div>
        </div>

        <div id="dpDuplicateWarning" class="alert alert-warning d-none">Duplicate items found in this purchase. Please remove duplicates or consolidate quantities.</div>
        <div class="table-responsive mb-4">
            <table class="table table-bordered align-middle" id="dailyPurchaseTable" style="min-width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th>Item</th>
                        <th style="width: 12%; text-align:center">Quantity</th>
                        <th style="width: 12%; text-align:center">Unit</th>
                        <th style="width: 14%; text-align:right">Unit Price</th>
                        <th style="width: 14%; text-align:right">Total</th>
                        <th style="width: 8%; text-align:center">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="dp-row">
                        <td>
                            <input class="form-control form-control-lg item-lookup" name="item_name[]" placeholder="Type to search item" autocomplete="off">
                        </td>
                        <td><input class="form-control form-control-lg text-center dp-qty" name="quantity[]" type="number" step="0.01" min="0" value="0"></td>
                        <td>
                            <select class="form-select form-select-lg dp-unit" name="unit[]">
                                <?php foreach (kitchen_available_unit_options($pdo) as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $value === 'kg' ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="form-control form-control-lg text-end dp-price" name="unit_price[]" type="number" step="0.01" min="0" value="0.00"></td>
                        <td class="text-end fw-semibold dp-line-total">0.00</td>
                        <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm dp-remove-row">−</button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="row g-3 align-items-center mb-4">
            <div class="col-auto">
                <button type="button" class="btn btn-success btn-lg" id="addDailyRow">+ Add Item</button>
            </div>
            <div class="col text-end">
                <div class="small text-muted">Daily Purchase Total</div>
                <div class="h2 fw-bold">KES <span id="dailyGrandTotal">0.00</span></div>
            </div>
        </div>

        <div class="row g-3 align-items-stretch mb-4">
            <div class="col-xl-7">
                <div class="summary-action-card">
                    <div>
                        <div class="text-muted small">Review your purchase items and save when ready.</div>
                        <div class="fw-semibold">Daily purchase is ready to submit.</div>
                    </div>
                        <?php if ($canDailyPurchase): ?>
                            <button class="btn btn-primary btn-lg" type="submit">Save Daily Purchase</button>
                        <?php else: ?>
                            <button class="btn btn-primary btn-lg" type="button" disabled>Save Daily Purchase</button>
                        <?php endif; ?>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="summary-card">
                    <div class="summary-card-header">
                        <h5>Daily Purchase Summary</h5>
                    </div>
                    <div class="summary-card-row"><span>Total Items</span><strong id="dpSummaryItems">0</strong></div>
                    <div class="summary-card-row"><span>Total Quantity</span><strong id="dpSummaryQuantity">0.00</strong></div>
                    <div class="summary-card-row"><span>Total Amount</span><strong id="dpSummaryAmount">KES 0.00</strong></div>
                    <div class="summary-note mt-3">Daily purchases help you keep track of urgent kitchen stock and small one-off inventory needs.</div>
                </div>
            </div>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
<script>
(function(){
    const table = document.getElementById('dailyPurchaseTable');
    const addRowBtn = document.getElementById('addDailyRow');
    const grandTotalEl = document.getElementById('dailyGrandTotal');
    const summaryItems = document.getElementById('dpSummaryItems');
    const summaryQuantity = document.getElementById('dpSummaryQuantity');
    const summaryAmount = document.getElementById('dpSummaryAmount');

    function formatMoney(value) {
        return parseFloat(value || 0).toFixed(2);
    }

    function updateRowTotals(row) {
        const qty = parseFloat(row.querySelector('.dp-qty').value) || 0;
        const price = parseFloat(row.querySelector('.dp-price').value) || 0;
        const total = qty * price;
        row.querySelector('.dp-line-total').textContent = formatMoney(total);
        updateSummary();
    }

    function updateSummary() {
        const rows = table.querySelectorAll('tbody tr');
        let itemCount = 0;
        const quantitiesByUnit = new Map();
        let amount = 0;
        rows.forEach(row => {
            const name = row.querySelector('.item-lookup').value.trim();
            const qty = parseFloat(row.querySelector('.dp-qty').value) || 0;
            const total = parseFloat(row.querySelector('.dp-line-total').textContent) || 0;
            if (name !== '' && qty > 0) {
                itemCount += 1;
                const unit = row.querySelector('.dp-unit').value || 'unit';
                quantitiesByUnit.set(unit, (quantitiesByUnit.get(unit) || 0) + qty);
                amount += total;
            }
        });
        summaryItems.textContent = itemCount;
        summaryQuantity.textContent = [...quantitiesByUnit.entries()].map(([unit, quantity]) => `${quantity.toFixed(2)} ${unit}`).join(' · ') || '0.00';
        summaryAmount.textContent = 'KES ' + amount.toFixed(2);
        grandTotalEl.textContent = amount.toFixed(2);
    }

    function attachRowEvents(row) {
        const qtyInput = row.querySelector('.dp-qty');
        const priceInput = row.querySelector('.dp-price');
        const removeBtn = row.querySelector('.dp-remove-row');
        const itemInput = row.querySelector('.item-lookup');

        qtyInput.addEventListener('input', () => updateRowTotals(row));
        priceInput.addEventListener('input', () => updateRowTotals(row));
        row.querySelector('.dp-unit').addEventListener('change', updateSummary);
        removeBtn.addEventListener('click', () => {
            const rows = table.querySelectorAll('tbody tr');
            if (rows.length > 1) {
                row.remove();
                updateSummary();
            }
        });
        attachSearch(itemInput);
    }

    function attachSearch(input) {
        if (input._searchAttached) return;
        input._searchAttached = true;
        let box = null;
        let timer = null;
        input.addEventListener('focus', function() {
            const q = this.value.trim();
            if (q) {
                this.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        input.addEventListener('input', function() {
            const q = this.value.trim();
            if (timer) clearTimeout(timer);
            if (!q) { if (box) box.style.display = 'none'; return; }
            timer = setTimeout(() => {
                fetch('<?= url('admin/kitchen_items_api.php') ?>?q=' + encodeURIComponent(q))
                    .then(res => res.json())
                    .then(data => {
                        if (!box) {
                            box = document.createElement('div');
                            box.className = 'suggest-box border bg-white position-absolute';
                            box.style.position = 'absolute';
                            box.style.background = '#fff';
                            box.style.zIndex = 9999;
                            box.style.maxHeight = '240px';
                            box.style.overflow = 'auto';
                            box.style.borderRadius = '4px';
                            document.body.appendChild(box);
                        }
                        box.innerHTML = '';
                        if (!data.length) {
                            if (box) {
                                box.style.display = 'none';
                            }
                            return;
                        }
                        data.forEach(item => {
                            const itemRow = document.createElement('div');
                            itemRow.className = 'p-2 suggestion-item';
                            itemRow.style.cursor = 'pointer';
                            itemRow.innerHTML = '<strong>' + item.item_name + '</strong><br><small>' + (item.unit || '') + '</small>';
                            itemRow.addEventListener('click', () => {
                                input.value = item.item_name;
                                const unitSelect = input.closest('tr').querySelector('.dp-unit');
                                if (unitSelect && item.unit) {
                                    if (![...unitSelect.options].some(option => option.value === item.unit)) {
                                        unitSelect.add(new Option(item.unit, item.unit));
                                    }
                                    unitSelect.value = item.unit;
                                    updateSummary();
                                }
                                box.style.display = 'none';
                                input.focus();
                            });
                            box.appendChild(itemRow);
                        });
                        const rect = input.getBoundingClientRect();
                        box.style.left = rect.left + window.scrollX + 'px';
                        box.style.top = rect.bottom + window.scrollY + 'px';
                        box.style.minWidth = rect.width + 'px';
                        box.style.display = 'block';
                    });
            }, 250);
        });
        document.addEventListener('click', function(ev) {
            if (box && ev.target !== input && !box.contains(ev.target)) {
                box.style.display = 'none';
            }
        });
    }

    // Unit suggestion / add handler (shared with weekly shopping implementation)
    function attachUnitInput(input) {
        if (!input) return;
        if (input._unitAttached) return;
        input._unitAttached = true;
        let box = null;
        let timer = null;
        function fetchUnits() {
            return fetch('<?= url('admin/kitchen_units_api.php') ?>')
                .then(r => r.json())
                .then(list => list.map(u => (u.label || u.code || u)) );
        }

        input.addEventListener('input', function() {
            const q = this.value.trim();
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => {
                fetchUnits().then(list => {
                    if (!box) {
                        box = document.createElement('div');
                        box.className = 'suggest-box border bg-white position-absolute';
                        box.style.position = 'absolute';
                        box.style.background = '#fff';
                        box.style.zIndex = 9999;
                        box.style.maxHeight = '160px';
                        box.style.overflow = 'auto';
                        box.style.borderRadius = '4px';
                        document.body.appendChild(box);
                    }
                    box.innerHTML = '';
                    const qLower = (q || '').toLowerCase();
                    const matches = list.filter(l => l.toLowerCase().includes(qLower));
                    matches.slice(0,30).forEach(m => {
                        const row = document.createElement('div');
                        row.className = 'p-2 suggestion-item';
                        row.style.cursor = 'pointer';
                        row.textContent = m;
                        row.addEventListener('click', () => {
                            input.value = m;
                            box.style.display = 'none';
                            input.focus();
                        });
                        box.appendChild(row);
                    });
                    const exact = list.some(l => l.toLowerCase() === qLower);
                    if (q && !exact) {
                        const addRow = document.createElement('div');
                        addRow.className = 'p-2 suggestion-item text-primary';
                        addRow.style.cursor = 'pointer';
                        addRow.innerHTML = '<strong>Add "' + q + '"</strong>';
                        addRow.addEventListener('click', () => {
                            fetch('<?= url('admin/kitchen_units_api.php') ?>', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-Token': window.APP_CSRF_TOKEN
                                },
                                body: JSON.stringify({ value: q })
                            }).then(r => r.json()).then(newUnit => {
                                input.value = newUnit.label || q;
                                const dl = document.getElementById('kitchenUnitsList');
                                const opt = document.createElement('option'); opt.value = newUnit.label || q; dl.appendChild(opt);
                                box.style.display = 'none';
                                input.focus();
                            });
                        });
                        box.appendChild(addRow);
                    }
                    const rect = input.getBoundingClientRect();
                    box.style.left = rect.left + window.scrollX + 'px';
                    box.style.top = rect.bottom + window.scrollY + 'px';
                    box.style.minWidth = rect.width + 'px';
                    box.style.display = 'block';
                });
            }, 150);
        });
        document.addEventListener('click', function(ev) {
            if (box && ev.target !== input && !box.contains(ev.target)) {
                box.style.display = 'none';
            }
        });
    }

    function normalizeItemName(value) {
        return (value || '').trim().toLowerCase();
    }

    function validateDuplicateItems() {
        const rows = table.querySelectorAll('tbody tr');
        const names = {};
        let hasDuplicate = false;

        rows.forEach(row => {
            const input = row.querySelector('.item-lookup');
            const value = normalizeItemName(input.value);
            row.classList.remove('duplicate-row');
            if (!value) return;
            if (names[value]) {
                names[value].push(row);
                hasDuplicate = true;
            } else {
                names[value] = [row];
            }
        });

        Object.values(names).forEach(group => {
            if (group.length > 1) {
                group.forEach(row => row.classList.add('duplicate-row'));
            }
        });

        const warning = document.getElementById('dpDuplicateWarning');
        if (warning) {
            warning.classList.toggle('d-none', !hasDuplicate);
        }
        return !hasDuplicate;
    }

    function attachDuplicateValidation(row) {
        const input = row.querySelector('.item-lookup');
        input.addEventListener('blur', () => validateDuplicateItems());
        input.addEventListener('input', () => validateDuplicateItems());
    }

    addRowBtn.addEventListener('click', function() {
        const baseRow = table.querySelector('tbody tr');
        const newRow = baseRow.cloneNode(true);
        newRow.querySelectorAll('input').forEach(input => { input.value = input.type === 'number' ? '0' : ''; });
        newRow.querySelector('.dp-unit').value = 'kg';
        newRow.querySelector('.dp-line-total').textContent = '0.00';
        table.querySelector('tbody').appendChild(newRow);
        attachRowEvents(newRow);
        attachDuplicateValidation(newRow);
    });

    const form = document.getElementById('dailyPurchaseForm');
    form.addEventListener('submit', function(event) {
        if (!validateDuplicateItems()) {
            event.preventDefault();
            event.stopPropagation();
            return false;
        }
    });

    table.querySelectorAll('tbody tr').forEach(row => { attachRowEvents(row); attachDuplicateValidation(row); });
    updateSummary();
})();
</script>
