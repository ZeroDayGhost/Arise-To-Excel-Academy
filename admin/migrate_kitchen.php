<?php
$pageTitle = 'Kitchen Schema Migration';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
    $stmt->execute(['column' => $column]);
    return (bool) $stmt->fetchColumn();
}

function runMigration(PDO $pdo): array
{
    $messages = [];
    $errors = [];

    try {
        $pdo->beginTransaction();

        if (!columnExists($pdo, 'kitchen_inventory', 'purchase_type')) {
            $pdo->exec("ALTER TABLE kitchen_inventory ADD COLUMN purchase_type VARCHAR(30) NOT NULL DEFAULT 'weekly' AFTER supplier");
            $messages[] = 'Added kitchen_inventory.purchase_type';
        } else {
            $messages[] = 'kitchen_inventory.purchase_type already exists';
        }

        if (!columnExists($pdo, 'kitchen_inventory', 'category')) {
            $pdo->exec("ALTER TABLE kitchen_inventory ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'Kitchen' AFTER purchase_type");
            $messages[] = 'Added kitchen_inventory.category';
        } else {
            $messages[] = 'kitchen_inventory.category already exists';
        }

        if (!columnExists($pdo, 'kitchen_inventory', 'academic_year')) {
            $pdo->exec("ALTER TABLE kitchen_inventory ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER category");
            $messages[] = 'Added kitchen_inventory.academic_year';
        } else {
            $messages[] = 'kitchen_inventory.academic_year already exists';
        }

        if (!columnExists($pdo, 'kitchen_inventory', 'term')) {
            $pdo->exec("ALTER TABLE kitchen_inventory ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
            $messages[] = 'Added kitchen_inventory.term';
        } else {
            $messages[] = 'kitchen_inventory.term already exists';
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_daily_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            category VARCHAR(80) NOT NULL DEFAULT 'Daily',
            supplier VARCHAR(150) NULL,
            notes TEXT NULL,
            purchase_date DATE NOT NULL,
            academic_year VARCHAR(4) NOT NULL DEFAULT '',
            term VARCHAR(20) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        if (!columnExists($pdo, 'kitchen_daily_purchases', 'quantity')) {
            $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER item_name");
            $messages[] = 'Added kitchen_daily_purchases.quantity';
        } else {
            $messages[] = 'kitchen_daily_purchases.quantity already exists';
        }
        $messages[] = 'Ensured kitchen_daily_purchases exists';

        $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_shopping (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(150) NULL,
            shopping_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            academic_year VARCHAR(4) NOT NULL DEFAULT '',
            term VARCHAR(20) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $messages[] = 'Ensured weekly_shopping exists';

        $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_shopping_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            weekly_shopping_id INT UNSIGNED NOT NULL,
            item_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit VARCHAR(30) NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_weekly_shopping_items_weekly FOREIGN KEY (weekly_shopping_id) REFERENCES weekly_shopping(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $messages[] = 'Ensured weekly_shopping_items exists';

        if (!columnExists($pdo, 'kitchen_daily_purchases', 'academic_year')) {
            $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER purchase_date");
            $messages[] = 'Added kitchen_daily_purchases.academic_year';
        } else {
            $messages[] = 'kitchen_daily_purchases.academic_year already exists';
        }
        if (!columnExists($pdo, 'kitchen_daily_purchases', 'term')) {
            $pdo->exec("ALTER TABLE kitchen_daily_purchases ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
            $messages[] = 'Added kitchen_daily_purchases.term';
        } else {
            $messages[] = 'kitchen_daily_purchases.term already exists';
        }

        if (!columnExists($pdo, 'weekly_shopping', 'academic_year')) {
            $pdo->exec("ALTER TABLE weekly_shopping ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER total_amount");
            $messages[] = 'Added weekly_shopping.academic_year';
        } else {
            $messages[] = 'weekly_shopping.academic_year already exists';
        }
        if (!columnExists($pdo, 'weekly_shopping', 'term')) {
            $pdo->exec("ALTER TABLE weekly_shopping ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
            $messages[] = 'Added weekly_shopping.term';
        } else {
            $messages[] = 'weekly_shopping.term already exists';
        }

        if (columnExists($pdo, 'kitchen_stock_movements', 'id')) {
            if (!columnExists($pdo, 'kitchen_stock_movements', 'transaction_date')) {
                $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN transaction_date DATE NOT NULL DEFAULT CURRENT_DATE AFTER recorded_by");
                $messages[] = 'Added kitchen_stock_movements.transaction_date';
            } else {
                $messages[] = 'kitchen_stock_movements.transaction_date already exists';
            }
            if (!columnExists($pdo, 'kitchen_stock_movements', 'academic_year')) {
                $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN academic_year VARCHAR(4) NOT NULL DEFAULT '' AFTER transaction_date");
                $messages[] = 'Added kitchen_stock_movements.academic_year';
            } else {
                $messages[] = 'kitchen_stock_movements.academic_year already exists';
            }
            if (!columnExists($pdo, 'kitchen_stock_movements', 'term')) {
                $pdo->exec("ALTER TABLE kitchen_stock_movements ADD COLUMN term VARCHAR(20) NOT NULL DEFAULT '' AFTER academic_year");
                $messages[] = 'Added kitchen_stock_movements.term';
            } else {
                $messages[] = 'kitchen_stock_movements.term already exists';
            }
        }

        $pdo->commit();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        $errors[] = 'Migration failed: ' . h($ex->getMessage());
    }

    return [$messages, $errors];
}

$messages = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$messages, $errors] = runMigration($pdo);
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Admin</p>
        <h1>Kitchen Schema Migration</h1>
        <p class="mb-0 text-muted">Run the idempotent kitchen schema migration for purchase types, daily purchases, and weekly shopping tables.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/dashboard.php') ?>">Back to Dashboard</a>
    </div>
</div>

<section class="panel mb-4">
    <p>Click the button below to apply the kitchen schema migration safely. This page is protected by the same admin login used elsewhere.</p>
    <form method="post">
        <button type="submit" class="btn btn-primary">Run Migration</button>
    </form>
</section>

<?php if ($messages): ?>
<section class="panel mb-4">
    <h2>Migration Results</h2>
    <ul>
        <?php foreach ($messages as $message): ?>
            <li><?= h($message) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($errors): ?>
<section class="panel mb-4">
    <h2 class="text-danger">Errors</h2>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
