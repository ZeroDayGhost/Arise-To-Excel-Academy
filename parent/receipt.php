<?php
require_once __DIR__ . '/../includes/parent_auth.php';

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$receipt = parent_portal_receipt($pdo, $parentUserId, $paymentId);
if (!$receipt) {
    http_response_code(404);
    exit('The requested receipt is not available.');
}

require_once __DIR__ . '/../includes/school_receipt_pdf.php';
if (($_GET['format'] ?? '') === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $receipt['receipt_no']) . '.pdf"');
    echo build_school_receipt_pdf($receipt);
    exit;
}

$pageTitle = 'Payment Receipt';
require __DIR__ . '/../includes/parent_header.php';
?>
<div class="parent-page-heading parent-print-actions">
    <div><h1>Payment receipt</h1><p>Official record of a school payment.</p></div>
    <div class="parent-receipt-actions receipt-print-actions">
        <a class="btn btn-outline-primary" href="<?= url('parent/payments.php') ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to payments</a>
        <a class="btn btn-outline-primary" href="<?= url('parent/receipt.php?id=' . (int) $receipt['id'] . '&format=pdf') ?>"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Download PDF</a>
        <button class="btn btn-primary" type="button" data-receipt-print="a4" onclick="document.body.setAttribute('data-receipt-print-mode','a4');window.print()"><i class="fa-solid fa-print" aria-hidden="true"></i> Print A4</button>
    </div>
</div>
<article class="parent-receipt receipt-a4-layout">
    <header class="parent-receipt-heading">
        <img src="<?= asset('images/school-logo.jpg') ?>" alt="Arise To Excel Academy logo">
        <div class="parent-receipt-school">
            <p>Official school receipt</p>
            <h2><?= h(SCHOOL_NAME) ?></h2>
            <span><?= h(SCHOOL_ADDRESS) ?></span>
            <span><?= h(SCHOOL_PHONE) ?></span>
        </div>
        <div class="parent-receipt-reference">
            <span>Receipt number</span>
            <strong><?= h($receipt['receipt_no']) ?></strong>
            <small><i class="fa-regular fa-calendar" aria-hidden="true"></i> <?= h($receipt['payment_date']) ?></small>
        </div>
    </header>

    <section class="parent-receipt-section">
        <h3>Payment details</h3>
        <dl class="parent-receipt-details">
            <div><dt>Student</dt><dd><?= h($receipt['full_name']) ?></dd></div>
            <div><dt>Admission number</dt><dd><?= h($receipt['registration_no']) ?></dd></div>
            <div><dt>Class</dt><dd><?= h($receipt['class_level']) ?></dd></div>
            <div><dt>Enrollment</dt><dd><?= h($receipt['enrollment_status']) ?></dd></div>
            <div><dt>Academic period</dt><dd><?= h($receipt['year'] . ' - ' . $receipt['term']) ?></dd></div>
            <div><dt>Payment method</dt><dd><?= h($receipt['payment_method'] ?: 'M-PESA') ?></dd></div>
            <div><dt>Payment reference</dt><dd><?= h($receipt['mpesa_code'] ?: 'Not provided') ?></dd></div>
            <div><dt>Payment status</dt><dd><?= h($receipt['payment_status'] ?: 'Recorded') ?></dd></div>
            <div><dt>Balance after payment</dt><dd><?= h(money((float) ($receipt['balance_after_payment'] ?? 0))) ?></dd></div>
            <?php if (($receipt['payment_status'] ?? 'Recorded') !== 'Recorded' && !empty($receipt['reversal_reason'])): ?><div><dt>Action note</dt><dd><?= h($receipt['reversal_reason']) ?></dd></div><?php endif; ?>
        </dl>
    </section>

    <div class="parent-receipt-total">
        <span>Amount received</span>
        <strong><?= h(money((float) $receipt['amount_paid'])) ?></strong>
        <small><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Payment recorded by the school</small>
    </div>
    <p class="parent-receipt-note">Please retain this receipt for your records. For payment enquiries, contact the school office.</p>
    <footer class="parent-receipt-footer">Arise To Excel Academy <span aria-hidden="true">&middot;</span> Computer-generated receipt</footer>
</article>
<script src="<?= asset_version('js/receipt-print.js') ?>"></script>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
