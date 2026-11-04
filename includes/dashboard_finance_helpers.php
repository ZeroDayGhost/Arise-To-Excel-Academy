<?php

function dashboard_financial_totals(
    float $feeCollections,
    float $uniformCollections,
    float $feedingCollections,
    float $transportCollections,
    float $schoolExpenses,
    float $fuelExpenses,
    float $activityIncome = 0.0
): array {
    $income = $feeCollections + $uniformCollections + $feedingCollections + $transportCollections + $activityIncome;
    $expenses = $schoolExpenses + $fuelExpenses;

    return [
        'income' => $income,
        'expenses' => $expenses,
        'net_position' => $income - $expenses,
        'activity_income' => $activityIncome,
    ];
}
