<?php
$pageTitle = 'School Expenses';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'expenses.access')) {
    flash('error', 'You do not have permission to access Expenses.');
    redirect('admin/dashboard.php');
}
$canAddExpense = current_admin_has_permission($pdo, 'expenses.save');
$canUpdateExpense = current_admin_has_permission($pdo, 'expenses.update');
$canDeleteExpense = current_admin_has_permission($pdo, 'expenses.delete');

ensure_kitchen_tables($pdo);
$currentContext = current_academic_context($pdo);
$baseCategories = expense_category_options();
$customCategories = $pdo->query("SELECT DISTINCT category FROM school_expenses WHERE category <> '' ORDER BY category ASC")->fetchAll();
$categories = $baseCategories;
foreach ($customCategories as $customCategory) {
    if (!school_expense_category_is_kitchen_managed((string) $customCategory['category']) && !in_array($customCategory['category'], $categories, true)) {
        $categories[] = $customCategory['category'];
    }
}
$filterCategories = $categories;
if (!in_array('Kitchen', $filterCategories, true)) {
    $filterCategories[] = 'Kitchen';
}
$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['expense_id'] ?? 0));
$form = [
    'id' => '',
    'item_name' => '',
    'category' => '',
    'amount' => '',
    'quantity' => '1',
    'unit' => '',
    'expense_date' => date('Y-m-d'),
    'description' => '',
    'custom_category_name' => '',
];

function school_expense_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM school_expenses WHERE id = :id");
    $statement->execute(['id' => $id]);
    $expense = $statement->fetch();

    return $expense ?: null;
}

function school_expense_category_is_kitchen_managed(string $category): bool
{
    $category = strtolower(trim($category));
    return in_array($category, ['kitchen', 'kitchen purchases', 'wholesale', 'gas refill'], true)
        || str_starts_with($category, 'kitchen ');
}

function school_expense_is_kitchen_managed(PDO $pdo, array $expense): bool
{
    if (school_expense_category_is_kitchen_managed((string) ($expense['category'] ?? ''))) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM kitchen_purchase_records WHERE school_expense_id = :expense_id LIMIT 1');
    $stmt->execute(['expense_id' => (int) ($expense['id'] ?? 0)]);
    return (bool) $stmt->fetchColumn();
}

