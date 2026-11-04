<?php
$pageTitle = 'Transport Payments';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'transport.access')) {
    flash('error', 'You do not have permission to access Transport.');
    redirect('admin/dashboard.php');
}

// Current admin quick permissions & role
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$currentAdminRole = $adminId ? get_user_role_template_name($pdo, $adminId) : '';
$currentAdminClass = $adminId ? current_admin_class_level($pdo) : null;
$canViewTransport = current_admin_has_permission($pdo, 'transport.view');
$canAddTransport = current_admin_has_permission($pdo, 'transport.add');
$canRecordTransportPayment = current_admin_has_permission($pdo, 'transport.save');
$canEditTransport = current_admin_has_permission($pdo, 'transport.edit');
$canDeleteTransport = current_admin_has_permission($pdo, 'transport.delete');

$classLevels = class_level_options();
$genderOptions = gender_options();
$terms = term_options();
$errors = [];
$currentContext = current_academic_context($pdo);
$editingAccountId = (int) ($_GET['edit'] ?? ($_POST['transport_account_id'] ?? 0));
$payingAccountId = (int) ($_GET['pay'] ?? ($_POST['payment_account_id'] ?? 0));
$form = [
    'transport_account_id' => '',
    'mode' => 'existing',
    'student_id' => '',
    'student_name' => '',
    'gender' => '',
    'parent_name' => '',
    'parent_phone' => '',
    'pickup_location' => '',
    'school_name' => '',
    'amount' => '',
    'academic_year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
];

function transport_account_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        "SELECT
            transport_accounts.*,
            transport_students.student_id,
            transport_students.student_name,
            transport_students.gender,
            transport_students.parent_name,
            transport_students.parent_phone,
            transport_students.pickup_location,
            transport_students.is_outside,
            students.registration_no,
            students.class_level,
            students.enrollment_status
         FROM transport_accounts
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id
         WHERE transport_accounts.id = :id"
    );
    $statement->execute(['id' => $id]);
    $account = $statement->fetch();

    return $account ?: null;
}

function transport_paid_amount(PDO $pdo, int $accountId): float
{
    $statement = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM transport_payments WHERE transport_account_id = :id");
    $statement->execute(['id' => $accountId]);
    return (float) $statement->fetchColumn();
}

if (!function_exists('transport_account_duplicate_exists')) {
    function transport_account_duplicate_exists(PDO $pdo, int $transportStudentId, string $academicYear, string $term, int $ignoreAccountId = 0): bool
    {
        $sql = "SELECT COUNT(*)
                FROM transport_accounts
                WHERE transport_student_id = :transport_student_id
                  AND academic_year = :academic_year
                  AND term = :term";
        $params = [
            'transport_student_id' => $transportStudentId,
            'academic_year' => $academicYear,
            'term' => $term,
        ];

        if ($ignoreAccountId > 0) {
            $sql .= " AND id <> :id";
            $params['id'] = $ignoreAccountId;
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }
}

function is_ajax_request(): bool
{
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1');
}

function get_scalar_get_param(string $name): string
{
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return '';
    }
    return trim($_GET[$name]);
}

function fetch_transport_accounts(PDO $pdo, array $filters = []): array
{
    $terms = term_options();
    $search = trim($filters['search'] ?? '');
    $location = trim($filters['location'] ?? '');
    $filterYear = trim($filters['year'] ?? '');
    $filterTerm = trim($filters['term'] ?? '');

    // Default to current academic context when no explicit filters provided
    if ($filterYear === '' || $filterTerm === '') {
        $ctx = current_academic_context($pdo);
        if ($filterYear === '') {
            $filterYear = $ctx['academic_year'];
        }
        if ($filterTerm === '') {
            $filterTerm = $ctx['term'];
        }
    }

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(transport_students.student_name LIKE :search_student OR transport_students.parent_name LIKE :search_parent OR transport_students.parent_phone LIKE :search_phone OR transport_students.pickup_location LIKE :search_route)';
        $searchLike = '%' . $search . '%';
        $params['search_student'] = $searchLike;
        $params['search_parent'] = $searchLike;
        $params['search_phone'] = $searchLike;
        $params['search_route'] = $searchLike;
    }
    if ($location !== '') {
        $where[] = 'transport_students.pickup_location LIKE :location';
        $params['location'] = '%' . $location . '%';
    }
    if ($filterYear !== '' && preg_match('/^\d{4}$/', $filterYear)) {
        $where[] = 'transport_accounts.academic_year = :year';
        $params['year'] = $filterYear;
    }
    if ($filterTerm !== '' && in_array($filterTerm, $terms, true)) {
        $where[] = 'transport_accounts.term = :term';
        $params['term'] = $filterTerm;
    }

    $sql = "SELECT
                transport_accounts.*,
                transport_students.student_id,
                transport_students.student_name,
                transport_students.gender,
                transport_students.parent_name,
                transport_students.parent_phone,
                transport_students.pickup_location,
                transport_students.is_outside,
                students.registration_no,
                students.class_level,
                COALESCE(SUM(transport_payments.amount_paid), 0) AS paid,
                transport_accounts.amount_due - COALESCE(SUM(transport_payments.amount_paid), 0) AS balance
            FROM transport_accounts
            JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
            LEFT JOIN students ON students.id = transport_students.student_id
            LEFT JOIN transport_payments ON transport_payments.transport_account_id = transport_accounts.id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY transport_accounts.id ORDER BY transport_accounts.academic_year DESC, FIELD(transport_accounts.term, "Term 1", "Term 2", "Term 3"), transport_students.student_name ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function render_transport_accounts_rows(array $accounts, bool $canPay = true, bool $canDelete = true, bool $canEdit = true): string
{
    ob_start();
    if (!$accounts) {
        echo '<tr><td colspan="8">No transport accounts found. A transport account is created automatically once a transport student is saved.</td></tr>';
    } else {
        foreach ($accounts as $account): ?>
            <tr
                data-account-id="<?= (int) $account['id'] ?>"
                data-student-name="<?= h($account['student_name']) ?>"
                data-route="<?= h($account['pickup_location']) ?>"
                data-year="<?= h($account['academic_year']) ?>"
                data-term="<?= h($account['term']) ?>"
                data-due="<?= h((string) $account['amount_due']) ?>"
                data-paid="<?= h((string) $account['paid']) ?>"
                data-balance="<?= h((string) $account['balance']) ?>"
            >
                <td>
                    <?= h($account['student_name']) ?><br>
                    <small><?= (int) $account['is_outside'] === 1 ? 'Outside student' : h($account['registration_no'] . ' - ' . $account['class_level']) ?></small>
                </td>
                <td><?= h($account['pickup_location']) ?></td>
                <td><?= h($account['academic_year']) ?></td>
                <td><?= h($account['term']) ?></td>
                <td><?= money((float) $account['amount_due']) ?></td>
                <td><?= money((float) $account['paid']) ?></td>
                <td><?= money((float) $account['balance']) ?></td>
                <td>
                    <div class="action-row">
                        <?php if ($canEdit): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(url('admin/transport.php?edit=' . (int) $account['id'])) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if ($canPay && (float) $account['balance'] > 0.005): ?>
                            <button type="button" class="btn btn-sm btn-primary" data-action="pay" data-account-id="<?= (int) $account['id'] ?>">Pay</button>
                        <?php elseif ((float) $account['balance'] > 0.005): ?>
                            <button type="button" class="btn btn-sm btn-primary" disabled>Pay</button>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Delete this transport account and its payments?');">
                            <input type="hidden" name="transport_account_id" value="<?= (int) $account['id'] ?>">
                            <?php if ($canDelete): ?>
                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete_account" type="submit">Delete</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-danger" disabled>Delete</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    }
    return ob_get_clean();
}

