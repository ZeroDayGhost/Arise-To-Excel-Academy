<?php
$originalValues = fee_correction_payment_snapshot($payment);
?>
<div class="fee-correction-layout">
    <aside class="panel fee-correction-summary" aria-labelledby="original-payment-title">
        <div class="fee-correction-summary-heading">
            <span class="fee-correction-summary-icon" aria-hidden="true"><i class="fa-solid fa-receipt"></i></span>
            <div>
                <p id="original-payment-title">Original payment</p>
                <strong><?= h($payment['receipt_no']) ?></strong>
            </div>
        </div>
        <dl class="fee-correction-details">
            <div>
                <dt>Student</dt>
                <dd><?= h($payment['full_name']) ?><small><?= h($payment['registration_no'] . ' / ' . $payment['class_level']) ?></small></dd>
            </div>
            <div class="fee-correction-amount">
                <dt>Amount recorded</dt>
                <dd><?= h(money((float) $payment['amount_paid'])) ?></dd>
            </div>
            <div>
                <dt>Payment method</dt>
                <dd><?= h($payment['payment_method']) ?></dd>
            </div>
            <div>
                <dt>Payment date</dt>
                <dd><?= h($payment['payment_date']) ?></dd>
            </div>
            <div>
                <dt>Academic period</dt>
                <dd><?= h($payment['year'] . ' / ' . $payment['term']) ?></dd>
            </div>
            <div>
                <dt>Reference</dt>
                <dd class="fee-correction-reference"><?= h($payment['mpesa_code'] ?: 'Not provided') ?><?php if (!empty($payment['mpesa_reference_text'])): ?><small><?= h($payment['mpesa_reference_text']) ?></small><?php endif; ?></dd>
            </div>
        </dl>
        <p class="fee-correction-preservation"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> The receipt number is preserved and every change is recorded.</p>
    </aside>

    <section class="panel fee-correction-form-panel" aria-labelledby="correction-form-title">
        <div class="fee-correction-form-heading">
            <p class="eyebrow">Correction details</p>
            <h2 id="correction-form-title"><?= h($formTitle) ?></h2>
            <p class="text-muted">Enter the correct payment information and explain what needs to be fixed.</p>
        </div>
        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= h($formAction) ?>" class="row g-3 fee-correction-fields">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
            <input type="hidden" name="_original_values" value="<?= h(json_encode($originalValues, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>">
            <div class="col-md-7">
                <label class="form-label" for="student_id">Correct student</label>
                <select class="form-select" name="student_id" id="student_id" required>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" <?= (int) $values['student_id'] === (int) $student['id'] ? 'selected' : '' ?>>
                            <?= h($student['registration_no'] . ' / ' . $student['full_name'] . ' / ' . $student['class_level']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="amount_paid">Correct amount (KES)</label>
                <input class="form-control" type="number" min="0.01" step="0.01" name="amount_paid" id="amount_paid" value="<?= h((string) $values['amount_paid']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="payment_method">Payment method</label>
                <select class="form-select" name="payment_method" id="payment_method" required>
                    <?php foreach ($paymentMethods as $method): ?>
                        <option value="<?= h($method) ?>" <?= $values['payment_method'] === $method ? 'selected' : '' ?>><?= h($method) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="mpesa_code">Payment reference / M-PESA code</label>
                <input class="form-control" type="text" maxlength="30" name="mpesa_code" id="mpesa_code" value="<?= h($values['mpesa_code']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="payment_date">Payment date</label>
                <input class="form-control" type="date" name="payment_date" id="payment_date" value="<?= h($values['payment_date']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="year">Academic year</label>
                <input class="form-control" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4" name="year" id="year" value="<?= h($values['year']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="term">Term</label>
                <select class="form-select" name="term" id="term" required>
                    <?php foreach (['Term 1', 'Term 2', 'Term 3'] as $term): ?>
                        <option value="<?= h($term) ?>" <?= $values['term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="mpesa_reference_text">Additional reference details</label>
                <textarea class="form-control" name="mpesa_reference_text" id="mpesa_reference_text" rows="2" maxlength="2000"><?= h($values['mpesa_reference_text']) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="reason"><?= $isRequest ? 'Reason for the mistake' : 'Reason for direct correction' ?></label>
                <textarea class="form-control" name="reason" id="reason" rows="3" maxlength="500" required><?= h($reason) ?></textarea>
            </div>
            <div class="col-12 fee-correction-actions">
                <button class="btn btn-primary" type="submit"><i class="fa-solid <?= $isRequest ? 'fa-paper-plane' : 'fa-check' ?>" aria-hidden="true"></i><?= h($submitLabel) ?></button>
                <a class="btn btn-outline-secondary" href="<?= url('admin/fee_payment_audit.php?id=' . (int) $payment['id']) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Cancel</a>
            </div>
        </form>
    </section>
</div>