function school_expense_duplicate_exists(PDO $pdo, string $itemName, string $category, string $expenseDate, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM school_expenses
            WHERE item_name = :item_name
              AND category = :category
              AND expense_date = :expense_date";
    $params = [
        'item_name' => $itemName,
        'category' => $category,
        'expense_date' => $expenseDate,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!$canUpdateExpense) {
        rbac_deny($pdo, 'You do not have permission to update expenses.');
    }
    $expense = school_expense_by_id($pdo, $editingId);
    if ($expense) {
        if (school_expense_is_kitchen_managed($pdo, $expense)) {
            flash('error', 'Kitchen purchases are managed through the Kitchen module so stock and expense history stay connected.');
            redirect('admin/kitchen_expenditure.php');
        }
        if (!in_array($expense['category'], $baseCategories, true)) {
            $expense['custom_category_name'] = $expense['category'];
            $expense['category'] = 'Other';
        }
        $form = array_merge($form, $expense);
    } else {
        flash('error', 'Expense item was not found.');
        redirect('admin/school_expenses.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $expenseId = (int) ($_POST['expense_id'] ?? 0);
    $requiredPermission = match ($action) {
        'delete' => 'expenses.delete',
        'update' => 'expenses.update',
        default => 'expenses.save',
    };
    require_action_permission($pdo, $requiredPermission);

    $originalExpense = $expenseId > 0 ? school_expense_by_id($pdo, $expenseId) : null;
    if ($originalExpense && school_expense_is_kitchen_managed($pdo, $originalExpense)) {
        flash('error', 'Kitchen purchases are managed through the Kitchen module so stock and expense history stay connected.');
        redirect('admin/kitchen_expenditure.php');
    }

    if ($action === 'delete') {
        $expense = $originalExpense;

        if (!$expense) {
            $errors[] = 'Choose an expense to delete.';
        } else {
            $statement = $pdo->prepare("DELETE FROM school_expenses WHERE id = :id");
            $statement->execute(['id' => $expenseId]);
            flash('success', 'Expense deleted successfully.');
            redirect('admin/school_expenses.php');
        }
    } else {
        $form = [
            'id' => $expenseId,
            'item_name' => trim($_POST['item_name'] ?? ''),
            'category' => trim($_POST['category'] ?? ''),
            'custom_category_name' => trim($_POST['custom_category_name'] ?? ''),
            'amount' => trim($_POST['amount'] ?? ''),
            'quantity' => trim($_POST['quantity'] ?? '1'),
            'unit' => trim($_POST['unit'] ?? ''),
            'expense_date' => trim($_POST['expense_date'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        if ($form['item_name'] === '') {
            $errors[] = 'Item name is required.';
        }
        if (!in_array($form['category'], $baseCategories, true)) {
            $errors[] = 'Choose a valid category.';
        }
        $categoryName = $form['category'];
        if ($form['category'] === 'Other') {
            if ($form['custom_category_name'] === '') {
                $errors[] = 'Enter the custom category name.';
            } elseif (strlen($form['custom_category_name']) > 40) {
                $errors[] = 'Custom category name must be 40 characters or less.';
            } else {
                $categoryName = $form['custom_category_name'];
            }
        }
        if (school_expense_category_is_kitchen_managed($categoryName)) {
            $errors[] = 'Record food, gas, and kitchen supply expenses through the Kitchen module to avoid duplicate entries.';
        }
        if (!valid_money_value($form['amount']) || (float) $form['amount'] <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }
        if ($form['quantity'] === '') {
            $form['quantity'] = '1';
        } elseif (!valid_quantity_value($form['quantity']) || (float) $form['quantity'] <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }
        if ($form['unit'] !== '' && !array_key_exists($form['unit'], kitchen_available_unit_options($pdo))) {
            $errors[] = 'Choose a valid quantity unit.';
        }
        if (!valid_date_value($form['expense_date'])) {
            $errors[] = 'Date must be valid.';
        }
        if (!$errors && school_expense_duplicate_exists($pdo, $form['item_name'], $categoryName, $form['expense_date'], $action === 'update' ? $expenseId : 0)) {
            $errors[] = 'This expense already exists for the same category and date.';
        }

        if (!$errors) {
            $totalAmount = (float) $form['amount'] * (float) $form['quantity'];
            $params = [
                'item_name' => $form['item_name'],
                'category' => $categoryName,
                'amount' => (float) $form['amount'],
                'quantity' => (float) $form['quantity'],
                'unit' => $form['unit'] !== '' ? $form['unit'] : null,
                'total_amount' => $totalAmount,
                'expense_date' => $form['expense_date'],
                'description' => $form['description'],
            ];

            if ($action === 'update' && $expenseId > 0) {
                $params['id'] = $expenseId;
                $statement = $pdo->prepare(
                    "UPDATE school_expenses
                     SET item_name = :item_name,
                         category = :category,
                         amount = :amount,
                         quantity = :quantity,
                         unit = :unit,
                         total_amount = :total_amount,
                         expense_date = :expense_date,
                         description = :description
                     WHERE id = :id"
                );
                $statement->execute($params);
                if ($originalExpense && in_array($originalExpense['category'], ['Kitchen', 'WHOLESALE'], true) && in_array($categoryName, ['Kitchen', 'WHOLESALE'], true)) {
                    kitchen_inventory_update_for_expense($pdo, $originalExpense, [
                        'item_name' => $form['item_name'],
                        'amount' => $form['amount'],
                        'quantity' => $form['quantity'],
                        'unit' => $form['unit'],
                        'expense_date' => $form['expense_date'],
                        'supplier' => null,
                        'category' => $categoryName,
                    ]);
                }
                flash('success', 'Expense updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO school_expenses (item_name, category, amount, quantity, unit, total_amount, expense_date, description)
                     VALUES (:item_name, :category, :amount, :quantity, :unit, :total_amount, :expense_date, :description)"
                );
                $statement->execute($params);
                flash('success', 'Expense added successfully.');
            }

            redirect('admin/school_expenses.php');
        }
    }
}

$search = trim($_GET['search'] ?? '');
$filterCategory = in_array(($_GET['category'] ?? ''), $filterCategories, true) ? $_GET['category'] : '';
$dateFrom = valid_date_value($_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$dateTo = valid_date_value($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(item_name LIKE :search_item OR description LIKE :search_description)';
    $params['search_item'] = '%' . $search . '%';
    $params['search_description'] = '%' . $search . '%';
}
if ($filterCategory !== '') {
    if ($filterCategory === 'Kitchen') {
        $where[] = "(e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill')
                    OR e.category LIKE 'Kitchen %'
                    OR EXISTS (SELECT 1 FROM kitchen_purchase_records kp_filter WHERE kp_filter.school_expense_id = e.id))";
    } else {
        $where[] = 'e.category = :category';
        $params['category'] = $filterCategory;
    }
}
if ($dateFrom !== '') {
    $where[] = 'e.expense_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'e.expense_date <= :date_to';
    $params['date_to'] = $dateTo;
}

if ($dateFrom === '' && $dateTo === '' && !empty($currentContext['start_date']) && !empty($currentContext['end_date'])) {
    $hasExpensePeriod = db_has_column($pdo, 'school_expenses', 'academic_year') && db_has_column($pdo, 'school_expenses', 'term');
    $kitchenPeriodMatch = 'EXISTS (
        SELECT 1 FROM kitchen_purchase_records kp_period
        WHERE kp_period.school_expense_id = e.id
          AND kp_period.academic_year = :active_kitchen_year
          AND kp_period.term = :active_kitchen_term
    )';
    $params['active_kitchen_year'] = (string) $currentContext['academic_year'];
    $params['active_kitchen_term'] = (string) $currentContext['term'];

    if ($hasExpensePeriod) {
        $where[] = "({$kitchenPeriodMatch}
            OR (NULLIF(e.academic_year, '') = :active_expense_year AND e.term = :active_expense_term)
            OR ((NULLIF(e.academic_year, '') IS NULL OR NULLIF(e.term, '') IS NULL)
                AND e.expense_date BETWEEN :active_term_start AND :active_term_end))";
        $params['active_expense_year'] = (string) $currentContext['academic_year'];
        $params['active_expense_term'] = (string) $currentContext['term'];
    } else {
        $where[] = "({$kitchenPeriodMatch} OR e.expense_date BETWEEN :active_term_start AND :active_term_end)";
    }
    $params['active_term_start'] = $currentContext['start_date'];
    $params['active_term_end'] = $currentContext['end_date'];
}

$sql = "SELECT e.*,
               CASE WHEN e.category IN ('Kitchen', 'Kitchen Purchases', 'WHOLESALE', 'Gas Refill')
                          OR e.category LIKE 'Kitchen %'
                          OR EXISTS (SELECT 1 FROM kitchen_purchase_records kp WHERE kp.school_expense_id = e.id)
                    THEN 1 ELSE 0 END AS kitchen_managed
        FROM school_expenses e";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY expense_date DESC, id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$expenses = $statement->fetchAll();
$totalExpenses = array_sum(array_map(fn($expense) => (float) $expense['total_amount'], $expenses));
$canViewKitchenRecords = current_admin_can_access_module($pdo, 'Kitchen');

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Expense Tracking</p>
        <h1>School Items and Expenses</h1>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/reports.php') ?>">Reports</a>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="row g-4">
    <?php if ($canAddExpense || $canUpdateExpense): ?><div class="col-lg-4">
        <section class="panel">
            <h2><?= (int) $form['id'] > 0 ? 'Edit Expense' : 'New Expense' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="expense_id" value="<?= (int) $form['id'] ?>">
                <div class="col-12">
                    <label class="form-label" for="item_name">Item Name</label>
                    <input class="form-control" id="item_name" name="item_name" value="<?= h((string) $form['item_name']) ?>" placeholder="Printing papers" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <option value="">Choose category...</option>
                        <?php foreach ($baseCategories as $category): ?>
                            <option value="<?= h($category) ?>" <?= $form['category'] === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 custom-category-field">
                    <label class="form-label" for="custom_category_name">Enter Category Name</label>
                    <input class="form-control" id="custom_category_name" name="custom_category_name" value="<?= h((string) $form['custom_category_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="expense_date">Date</label>
                    <input class="form-control" type="date" id="expense_date" name="expense_date" value="<?= h((string) $form['expense_date']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" type="number" min="0.01" step="0.01" id="amount" name="amount" value="<?= h((string) $form['amount']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="quantity">Quantity</label>
                    <input class="form-control" type="number" min="0.01" step="0.01" id="quantity" name="quantity" value="<?= h((string) $form['quantity']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="unit">Quantity Unit</label>
                    <select class="form-select" id="unit" name="unit">
                        <option value="">Not specified</option>
                        <?php foreach (kitchen_available_unit_options($pdo) as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= ($form['unit'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= h((string) $form['description']) ?></textarea>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <?php if ($canAddExpense && (int) $form['id'] === 0): ?><button class="btn btn-primary" name="action" value="save" type="submit">Save</button><?php endif; ?>
                        <?php if ($canUpdateExpense && (int) $form['id'] > 0): ?><button class="btn btn-outline-primary" name="action" value="update" type="submit">Update</button><?php endif; ?>
                    </div>
                </div>
            </form>
        </section>
    </div><?php endif; ?>
    <div class="<?= ($canAddExpense || $canUpdateExpense) ? 'col-lg-8' : 'col-12' ?>">
        <section class="panel">
            <div class="panel-heading">
                <h2>Expense Records</h2>
                <strong><?= money($totalExpenses) ?></strong>
            </div>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-3"><input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search expenses"></div>
                <div class="col-md-3">
                    <select class="form-select" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($filterCategories as $category): ?>
                            <option value="<?= h($category) ?>" <?= $filterCategory === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><input class="form-control" type="date" name="date_from" value="<?= h($dateFrom) ?>"></div>
                <div class="col-md-2"><input class="form-control" type="date" name="date_to" value="<?= h($dateTo) ?>"></div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Item</th><th>Category</th><th>Amount</th><th>Qty</th><th>Total</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?= h($expense['item_name']) ?><br><small><?= h($expense['description']) ?></small></td>
                                <td><?= h($expense['category']) ?></td>
                                <td><?= money((float) $expense['amount']) ?></td>
                                <td><?= h((string) $expense['quantity']) ?> <?= h($expense['unit'] ?? '') ?></td>
                                <td><?= money((float) $expense['total_amount']) ?></td>
                                <td><?= h($expense['expense_date']) ?></td>
                                <td>
                                    <?php if (!empty($expense['kitchen_managed'])): ?>
                                        <?php if ($canViewKitchenRecords): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/kitchen_expenditure.php') ?>">Kitchen records</a><?php else: ?><span class="text-muted">Managed in Kitchen</span><?php endif; ?>
                                    <?php else: ?>
                                        <div class="action-row">
                                            <?php if ($canUpdateExpense): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/school_expenses.php?edit=' . $expense['id']) ?>">Edit</a><?php endif; ?>
                                            <?php if ($canDeleteExpense): ?><form method="post" onsubmit="return confirm('Delete this expense?');">
                                                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                                            </form><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$expenses): ?><tr><td colspan="7">No expenses found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