function render_transport_payment_history_rows(array $payments): string
{
    ob_start();
    if (!$payments) {
        echo '<tr><td colspan="6">No transport payments recorded.</td></tr>';
    } else {
        foreach ($payments as $payment): ?>
            <tr>
                <td><?= h($payment['student_name']) ?></td>
                <td><?= h($payment['term'] . ' ' . $payment['academic_year']) ?></td>
                <td><?= money((float) $payment['amount_paid']) ?></td>
                <td><?= h($payment['payment_date']) ?></td>
                <td><?= h($payment['reference_no']) ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/transport_receipt.php?id=' . $payment['id']) ?>">Receipt</a></td>
            </tr>
        <?php endforeach;
    }
    return ob_get_clean();
}

function transport_payment_history(PDO $pdo, int $accountId): array
{
    $statement = $pdo->prepare(
        "SELECT transport_payments.*, transport_accounts.term, transport_accounts.academic_year,
                transport_students.student_name
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         WHERE transport_payments.transport_account_id = :id
         ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC"
    );
    $statement->execute(['id' => $accountId]);
    return $statement->fetchAll();
}

function transport_recent_payment_history(PDO $pdo, string $academicYear, string $term, ?string $classLevel = null): array
{
    $params = [
        'year' => $academicYear,
        'term' => $term,
    ];
    $sql = "SELECT transport_payments.*, transport_students.student_name, transport_accounts.term, transport_accounts.academic_year, students.class_level
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id
         WHERE transport_accounts.academic_year = :year AND transport_accounts.term = :term";

    if ($classLevel) {
        $sql .= ' AND students.class_level = :class_level';
        $params['class_level'] = $classLevel;
    }

    $sql .= ' ORDER BY transport_payments.payment_date DESC, transport_payments.id DESC LIMIT 20';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function transport_latest_payment(PDO $pdo, int $accountId): ?array
{
    $statement = $pdo->prepare(
        "SELECT id, amount_paid, payment_date, reference_no, created_at
         FROM transport_payments
         WHERE transport_account_id = :id
         ORDER BY payment_date DESC, id DESC
         LIMIT 1"
    );
    $statement->execute(['id' => $accountId]);
    return $statement->fetch() ?: null;
}

function transport_payment_by_id(PDO $pdo, int $paymentId): ?array
{
    $statement = $pdo->prepare(
        "SELECT transport_payments.*, transport_accounts.amount_due, transport_accounts.academic_year, transport_accounts.term,
                transport_students.student_id, transport_students.student_name, transport_students.pickup_location,
                transport_students.is_outside, transport_students.parent_name, transport_students.parent_phone,
                students.registration_no, students.class_level
         FROM transport_payments
         JOIN transport_accounts ON transport_accounts.id = transport_payments.transport_account_id
         JOIN transport_students ON transport_students.id = transport_accounts.transport_student_id
         LEFT JOIN students ON students.id = transport_students.student_id
         WHERE transport_payments.id = :id"
    );
    $statement->execute(['id' => $paymentId]);
    return $statement->fetch() ?: null;
}

function render_transport_receipt_html(PDO $pdo, array $payment): string
{
    $receipt_no = 'TR-' . str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT);
    $receipt_date = date('M d, Y', strtotime($payment['payment_date']));
    $receipt_status = 'PAID';
    $receipt_title = 'Transport Payment Receipt';
    $payer_name = $payment['student_name'];
    $payer_meta = [
        'Reg Number' => $payment['registration_no'] ?: 'N/A',
        'Class Level' => $payment['class_level'] ?: 'N/A',
        'Route' => $payment['pickup_location'],
        'Term' => $payment['term'] . ' - ' . $payment['academic_year'],
        'Reference' => $payment['reference_no'] ?: 'N/A',
    ];
    $amountPaid = (float) $payment['amount_paid'];
    $balance = max((float) $payment['amount_due'] - ((float) transport_paid_amount($pdo, (int) $payment['transport_account_id'])), 0);
    $items = [
        ['description' => 'Transport Fee Payment', 'quantity' => 1, 'unit_price' => $amountPaid, 'line_total' => $amountPaid],
    ];
    $totals = [
        'subtotal' => $amountPaid,
        'discount' => 0,
        'grand_total' => $amountPaid,
        'amount_paid' => $amountPaid,
        'balance' => $balance,
    ];
    $payment_method = 'M-PESA';
    $mpesa_code = $payment['reference_no'] ?? '';
    $served_by = $_SESSION['admin_name'] ?? '';
    $receipt_type = 'TRANSPORT FEES';
    $back_href = '';
    $pdf_href = '';
    ob_start();
    include __DIR__ . '/../includes/receipt_template.php';
    return ob_get_clean();
}

function find_or_create_existing_transport_student(PDO $pdo, int $studentId, string $pickupLocation): int
{
    $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $studentStatement = $pdo->prepare("SELECT * FROM students WHERE id = :id" . $lockClause);
    $studentStatement->execute(['id' => $studentId]);
    $student = $studentStatement->fetch();

    if (!$student) {
        throw new RuntimeException('Selected student was not found.');
    }
    if (($student['enrollment_status'] ?? 'Active') !== 'Active') {
        throw new InvalidArgumentException('Transport accounts can only be created or updated for active students.');
    }

    $statement = $pdo->prepare("SELECT id FROM transport_students WHERE student_id = :student_id LIMIT 1");
    $statement->execute(['student_id' => $studentId]);
    $transportStudentId = (int) $statement->fetchColumn();

    if ($transportStudentId > 0) {
        $update = $pdo->prepare(
            "UPDATE transport_students
             SET student_name = :student_name,
                 gender = :gender,
                 parent_name = :parent_name,
                 parent_phone = :parent_phone,
                 pickup_location = :pickup_location,
                 is_outside = 0
             WHERE id = :id"
        );
        $update->execute([
            'student_name' => $student['full_name'],
            'gender' => $student['gender'],
            'parent_name' => $student['parent_name'],
            'parent_phone' => $student['guardian_phone'],
            'pickup_location' => $pickupLocation,
            'id' => $transportStudentId,
        ]);

        return $transportStudentId;
    }

    $insert = $pdo->prepare(
        "INSERT INTO transport_students (student_id, student_name, gender, parent_name, parent_phone, pickup_location, is_outside)
         VALUES (:student_id, :student_name, :gender, :parent_name, :parent_phone, :pickup_location, 0)"
    );
    $insert->execute([
        'student_id' => $studentId,
        'student_name' => $student['full_name'],
        'gender' => $student['gender'],
        'parent_name' => $student['parent_name'],
        'parent_phone' => $student['guardian_phone'],
        'pickup_location' => $pickupLocation,
    ]);

    return (int) $pdo->lastInsertId();
}

