<?php
/**
 * Migration: Consolidate WHOLESALE category into Kitchen category
 * This script updates all school_expenses and kitchen_inventory records
 * where category = 'WHOLESALE' to category = 'Kitchen'
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$results = [];

try {
    // Update school_expenses
    $stmt = $pdo->prepare("UPDATE school_expenses SET category = 'Kitchen' WHERE category = 'WHOLESALE'");
    $stmt->execute();
    $results['school_expenses_updated'] = $stmt->rowCount();

    // Update kitchen_inventory
    $stmt = $pdo->prepare("UPDATE kitchen_inventory SET category = 'Kitchen' WHERE category = 'WHOLESALE'");
    $stmt->execute();
    $results['kitchen_inventory_updated'] = $stmt->rowCount();

    // Update kitchen_daily_purchases
    $stmt = $pdo->prepare("UPDATE kitchen_daily_purchases SET category = 'Kitchen' WHERE category = 'WHOLESALE'");
    $stmt->execute();
    $results['kitchen_daily_purchases_updated'] = $stmt->rowCount();

    // Update kitchen_items
    $stmt = $pdo->prepare("UPDATE kitchen_items SET category = 'Kitchen' WHERE category = 'WHOLESALE'");
    $stmt->execute();
    $results['kitchen_items_updated'] = $stmt->rowCount();

    $_SESSION['success'] = 'Migration complete! ' . array_sum($results) . ' records updated.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Migration failed: ' . $e->getMessage();
}

header('Location: ' . url('admin/reports.php'));
exit;
