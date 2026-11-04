<?php
// expects variables:
// $receipt_title, $receipt_no, $receipt_date, $receipt_status,
// $payer_name, $payer_meta (array of key=>value),
// $items (array of ['description','size'?, 'quantity', 'unit_price','line_total']),
// $totals (array with keys subtotal, discount, grand_total, amount_paid, balance),
// $payment_method, $mpesa_code, $served_by
?>
<div class="action-row receipt-print-actions" aria-label="Receipt output options">
    <?php if (!empty($back_href)): ?>
        <a class="btn-receipt btn-back" href="<?= h($back_href) ?>">Back</a>
    <?php endif; ?>
    <?php if (!empty($pdf_href)): ?>
        <a class="btn-receipt btn-pdf" href="<?= h($pdf_href) ?>">Download PDF</a>
    <?php endif; ?>
    <button class="btn-receipt btn-print" type="button" data-receipt-print="thermal" onclick="window.printThermalReceipt()">Print thermal receipt</button>
    <button class="btn-receipt btn-print-a4" type="button" data-receipt-print="a4" onclick="document.body.setAttribute('data-receipt-print-mode','a4');window.print()">Print A4 receipt</button>
</div>

<div class="receipt-container receipt-a4-layout">

    <!-- Header Section -->
    <div class="receipt-header">
        <div class="receipt-header-logo">
            <img src="<?= asset('images/school-logo.jpg') ?>" alt="School Logo">
        </div>
        <div class="receipt-header-details">
            <h2><?= defined('SCHOOL_NAME') ? h(SCHOOL_NAME) : 'School' ?></h2>
            <div class="school-address">
                <?= defined('SCHOOL_ADDRESS') ? h(SCHOOL_ADDRESS) : '' ?>
            </div>
            <div class="school-contact">
                <?php if (defined('SCHOOL_PHONE')): ?><?= h(SCHOOL_PHONE) ?> |<?php endif; ?>
                <?= defined('SCHOOL_EMAIL') ? h(SCHOOL_EMAIL) : '' ?>
            </div>
        </div>
    </div>

    <!-- Receipt Title -->
    <div class="receipt-title"><?= h($receipt_title ?? 'PAYMENT RECEIPT') ?></div>

    <!-- Receipt Information Card -->
    <div class="receipt-info-card">
        <div class="receipt-info-item">
            <span class="receipt-info-label">Receipt Number</span>
            <span class="receipt-info-value"><?= h($receipt_no ?? '') ?></span>
        </div>
        <div class="receipt-info-item">
            <span class="receipt-info-label">Receipt Type</span>
            <span class="receipt-info-value" style="color: #1179a8; font-weight: 600;"><?= h($receipt_type ?? 'PAYMENT') ?></span>
        </div>
        <div class="receipt-info-item">
            <span class="receipt-info-label">Date</span>
            <span class="receipt-info-value"><?= h($receipt_date ?? '') ?></span>
        </div>
        <div class="receipt-info-item">
            <span class="receipt-info-label">Status</span>
            <span class="payment-status-badge <?= strtolower($receipt_status ?? 'partial') ?>">
                <?= h(strtoupper($receipt_status ?? 'PARTIAL')) ?>
            </span>
        </div>
    </div>

    <!-- Student Details Section -->
    <h3 class="receipt-section-title">Student Information</h3>
    <table class="receipt-details-table">
        <tbody>
            <tr>
                <td>Student Name</td>
                <td><?= h($payer_name ?? '') ?></td>
            </tr>
            <?php if (!empty($payer_meta) && is_array($payer_meta)): ?>
                <?php foreach ($payer_meta as $k => $v): ?>
                    <tr>
                        <td><?= h($k) ?></td>
                        <td><?= h($v) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Payment Items Section -->
    <?php if (!empty($items) && is_array($items)): ?>
        <h3 class="receipt-section-title">Payment Details</h3>
        <table class="receipt-items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <?php if (!empty($items) && is_array($items) && isset($items[0]['size'])): ?>
                        <th>Size</th>
                    <?php endif; ?>
                    <?php if (!empty($receipt_has_fee_balances)): ?>
                        <th style="text-align: right;">Fee balance after</th>
                    <?php endif; ?>
                    <th style="text-align: right;">Qty</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= h($it['description'] ?? ($it['uniform_name'] ?? '')) ?></td>
                        <?php if (isset($it['size'])): ?>
                            <td><?= h($it['size']) ?></td>
                        <?php endif; ?>
                        <?php if (!empty($receipt_has_fee_balances)): ?>
                            <td style="text-align: right;"><?= is_numeric($it['fee_balance_after'] ?? null) ? money((float) $it['fee_balance_after']) : '—' ?></td>
                        <?php endif; ?>
                        <td style="text-align: right;"><?= (int)($it['quantity'] ?? 1) ?></td>
                        <td style="text-align: right;"><?= money((float)($it['unit_price'] ?? 0)) ?></td>
                        <td style="text-align: right;"><?= money((float)($it['line_total'] ?? ($it['amount'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Totals Section -->
    <div class="receipt-totals">
        <div class="totals-column">
            <div class="totals-row">
                <span class="totals-label">Subtotal:</span>
                <span class="totals-value"><?= money((float)($totals['subtotal'] ?? 0)) ?></span>
            </div>
            <?php if (!empty($totals['discount'])): ?>
                <div class="totals-row">
                    <span class="totals-label">Discount:</span>
                    <span class="totals-value">-<?= money((float)($totals['discount'] ?? 0)) ?></span>
                </div>
            <?php endif; ?>
            <div class="totals-row total">
                <span class="totals-label">Grand Total:</span>
                <span class="totals-value"><?= money((float)($totals['grand_total'] ?? 0)) ?></span>
            </div>
            <div class="totals-row">
                <span class="totals-label">Amount Paid:</span>
                <span class="totals-value"><?= money((float)($totals['amount_paid'] ?? 0)) ?></span>
            </div>
            <div class="totals-row balance <?= ((float)($totals['balance'] ?? 0) <= 0.005) ? 'cleared' : '' ?>">
                <span class="totals-label"><?= h($totals['balance_label'] ?? 'Balance') ?>:</span>
                <span class="totals-value"><?= money((float)($totals['balance'] ?? 0)) ?></span>
            </div>
            <?php if (array_key_exists('fee_balance_after', $totals)): ?>
                <div class="totals-row">
                    <span class="totals-label">Outstanding fee balance(s) after payment:</span>
                    <span class="totals-value"><?= money((float) $totals['fee_balance_after']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Method Info -->
    <?php if (!empty($payment_method) || !empty($mpesa_code)): ?>
        <div class="payment-info">
            <?php if (!empty($payment_method)): ?>
                <div class="payment-info-item">
                    <span class="payment-info-label">Payment Method:</span>
                    <?= h($payment_method) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($mpesa_code)): ?>
                <div class="payment-info-item">
                    <span class="payment-info-label">M-PESA Code:</span>
                    <?= h($mpesa_code) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="receipt-footer">
        <div class="footer-section">
            <div class="footer-label">Received By</div>
            <div class="footer-line"></div>
            <div class="footer-value"><?= h($served_by ?? 'Admin') ?></div>
        </div>
        <div class="footer-section">
            <div class="footer-label">Authorized Signature</div>
            <div class="footer-line"></div>
        </div>
        <div class="footer-section">
            <div class="footer-label">School Stamp</div>
            <div class="footer-line"></div>
        </div>
        <div class="receipt-footer-text">
            <strong>Thank you for your payment.</strong> This receipt is computer-generated and is valid without a signature.
            Please retain this receipt for your records. For inquiries, contact <?= defined('SCHOOL_EMAIL') ? h(SCHOOL_EMAIL) : 'the school office' ?>.
        </div>
    </div>
</div>

<?php
$thermalAdmission = $payer_meta['Reg Number'] ?? $payer_meta['Admission number'] ?? $payer_meta['Admission Number'] ?? '';
$thermalClass = $payer_meta['Class Level'] ?? $payer_meta['Class'] ?? '';
$thermalPeriod = $payer_meta['Term'] ?? $payer_meta['Academic period'] ?? '';
$thermalStatus = $receipt_status ?? 'Recorded';
$thermalReceiptType = $receipt_type ?? 'PAYMENT';
$thermalAmount = (float) ($totals['amount_paid'] ?? $totals['grand_total'] ?? 0);
$thermalBalance = $totals['balance'] ?? null;
$thermalReference = $mpesa_reference_text ?? '';
$thermalActionNote = is_array($payer_meta ?? null) ? (string) ($payer_meta['Action Note'] ?? '') : '';
?>
<section class="thermal-receipt" aria-label="Compact thermal receipt">
    <header class="thermal-receipt-header">
        <img class="thermal-receipt-logo" src="<?= h(asset('images/school-logo.jpg')) ?>" alt="Arise To Excel Academy logo">
        <strong><?= defined('SCHOOL_NAME') ? h(SCHOOL_NAME) : 'Arise To Excel Academy' ?></strong>
        <?php if (defined('SCHOOL_PHONE') && SCHOOL_PHONE !== ''): ?><span><?= h(SCHOOL_PHONE) ?></span><?php endif; ?>
        <b>PAYMENT RECEIPT</b>
    </header>
    <div class="thermal-receipt-row thermal-receipt-number"><span>Receipt No.</span><strong><?= h($receipt_no ?? '') ?></strong></div>
    <div class="thermal-receipt-row"><span>Date</span><strong><?= h($receipt_date ?? '') ?></strong></div>
    <div class="thermal-receipt-row thermal-receipt-type"><span>Type</span><strong><?= h($thermalReceiptType) ?></strong></div>
    <div class="thermal-receipt-divider"></div>
    <div class="thermal-receipt-field thermal-receipt-student"><span>Student</span><strong><?= h($payer_name ?? '') ?></strong></div>
    <?php if ($thermalAdmission !== ''): ?><div class="thermal-receipt-row"><span>Admission no.</span><strong><?= h($thermalAdmission) ?></strong></div><?php endif; ?>
    <?php if ($thermalClass !== ''): ?><div class="thermal-receipt-row"><span>Class</span><strong><?= h($thermalClass) ?></strong></div><?php endif; ?>
    <?php if ($thermalPeriod !== ''): ?><div class="thermal-receipt-row"><span>Period</span><strong><?= h($thermalPeriod) ?></strong></div><?php endif; ?>
    <?php if (!empty($payment_method)): ?><div class="thermal-receipt-row"><span>Method</span><strong><?= h($payment_method) ?></strong></div><?php endif; ?>
    <?php if (!empty($mpesa_code)): ?><div class="thermal-receipt-field"><span>M-PESA code</span><strong><?= h($mpesa_code) ?></strong></div><?php endif; ?>
    <?php if ($thermalReference !== ''): ?><div class="thermal-receipt-field"><span>Payment reference</span><strong><?= h($thermalReference) ?></strong></div><?php endif; ?>
    <?php if (isset($balance_before_payment) && is_numeric($balance_before_payment)): ?><div class="thermal-receipt-row"><span>Previous balance</span><strong><?= money((float) $balance_before_payment) ?></strong></div><?php endif; ?>
    <div class="thermal-receipt-divider"></div>
    <div class="thermal-receipt-amount"><span>Amount received</span><strong><?= money($thermalAmount) ?></strong></div>
    <?php if ($thermalBalance !== null && is_numeric($thermalBalance)): ?><div class="thermal-receipt-row thermal-receipt-balance"><span>Balance after payment</span><strong><?= money((float) $thermalBalance) ?></strong></div><?php endif; ?>
    <div class="thermal-receipt-row"><span>Status</span><strong><?= h(strtoupper($thermalStatus)) ?></strong></div>
    <?php if (($thermalStatus !== 'PAID' && $thermalStatus !== 'PARTIAL' && $thermalStatus !== 'Recorded') && $thermalActionNote !== ''): ?><div class="thermal-receipt-field"><span>Action note</span><strong><?= h($thermalActionNote) ?></strong></div><?php endif; ?>
    <footer class="thermal-receipt-footer">
        <span>Thank you for your payment.</span>
        <span>Please keep this receipt for your records.</span>
        <strong class="thermal-receipt-signoff">Arise &amp; Excel</strong>
    </footer>
</section>
<script src="<?= asset_version('js/receipt-print.js') ?>"></script>