function find_or_create_outside_transport_student(PDO $pdo, array $data): int
{
    $statement = $pdo->prepare(
        "SELECT id
         FROM transport_students
         WHERE student_id IS NULL
           AND student_name = :student_name
           AND school_name = :school_name
           AND parent_phone = :parent_phone
           AND pickup_location = :pickup_location
         LIMIT 1"
    );
    $statement->execute([
        'student_name' => $data['student_name'],
        'school_name' => $data['school_name'],
        'parent_phone' => $data['parent_phone'],
        'pickup_location' => $data['pickup_location'],
    ]);
    $transportStudentId = (int) $statement->fetchColumn();

    if ($transportStudentId > 0) {
        $update = $pdo->prepare(
            "UPDATE transport_students
             SET gender = :gender,
                 school_name = :school_name,
                 parent_name = :parent_name,
                 parent_phone = :parent_phone,
                 is_outside = 1
             WHERE id = :id"
        );
        $update->execute([
            'gender' => $data['gender'],
            'school_name' => $data['school_name'],
            'parent_name' => $data['parent_name'],
            'parent_phone' => $data['parent_phone'],
            'id' => $transportStudentId,
        ]);

        return $transportStudentId;
    }

    $insert = $pdo->prepare(
        "INSERT INTO transport_students (student_id, student_name, school_name, gender, parent_name, parent_phone, pickup_location, is_outside)
         VALUES (NULL, :student_name, :school_name, :gender, :parent_name, :parent_phone, :pickup_location, 1)"
    );
    $insert->execute([
        'student_name' => $data['student_name'],
        'school_name' => $data['school_name'],
        'gender' => $data['gender'],
        'parent_name' => $data['parent_name'],
        'parent_phone' => $data['parent_phone'],
        'pickup_location' => $data['pickup_location'],
    ]);

    return (int) $pdo->lastInsertId();
}

if ($editingAccountId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    rbac_require_permission($pdo, 'transport.update', 'You do not have permission to edit transport accounts.');
    $account = transport_account_by_id($pdo, $editingAccountId);
    if ($account) {
        $form = [
            'transport_account_id' => $account['id'],
            'mode' => (int) $account['is_outside'] === 1 ? 'outside' : 'existing',
            'student_id' => $account['student_id'],
            'student_name' => $account['student_name'],
            'school_name' => $account['school_name'] ?? '',
            'gender' => $account['gender'],
            'parent_name' => $account['parent_name'],
            'parent_phone' => $account['parent_phone'],
            'pickup_location' => $account['pickup_location'],
            'amount' => $account['amount_due'],
            'academic_year' => $account['academic_year'],
            'term' => $account['term'],
        ];
    } else {
        flash('error', 'Transport account was not found.');
        redirect('admin/transport.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_account';
    $ajaxRequest = is_ajax_request();

    if ($action === 'delete_account') {
        // API-level permission check
        rbac_require_permission($pdo, 'transport.delete', 'You do not have permission to delete transport accounts.');
        
        $accountId = (int) ($_POST['transport_account_id'] ?? 0);
        $account = $accountId > 0 ? transport_account_by_id($pdo, $accountId) : null;

        if (!$canDeleteTransport) {
            flash('error', 'You do not have permission to delete transport accounts.');
            redirect('admin/transport.php');
        }

        if ($currentAdminRole === 'Teacher' && $currentAdminClass && $account && ($account['class_level'] ?? '') !== $currentAdminClass) {
            flash('error', 'You do not have permission to delete transport accounts outside your class.');
            redirect('admin/transport.php');
        }

        if (!$account) {
            $errors[] = 'Choose a transport account to delete.';
        } else {
            $statement = $pdo->prepare("DELETE FROM transport_accounts WHERE id = :id");
            $statement->execute(['id' => $accountId]);
            flash('success', 'Transport account deleted successfully.');
            redirect('admin/transport.php');
        }
    } elseif ($action === 'save_payment') {
        // API-level permission check
        rbac_require_permission($pdo, 'transport.add', 'You do not have permission to record transport payments.');

        $accountId = (int) ($_POST['payment_account_id'] ?? 0);
        $amountPaid = trim($_POST['amount_paid'] ?? '');
        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $referenceNo = normalize_payment_code(trim($_POST['reference_no'] ?? ''));
        $account = $accountId > 0 ? transport_account_by_id($pdo, $accountId) : null;

        if (!$account) {
            $errors[] = 'Transport account not found.';
        } elseif (($account['enrollment_status'] ?? 'Active') !== 'Active' && (int) ($account['is_outside'] ?? 0) !== 1) {
            $errors[] = 'Routine transport payments are only available for active students.';
        }
        if (!valid_money_value($amountPaid) || (float) $amountPaid <= 0) {
            $errors[] = 'Invalid payment amount. Enter a positive amount.';
        }
        if (!valid_date_value($paymentDate)) {
            $errors[] = 'Payment date must be valid.';
        }

        if (!$errors) {

            if ($currentAdminRole === 'Teacher' && $currentAdminClass && $account && ($account['class_level'] ?? '') !== $currentAdminClass) {
                $errors[] = 'You do not have permission to record payments for students outside your class.';
            }
            if ($referenceNo !== '' && payment_code_duplicate_exists($pdo, $referenceNo, 'transport_reference')) {
                $errors[] = 'Duplicate reference number. Please use a new receipt/reference code.';
            }
            $balance = max((float) $account['amount_due'] - transport_paid_amount($pdo, $accountId), 0);
            if ((float) $amountPaid > $balance + 0.005) {
                $errors[] = 'Payment cannot exceed the remaining transport balance of ' . money($balance) . '.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ((int) ($account['is_outside'] ?? 0) !== 1 && (int) ($account['student_id'] ?? 0) > 0) {
                    $studentLock = $pdo->prepare('SELECT enrollment_status FROM students WHERE id = :id FOR UPDATE');
                    $studentLock->execute(['id' => (int) $account['student_id']]);
                    if ($studentLock->fetchColumn() !== 'Active') {
                        throw new InvalidArgumentException('Routine transport payments are only available for active students.');
                    }
                }
                $statement = $pdo->prepare(
                    "INSERT INTO transport_payments (transport_account_id, amount_paid, payment_date, reference_no)
                     VALUES (:transport_account_id, :amount_paid, :payment_date, :reference_no)"
                );
                $statement->execute([
                    'transport_account_id' => $accountId,
                    'amount_paid' => (float) $amountPaid,
                    'payment_date' => $paymentDate,
                    'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                ]);
                $paymentId = (int) $pdo->lastInsertId();
                $pdo->commit();
                if ($ajaxRequest) {
                    $payment = transport_payment_by_id($pdo, $paymentId);
                    $receiptHtml = $payment ? render_transport_receipt_html($pdo, $payment) : null;
                    $fetchedAccounts = fetch_transport_accounts($pdo);
                    if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
                        $fetchedAccounts = array_values(array_filter($fetchedAccounts, function($a) use ($currentAdminClass) { return ($a['class_level'] ?? '') === $currentAdminClass; }));
                    }
                    $history = transport_recent_payment_history(
                        $pdo,
                        $currentContext['academic_year'],
                        $currentContext['term'],
                        $currentAdminRole === 'Teacher' && $currentAdminClass ? $currentAdminClass : null
                    );
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Transport payment recorded successfully.',
                        'html' => render_transport_accounts_rows($fetchedAccounts, $canAddTransport, $canDeleteTransport, $canEditTransport),
                        'receipt_html' => $receiptHtml,
                        'history_html' => render_transport_payment_history_rows($history),
                        'history' => $history,
                    ]);
                    exit;
                }
                flash('success', 'Transport payment recorded successfully.');
                redirect('admin/transport.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Transport payment insertion failed with code ' . (string) $exception->getCode());
                $errors[] = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to record the transport payment. Please try again or contact the system administrator.';
            }
        }
    } elseif (in_array($action, ['save_account', 'update_account'], true)) {
        $isUpdate = $action === 'update_account';
        rbac_require_permission(
            $pdo,
            $isUpdate ? 'transport.update' : 'transport.save',
            $isUpdate ? 'You do not have permission to update transport accounts.' : 'You do not have permission to add transport accounts.'
        );

        $accountId = (int) ($_POST['transport_account_id'] ?? 0);
        $existingAccount = $isUpdate && $accountId > 0 ? transport_account_by_id($pdo, $accountId) : null;
        $form = [
            'transport_account_id' => $accountId,
            'mode' => ($_POST['mode'] ?? 'existing') === 'outside' ? 'outside' : 'existing',
            'student_id' => (int) ($_POST['student_id'] ?? 0),
            'student_name' => trim($_POST['student_name'] ?? ''),
            'school_name' => trim($_POST['school_name'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'parent_name' => trim($_POST['parent_name'] ?? ''),
            'parent_phone' => trim($_POST['parent_phone'] ?? ''),
            'pickup_location' => trim($_POST['pickup_location'] ?? ''),
            'amount' => trim($_POST['amount'] ?? ''),
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'term' => trim($_POST['term'] ?? ''),
        ];

        if ($isUpdate && $accountId <= 0) {
            $errors[] = 'Choose a transport account to update.';
        } elseif ($isUpdate && !$existingAccount) {
            $errors[] = 'Transport account was not found.';
        }
        if ($isUpdate && $currentAdminRole === 'Teacher' && $currentAdminClass && $existingAccount
            && ($existingAccount['class_level'] ?? '') !== $currentAdminClass) {
            $errors[] = 'You can only edit transport accounts for students in your class.';
        }

        if ($form['mode'] === 'existing' && $form['student_id'] <= 0) {
            $errors[] = 'Choose an existing student.';
        }
        if ($form['mode'] === 'outside') {
            if ($form['student_name'] === '') {
                $errors[] = 'Student name is required for outside students.';
            }
            if ($form['school_name'] === '') {
                $errors[] = 'School name is required for outside students.';
            }
            if (!in_array($form['gender'], $genderOptions, true)) {
                $errors[] = 'Choose a valid gender.';
            }
        }
        if ($form['pickup_location'] === '') {
            $errors[] = 'Pickup location is required.';
        } else {
            $feeAmount = transport_fee_amount_for_location($pdo, $form['pickup_location'], $form['academic_year']);
            if ($feeAmount === null) {
                $errors[] = 'Choose a valid active transport fee location for the selected year.';
            } else {
                $form['amount'] = (string) $feeAmount;
            }
        }
        if (!preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year must be four digits.';
        }
        if (!in_array($form['term'], $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }
        if (!$errors && $isUpdate && (float) $form['amount'] < transport_paid_amount($pdo, $accountId)) {
            $errors[] = 'Transport amount cannot be less than payments already recorded.';
        }

        if (!$errors) {
            // If teacher, ensure student belongs to their class when using existing student
            if ($form['mode'] === 'existing' && $currentAdminRole === 'Teacher' && $currentAdminClass) {
                $sstmt = $pdo->prepare('SELECT class_level FROM students WHERE id = :id LIMIT 1');
                $sstmt->execute(['id' => (int)$form['student_id']]);
                $srow = $sstmt->fetch();
                if (!$srow || ($srow['class_level'] ?? '') !== $currentAdminClass) {
                    $errors[] = 'You can only create or edit transport accounts for students in your class.';
                }
            }
        }
        if (!$errors) {
            $pdo->beginTransaction();
            try {
                if ($form['mode'] === 'existing') {
                    $transportStudentId = find_or_create_existing_transport_student($pdo, (int) $form['student_id'], $form['pickup_location']);
                } else {
                    $transportStudentId = find_or_create_outside_transport_student($pdo, $form);
                }

                if (transport_account_duplicate_exists($pdo, $transportStudentId, $form['academic_year'], $form['term'], $isUpdate ? $accountId : 0)) {
                    throw new RuntimeException('This transport student already has an account for the selected year and term.');
                }

                $params = [
                    'transport_student_id' => $transportStudentId,
                    'academic_year' => $form['academic_year'],
                    'term' => $form['term'],
                    'amount_due' => (float) $form['amount'],
                ];

                if ($isUpdate) {
                    $params['id'] = $accountId;
                    $statement = $pdo->prepare(
                        "UPDATE transport_accounts
                         SET transport_student_id = :transport_student_id,
                             academic_year = :academic_year,
                             term = :term,
                             amount_due = :amount_due
                         WHERE id = :id"
                    );
                    $statement->execute($params);
                    $message = 'Transport account updated successfully.';
                } else {
                    $statement = $pdo->prepare(
                        "INSERT INTO transport_accounts (transport_student_id, academic_year, term, amount_due)
                         VALUES (:transport_student_id, :academic_year, :term, :amount_due)"
                    );
                    $statement->execute($params);
                    $message = 'Transport account added successfully.';
                }

                $pdo->commit();
                if ($ajaxRequest) {
                    $fetchedAccounts = fetch_transport_accounts($pdo);
                    if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
                        $fetchedAccounts = array_values(array_filter($fetchedAccounts, function($a) use ($currentAdminClass) { return ($a['class_level'] ?? '') === $currentAdminClass; }));
                    }
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'html' => render_transport_accounts_rows($fetchedAccounts, $canAddTransport, $canDeleteTransport, $canEditTransport),
                    ]);
                    exit;
                }
                flash('success', $message);
                redirect('admin/transport.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Transport account save failed with code ' . (string) $exception->getCode());
                $errors[] = 'Unable to save the transport account. Please try again or contact the system administrator.';
            }
        }
    } else {
        $errors[] = 'Invalid transport action.';
    }

    if ($ajaxRequest && $errors) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}

$students = $pdo->query(
    "SELECT id, registration_no, full_name, gender, parent_name, guardian_phone, class_level
     FROM students WHERE enrollment_status = 'Active'
     ORDER BY full_name ASC"
)->fetchAll();

$transportLocations = $pdo->query(
    "SELECT location_name, fee_amount, status
     FROM transport_fee_structures
     ORDER BY location_name ASC"
)->fetchAll();

$search = get_scalar_get_param('search');
$location = get_scalar_get_param('location');
$filterYear = get_scalar_get_param('year');
if ($filterYear !== '' && !preg_match('/^\d{4}$/', $filterYear)) {
    $filterYear = '';
}
$filterTerm = get_scalar_get_param('term');
if ($filterTerm === '' || !in_array($filterTerm, $terms, true)) {
    $filterTerm = '';
}

// If the active academic context was just switched, ignore explicit year/term filters so pages show the new context
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!empty($_SESSION['academic_context_switched'])) {
    $filterYear = '';
    $filterTerm = '';
    unset($_SESSION['academic_context_switched']);
    $currentContext = current_academic_context($pdo);
}

$accounts = fetch_transport_accounts($pdo, [
    'search' => $search,
    'location' => $location,
    'year' => $filterYear,
    'term' => $filterTerm,
]);
$accounts = is_array($accounts) ? $accounts : [];
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $accounts = array_values(array_filter($accounts, function($a) use ($currentAdminClass) { return ($a['class_level'] ?? '') === $currentAdminClass; }));
}
$paymentTarget = $payingAccountId > 0 ? transport_account_by_id($pdo, $payingAccountId) : null;

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajaxType = $_GET['ajax'];
    if ($ajaxType === 'accounts') {
        echo json_encode(['success' => true, 'html' => render_transport_accounts_rows($accounts, $canAddTransport, $canDeleteTransport, $canEditTransport)]);
        exit;
    }
    if ($ajaxType === 'account_history') {
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $history = $accountId > 0 ? transport_payment_history($pdo, $accountId) : [];
        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }
    if ($ajaxType === 'payment_receipt') {
        $paymentId = (int) ($_GET['payment_id'] ?? 0);
        $payment = $paymentId > 0 ? transport_payment_by_id($pdo, $paymentId) : null;
        $receiptHtml = $payment ? render_transport_receipt_html($pdo, $payment) : null;
        echo json_encode(['success' => (bool) $receiptHtml, 'html' => $receiptHtml]);
        exit;
    }
    if ($ajaxType === 'latest_payment') {
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $latest = $accountId > 0 ? transport_latest_payment($pdo, $accountId) : null;
        $receiptHtml = null;
        if ($latest) {
            $latestFull = transport_payment_by_id($pdo, (int) $latest['id']);
            $receiptHtml = $latestFull ? render_transport_receipt_html($pdo, $latestFull) : null;
        }
        echo json_encode(['success' => true, 'payment' => $latest, 'html' => $receiptHtml]);
        exit;
    }
}

$paymentHistory = transport_recent_payment_history(
    $pdo,
    $currentContext['academic_year'],
    $currentContext['term'],
    $currentAdminRole === 'Teacher' && $currentAdminClass ? $currentAdminClass : null
);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Routes and Riders</p>
        <h1>Transport Payments</h1>
        <p class="mb-0 text-muted"><?= h($currentContext['academic_year'] . ' - ' . $currentContext['term']) ?></p>
    </div>
    <div class="action-row d-flex flex-wrap gap-2">
        <?php if ($canRecordTransportPayment): ?><a class="btn btn-primary" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right me-1" aria-hidden="true"></i>Combined Payment</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= h(url('admin/reports.php')) ?>">Reports</a>
    </div>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<div id="transportMessages"></div>
<div id="transportErrors">
    <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <?php if ($canAddTransport || $canEditTransport): ?>
                <h2><?= (int) $form['transport_account_id'] > 0 ? 'Edit Transport Student' : 'Add Transport Student' ?></h2>
                <form id="transportForm" class="row g-3" method="post">
                    <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="transport_account_id" value="<?= (int) $form['transport_account_id'] ?>">
                    <input type="hidden" name="academic_year" value="<?= h((string) $form['academic_year']) ?>">
                    <input type="hidden" name="term" value="<?= h((string) $form['term']) ?>">
                    <div class="col-12">
                        <label class="form-label" for="mode">Student Type</label>
                        <select class="form-select" id="mode" name="mode">
                            <option value="existing" <?= $form['mode'] === 'existing' ? 'selected' : '' ?>>Existing school student</option>
                            <option value="outside" <?= $form['mode'] === 'outside' ? 'selected' : '' ?>>Outside student</option>
                        </select>
                    </div>
                    <div class="col-12 existing-fields" style="display: <?= $form['mode'] === 'outside' ? 'none' : '' ?>;">
                        <label class="form-label" for="student_id">Select Existing Student</label>
                        <select class="form-select" id="student_id" name="student_id">
                            <option value="">Choose student...</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student['id'] ?>" <?= (int) $form['student_id'] === (int) $student['id'] ? 'selected' : '' ?>>
                                    <?= h($student['registration_no'] . ' - ' . $student['full_name'] . ' - ' . $student['class_level']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 outside-fields" style="display: <?= $form['mode'] === 'existing' ? 'none' : '' ?>;">
                        <p class="text-muted mb-2">Enter the outside student’s details below so we can register them correctly.</p>
                        <label class="form-label" for="student_name">Outside Student Name</label>
                        <input class="form-control" id="student_name" name="student_name" value="<?= h((string) $form['student_name']) ?>" <?= $form['mode'] === 'existing' ? 'disabled' : '' ?> data-required-outside="true">
                    </div>
                    <div class="col-12 outside-fields" style="display: <?= $form['mode'] === 'existing' ? 'none' : '' ?>;">
                        <label class="form-label" for="school_name">School Name</label>
                        <input class="form-control" id="school_name" name="school_name" value="<?= h((string) $form['school_name']) ?>" <?= $form['mode'] === 'existing' ? 'disabled' : '' ?> data-required-outside="true">
                    </div>
                    <div class="col-md-6 outside-fields" style="display: <?= $form['mode'] === 'existing' ? 'none' : '' ?>;">
                        <label class="form-label" for="gender">Gender</label>
                        <select class="form-select" id="gender" name="gender" <?= $form['mode'] === 'existing' ? 'disabled' : '' ?> data-required-outside="true">
                            <option value="">Choose gender...</option>
                            <?php foreach ($genderOptions as $gender): ?>
                                <option value="<?= h($gender) ?>" <?= $form['gender'] === $gender ? 'selected' : '' ?>><?= h($gender) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="pickup_location">Pickup/Drop Location</label>
                        <select class="form-select" id="pickup_location" name="pickup_location" required>
                            <option value="">Choose location...</option>
                            <?php foreach ($transportLocations as $transportLocation): ?>
                                <option value="<?= h($transportLocation['location_name']) ?>" data-fee="<?= h((string) $transportLocation['fee_amount']) ?>" <?= $form['pickup_location'] === $transportLocation['location_name'] ? 'selected' : '' ?>>
                                    <?= h($transportLocation['location_name'] . ($transportLocation['status'] !== 'Active' ? ' (' . $transportLocation['status'] . ')' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($form['pickup_location'] !== '' && !in_array($form['pickup_location'], array_column($transportLocations, 'location_name'), true)): ?>
                                <option value="<?= h($form['pickup_location']) ?>" selected><?= h($form['pickup_location'] . ' (Unknown)') ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="amount">Transport Fee</label>
                        <input class="form-control" type="number" min="0.00" step="0.01" id="amount" name="amount" value="<?= h((string) $form['amount']) ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="academic_year">Year</label>
                        <input class="form-control" id="academic_year" value="<?= h((string) $form['academic_year']) ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="term">Term</label>
                        <input class="form-control" id="term" value="<?= h((string) $form['term']) ?>" readonly>
                    </div>
                    <div class="col-12">
                        <div class="action-row">
                            <?php if ((int) $form['transport_account_id'] > 0): ?>
                                <?php if ($canEditTransport): ?><button class="btn btn-primary" name="action" value="update_account" type="submit">Update</button><?php endif; ?>
                            <?php elseif ($canAddTransport): ?>
                                <button class="btn btn-primary" name="action" value="save_account" type="submit">Save</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-secondary">
                    You do not have permission to add or edit transport accounts.
                </div>
            <?php endif; ?>
        </section>
        <script>
        (function() {
            function initTransportForm() {
                const modeSelect = document.getElementById('mode');
                const studentSelect = document.getElementById('student_id');
                const existingFields = document.querySelectorAll('.existing-fields');
                const outsideFields = document.querySelectorAll('.outside-fields');
                const studentName = document.getElementById('student_name');
                const genderSelect = document.getElementById('gender');
                const locationSelect = document.getElementById('pickup_location');
                const amountInput = document.getElementById('amount');

                if (!modeSelect || !locationSelect || !amountInput) {
                    return;
                }

                function updateTransportForm() {
                    const existingMode = modeSelect.value === 'existing';

                    existingFields.forEach(container => {
                        container.style.display = existingMode ? '' : 'none';
                    });

                    outsideFields.forEach(container => {
                        container.style.display = existingMode ? 'none' : '';
                        container.querySelectorAll('input, select').forEach(field => {
                            field.disabled = existingMode;
                            if (field.dataset.requiredOutside === 'true') {
                                field.required = !existingMode;
                            }
                        });
                    });

                    if (studentSelect) {
                        studentSelect.disabled = !existingMode;
                    }

                    if (existingMode) {
                        if (studentName) studentName.value = '';
                        if (genderSelect) genderSelect.value = '';
                    }
                }

                function updateAmount() {
                    const selectedOption = locationSelect.selectedOptions[0];
                    const fee = selectedOption?.dataset?.fee ? parseFloat(selectedOption.dataset.fee) : 0;
                    amountInput.value = Number.isFinite(fee) ? fee.toFixed(2) : '0.00';
                }

                modeSelect.addEventListener('change', updateTransportForm);
                locationSelect.addEventListener('change', updateAmount);
                updateTransportForm();
                updateAmount();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTransportForm);
            } else {
                initTransportForm();
            }
        })();
        </script>

        <div class="modal fade" id="transportModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-body" id="transportModalBody"></div>
                    <div class="modal-footer" id="transportModalFooter"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <h2>Transport Accounts</h2>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-3"><input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search student, parent, route"></div>
                <div class="col-md-3"><input class="form-control" name="location" value="<?= h($location) ?>" placeholder="Route/location"></div>
                <div class="col-md-2"><input class="form-control" name="year" value="<?= h($filterYear) ?>" maxlength="4" placeholder="Year"></div>
                <div class="col-md-2">
                    <select class="form-select" name="term">
                        <option value="">All terms</option>
                        <?php foreach ($terms as $term): ?><option value="<?= h($term) ?>" <?= $filterTerm === $term ? 'selected' : '' ?>><?= h($term) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table id="transportAccountsTable" class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Route</th><th>Year</th><th>Term</th><th>Due</th><th>Paid</th><th>Balance</th><th>Actions</th></tr></thead>
                    <tbody id="transportAccountsBody">
                        <?= render_transport_accounts_rows($accounts, $canAddTransport, $canDeleteTransport, $canEditTransport) ?>
                    </tbody>
                </table>
            </div>
        </section>
        <script>
        (function () {
            const transportForm = document.getElementById('transportForm');
            const transportMessages = document.getElementById('transportMessages');
            const transportErrors = document.getElementById('transportErrors');
            const transportAccountsBody = document.getElementById('transportAccountsBody');
            const modalElement = document.getElementById('transportModal');
            const transportModalBody = document.getElementById('transportModalBody');
            const transportModalFooter = document.getElementById('transportModalFooter');
            let bootstrapModal = null;

            function getBootstrapModal() {
                if (!modalElement) {
                    return null;
                }
                if (window.bootstrap && window.bootstrap.Modal) {
                    if (!bootstrapModal) {
                        bootstrapModal = new window.bootstrap.Modal(modalElement);
                    }
                    return bootstrapModal;
                }
                return null;
            }

            let customModalBackdrop = null;
            function createBackdrop() {
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                return backdrop;
            }

            function hideCustomModal() {
                if (!modalElement) {
                    return;
                }
                modalElement.classList.remove('show');
                modalElement.style.display = 'none';
                modalElement.setAttribute('aria-hidden', 'true');
                modalElement.removeAttribute('aria-modal');
                document.body.classList.remove('modal-open');
                if (customModalBackdrop && customModalBackdrop.parentNode) {
                    customModalBackdrop.parentNode.removeChild(customModalBackdrop);
                }
                customModalBackdrop = null;
            }

            function showCustomModal() {
                if (!modalElement) {
                    return;
                }
                if (!customModalBackdrop) {
                    customModalBackdrop = createBackdrop();
                    document.body.appendChild(customModalBackdrop);
                }
                modalElement.classList.add('show');
                modalElement.style.display = 'block';
                modalElement.setAttribute('aria-modal', 'true');
                modalElement.removeAttribute('aria-hidden');
                document.body.classList.add('modal-open');
                modalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => {
                    if (!button._customModalCloseAttached) {
                        button._customModalCloseAttached = true;
                        button.addEventListener('click', hideCustomModal);
                    }
                });
            }

            function formatCurrency(value) {
                const amount = Number(value) || 0;
                return 'KES ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }

            function ajaxFetch(url, options) {
                options = options || {};
                options.headers = Object.assign({
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-Token': window.APP_CSRF_TOKEN
                }, options.headers || {});
                return fetch(url, options);
            }

            function parseJsonResponse(response) {
                if (!response.ok) {
                    return response.text().then(function (text) {
                        throw new Error(text || 'Server returned status ' + response.status);
                    });
                }
                var contentType = response.headers.get('content-type') || '';
                if (contentType.indexOf('application/json') === -1) {
                    return response.text().then(function (text) {
                        throw new Error(text || 'Unexpected server response format.');
                    });
                }
                return response.json();
            }

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, function (character) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    }[character];
                });
            }

            function clearMessages() {
                if (transportMessages) transportMessages.innerHTML = '';
                if (transportErrors) transportErrors.innerHTML = '';
            }

            function showMessage(text, type) {
                if (!transportMessages) return;
                transportMessages.innerHTML = '<div class="alert alert-' + type + '">' + escapeHtml(text) + '</div>';
            }

            function showErrors(errors) {
                if (!transportErrors) return;
                transportErrors.innerHTML = '<div class="alert alert-danger">' + errors.map(function (error) {
                    return '<div>' + escapeHtml(error) + '</div>';
                }).join('') + '</div>';
            }

            function refreshAccounts() {
                ajaxFetch(window.location.pathname + '?ajax=accounts&nocache=' + Date.now())
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        if (data.success && transportAccountsBody) {
                            transportAccountsBody.innerHTML = data.html;
                            attachAccountActions();
                        }
                    });
            }

            function openModal(title, bodyHtml, footerHtml) {
                if (!transportModalBody || !transportModalFooter || !modalElement) {
                    return;
                }
                transportModalBody.innerHTML = bodyHtml;
                transportModalFooter.innerHTML = footerHtml || '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                const titleElement = modalElement.querySelector('.modal-title');
                if (titleElement) {
                    titleElement.textContent = title;
                }
                const modal = getBootstrapModal();
                if (modal) {
                    modal.show();
                } else {
                    showCustomModal();
                }
            }

            function openPaymentModal(accountId) {
                const row = document.querySelector('tr[data-account-id="' + accountId + '"]');
                if (!row) {
                    showMessage('Unable to find the selected account.', 'danger');
                    return;
                }
                const studentName = row.dataset.studentName;
                const route = row.dataset.route;
                const year = row.dataset.year;
                const term = row.dataset.term;
                const due = row.dataset.due;
                const paid = row.dataset.paid;
                const balance = row.dataset.balance;
                const bodyHtml = '<div class="mb-3"><strong>' + escapeHtml(studentName) + '</strong><br>' +
                    '<small>' + escapeHtml(route) + ' &bull; ' + escapeHtml(year) + ' &bull; ' + escapeHtml(term) + '</small></div>' +
                    '<div class="row g-3 mb-3">' +
                    '<div class="col-md-4"><div class="border rounded p-3"><strong>Due</strong><div>' + formatCurrency(due) + '</div></div></div>' +
                    '<div class="col-md-4"><div class="border rounded p-3"><strong>Paid</strong><div>' + formatCurrency(paid) + '</div></div></div>' +
                    '<div class="col-md-4"><div class="border rounded p-3"><strong>Balance</strong><div>' + formatCurrency(balance) + '</div></div></div>' +
                    '</div>' +
                    '<form id="transportPaymentForm" class="row g-3">' +
                    '<input type="hidden" name="action" value="save_payment">' +
                    '<input type="hidden" name="payment_account_id" value="' + accountId + '">' +
                    '<div class="col-md-6"><label class="form-label" for="modal_amount_paid">Amount Paid</label><input class="form-control" type="number" min="0.01" step="0.01" id="modal_amount_paid" name="amount_paid" required></div>' +
                    '<div class="col-md-6"><label class="form-label" for="modal_payment_date">Date</label><input class="form-control" type="date" id="modal_payment_date" name="payment_date" value="' + new Date().toISOString().slice(0, 10) + '" required></div>' +
                    '<div class="col-12"><label class="form-label" for="modal_reference_no">Reference</label><input class="form-control" id="modal_reference_no" name="reference_no"></div>' +
                    '</form>';
                const footerHtml = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-primary" id="saveTransportPaymentBtn">Save Payment</button>';
                openModal('Record Transport Payment', bodyHtml, footerHtml);
                const saveBtn = document.getElementById('saveTransportPaymentBtn');
                if (saveBtn) {
                    saveBtn.addEventListener('click', function () {
                        const form = document.getElementById('transportPaymentForm');
                        if (!form) return;
                        submitPaymentForm(form);
                    });
                }
            }

            function openHistoryModal(accountId) {
                ajaxFetch(window.location.pathname + '?ajax=account_history&account_id=' + accountId)
                    .then(parseJsonResponse)
                    .then(function (data) {
                        if (!data.success) {
                            showMessage('Unable to load history.', 'danger');
                            return;
                        }
                        const rows = data.history;
                        let bodyHtml = '<div class="mb-3">Payment history for this transport account.</div>';
                        bodyHtml += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Amount</th><th>Reference</th></tr></thead><tbody>';
                        if (rows.length) {
                            rows.forEach(function (payment) {
                                bodyHtml += '<tr><td>' + payment.payment_date + '</td><td>' + formatCurrency(payment.amount_paid) + '</td><td>' + (payment.reference_no || '&mdash;') + '</td></tr>';
                            });
                        } else {
                            bodyHtml += '<tr><td colspan="3">No payments have been recorded for this account yet.</td></tr>';
                        }
                        bodyHtml += '</tbody></table></div>';
                        openModal('Transport Payment History', bodyHtml, '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>');
                    })
                    .catch(function (error) {
                        showMessage(error.message || 'Unable to load history.', 'danger');
                    });
            }

            function openReceiptModal(accountId) {
                ajaxFetch(window.location.pathname + '?ajax=latest_payment&account_id=' + accountId)
                    .then(parseJsonResponse)
                    .then(function (data) {
                        if (!data.success) {
                            showMessage('Unable to load receipt details.', 'danger');
                            return;
                        }
                        if (!data.html) {
                            openModal('Receipt', '<div class="alert alert-warning">No receipt is available for this account yet.</div>', '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>');
                            return;
                        }
                        openModal('Transport Receipt', data.html, '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>');
                    })
                    .catch(function (error) {
                        showMessage(error.message || 'Unable to load receipt details.', 'danger');
                    });
            }

            function openPaymentReceiptModal(paymentId) {
                ajaxFetch(window.location.pathname + '?ajax=payment_receipt&payment_id=' + paymentId)
                    .then(parseJsonResponse)
                    .then(function (data) {
                        if (!data.success || !data.html) {
                            showMessage('Unable to load receipt details.', 'danger');
                            return;
                        }
                        openModal('Transport Receipt', data.html, '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>');
                    })
                    .catch(function (error) {
                        showMessage(error.message || 'Unable to load receipt details.', 'danger');
                    });
            }

            function submitPaymentForm(form) {
                if (form.reportValidity && !form.reportValidity()) {
                    return;
                }
                clearMessages();
                const saveBtn = document.getElementById('saveTransportPaymentBtn');
                if (saveBtn) {
                    saveBtn.disabled = true;
                }
                const formData = new FormData(form);
                formData.append('ajax', '1');
                ajaxFetch(window.location.pathname, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                })
                    .then(parseJsonResponse)
                    .then(function (data) {
                        if (data.success) {
                            showMessage(data.message || 'Payment saved successfully.', 'success');
                            if (bootstrapModal) {
                                bootstrapModal.hide();
                            } else {
                                hideCustomModal();
                            }
                            if (transportAccountsBody && data.html) {
                                transportAccountsBody.innerHTML = data.html;
                                attachAccountActions();
                            }
                            var historyBody = document.getElementById('transportPaymentHistoryBody');
                            if (historyBody && data.history_html) {
                                historyBody.innerHTML = data.history_html;
                            }
                            if (data.receipt_html) {
                                openModal('Transport Receipt', data.receipt_html, '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>');
                            }
                        } else {
                            showErrors(data.errors || ['Unable to record payment.']);
                        }
                    })
                    .catch(function (error) {
                        showErrors([error.message || 'An unexpected error occurred while saving the payment.']);
                    })
                    .finally(function () {
                        if (saveBtn) {
                            saveBtn.disabled = false;
                        }
                    });
            }

            function attachAccountActions() {
                document.querySelectorAll('[data-action="pay"]').forEach(function (button) {
                    button.removeEventListener('click', handlePayClick);
                    button.addEventListener('click', handlePayClick);
                });
            }

            function attachHistoryReceiptActions() {
                document.querySelectorAll('[data-action="history-receipt"]').forEach(function (button) {
                    button.removeEventListener('click', handleHistoryReceiptClick);
                    button.addEventListener('click', handleHistoryReceiptClick);
                });
            }

            window.attachHistoryReceiptActions = attachHistoryReceiptActions;

            function handlePayClick(event) {
                const accountId = event.currentTarget.dataset.accountId;
                if (accountId) {
                    openPaymentModal(accountId);
                }
            }

            function handleHistoryClick(event) {
                const accountId = event.currentTarget.dataset.accountId;
                if (accountId) {
                    openHistoryModal(accountId);
                }
            }

            function handleReceiptClick(event) {
                const accountId = event.currentTarget.dataset.accountId;
                if (accountId) {
                    openReceiptModal(accountId);
                }
            }

            function handleHistoryReceiptClick(event) {
                const paymentId = event.currentTarget.dataset.paymentId;
                if (paymentId) {
                    openPaymentReceiptModal(paymentId);
                }
            }

            function handleHistoryReceiptDelegatedClick(event) {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const button = target.closest('button[data-action="history-receipt"]');
                if (!button) {
                    return;
                }
                event.preventDefault();
                const paymentId = button.dataset.paymentId;
                if (paymentId) {
                    openPaymentReceiptModal(paymentId);
                }
            }

            if (document.body) {
                document.body.addEventListener('click', handleHistoryReceiptDelegatedClick);
            }

            if (transportForm) {
                transportForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (!window.fetch) {
                        transportForm.submit();
                        return;
                    }
                    const formData = new FormData(transportForm);
                    formData.append('ajax', '1');
                    ajaxFetch(window.location.pathname, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            if (data.success) {
                                clearMessages();
                                showMessage(data.message || 'Transport account saved successfully.', 'success');
                                transportForm.reset();
                                transportForm.querySelectorAll('.outside-fields input, .outside-fields select').forEach(function (field) {
                                    field.disabled = true;
                                });
                                if (transportAccountsBody && data.html) {
                                    transportAccountsBody.innerHTML = data.html;
                                    attachAccountActions();
                                }
                            } else {
                                showErrors(data.errors || ['Unable to save transport account.']);
                            }
                        })
                        .catch(function () {
                            showErrors(['An unexpected error occurred while saving the transport account.']);
                        });
                });
            }

            function initTransportPage() {
                attachAccountActions();
                attachHistoryReceiptActions();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTransportPage);
            } else {
                initTransportPage();
            }
        })();
        </script>
        <section class="panel mt-4">
            <h2>Payment History</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Term</th><th>Amount</th><th>Date</th><th>Reference</th><th>Receipt</th></tr></thead>
                    <tbody id="transportPaymentHistoryBody">
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><?= h($payment['student_name']) ?></td>
                                <td><?= h($payment['term'] . ' ' . $payment['academic_year']) ?></td>
                                <td><?= money((float) $payment['amount_paid']) ?></td>
                                <td><?= h($payment['payment_date']) ?></td>
                                <td><?= h($payment['reference_no']) ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/transport_receipt.php?id=' . $payment['id']) ?>">Receipt</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentHistory): ?><tr><td colspan="6">No transport payments recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <script>
            if (window.attachHistoryReceiptActions) {
                window.attachHistoryReceiptActions();
            }
        </script>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
