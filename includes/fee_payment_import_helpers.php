<?php
require_once __DIR__ . '/fee_helpers.php';
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/fee_receipt_helpers.php';

function fee_import_template_headers(): array
{
    return [
        'Receipt No', 'Payment Date', 'Student Name', 'M-PESA Code', 'Payment Method', 'Amount Paid',
    ];
}

function fee_import_normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    $aliases = [
        'receipt' => 'receipt_no', 'receipt_number' => 'receipt_no', 'receipt_num' => 'receipt_no',
        'admission_number' => 'admission_no', 'admission_number_no' => 'admission_no',
        'registration_no' => 'admission_no', 'registration_number' => 'admission_no',
        'reg_no' => 'admission_no', 'regno' => 'admission_no', 'student_registration_no' => 'admission_no',
        'full_name' => 'student_name', 'name' => 'student_name',
        'academic_year' => 'year', 'payment_year' => 'year',
        'academic_term' => 'term', 'payment_term' => 'term',
        'transaction_code' => 'mpesa_code', 'transaction_reference' => 'mpesa_code', 'reference_no' => 'mpesa_code',
        'reference_text' => 'mpesa_reference_text', 'mpesa_reference' => 'mpesa_reference_text',
        'method' => 'payment_method', 'payment_type' => 'payment_method',
        'payment_status' => 'status', 'amount' => 'amount_paid', 'paid_amount' => 'amount_paid',
        'balance' => 'term_balance', 'balance_after_payment' => 'term_balance',
        'created' => 'created_at',
    ];
    return $aliases[$header] ?? $header;
}

function fee_import_create_template(): string
{
    $cells = [];
    foreach (fee_import_template_headers() as $index => $header) {
        $column = '';
        $number = $index + 1;
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $column = chr(65 + $remainder) . $column;
            $number = intdiv($number - 1, 26);
        }
        $cells[] = '<c r="' . $column . '1" t="inlineStr"><is><t>'
            . htmlspecialchars($header, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</t></is></c>';
    }

    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Fee Payments" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">' . implode('', $cells) . '</row></sheetData></worksheet>',
    ];

    return xlsx_create_stored_zip($entries);
}

function fee_import_read_workbook(string $filename): array
{
    $rows = xlsx_read_rows_with_numbers($filename, 1001);
    $headerRecord = null;
    foreach ($rows as $record) {
        if (array_filter($record['values'], static fn($value): bool => trim((string) $value) !== '')) {
            $headerRecord = $record;
            break;
        }
    }
    if (!$headerRecord) {
        throw new RuntimeException('The workbook does not contain a header row.');
    }

    $columns = [];
    foreach ($headerRecord['values'] as $index => $label) {
        $key = fee_import_normalize_header((string) $label);
        if ($key === '') {
            continue;
        }
        if (isset($columns[$key])) {
            throw new RuntimeException('The workbook contains the same recognized column more than once: ' . $label . '.');
        }
        $columns[$key] = $index;
    }

    foreach (['payment_date', 'amount_paid', 'payment_method'] as $required) {
        if (!isset($columns[$required])) {
            throw new RuntimeException('Missing required Excel column: ' . str_replace('_', ' ', $required) . '.');
        }
    }
    if (!isset($columns['student_name']) && !isset($columns['admission_no'])) {
        throw new RuntimeException('The workbook must include Student Name or Admission No so each payment can be matched to a student.');
    }

    $dataRows = [];
    foreach ($rows as $record) {
        if ($record['row_number'] <= $headerRecord['row_number']) {
            continue;
        }
        $nonEmpty = false;
        foreach ($record['values'] as $value) {
            if (trim((string) $value) !== '') {
                $nonEmpty = true;
                break;
            }
        }
        if (!$nonEmpty) {
            continue;
        }

        $values = [];
        foreach ($columns as $key => $index) {
            $values[$key] = trim((string) ($record['values'][$index] ?? ''));
        }
        unset($values['year'], $values['term'], $values['mpesa_reference_text']);
        $dataRows[] = ['source_row' => (int) $record['row_number'], 'values' => $values];
    }

    if (!$dataRows) {
        throw new RuntimeException('The workbook has headers but no payment rows.');
    }
    if (count($dataRows) > 1000) {
        throw new RuntimeException('A single import can contain at most 1,000 payment rows.');
    }

    return ['columns' => array_keys($columns), 'rows' => $dataRows];
}

function fee_import_parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
        $serial = (float) $value;
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $days = (int) floor($serial);
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!j M Y', '!M j, Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $issues = DateTimeImmutable::getLastErrors();
        if ($date && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function fee_import_parse_amount(string $value): ?float
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/^KES\s*/', '', $value);
    $value = str_replace([',', ' '], '', (string) $value);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    $amount = round((float) $value, 2);
    return $amount > 0 ? $amount : null;
}

function fee_import_normalize_method(string $value): ?string
{
    $method = strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    $methods = [
        'M-PESA' => 'M-PESA', 'MPESA' => 'M-PESA', 'M PESA' => 'M-PESA',
        'CASH' => 'Cash', 'BANK' => 'Bank', 'BANK TRANSFER' => 'Bank',
        'CHEQUE' => 'Cheque', 'CHECK' => 'Cheque', 'OTHER' => 'Other',
    ];
    return $methods[$method] ?? null;
}

function fee_import_find_students(PDO $pdo, string $registrationNo): array
{
    $statement = $pdo->prepare(
        'SELECT id, registration_no, full_name, class_level, enrollment_status
         FROM students WHERE registration_no = :registration_no ORDER BY id LIMIT 2'
    );
    $statement->execute(['registration_no' => trim($registrationNo)]);
    return $statement->fetchAll();
}

function fee_import_find_students_by_name(PDO $pdo, string $studentName): array
{
    $studentName = trim((string) preg_replace('/\s+/u', ' ', $studentName));
    if ($studentName === '') {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT id, registration_no, full_name, class_level, enrollment_status
         FROM students WHERE LOWER(TRIM(full_name)) = LOWER(:full_name) ORDER BY id LIMIT 2'
    );
    $statement->execute(['full_name' => $studentName]);
    return $statement->fetchAll();
}

function fee_import_validate_rows(PDO $pdo, array $rawRows, array $mappings, string $fallbackYear, string $fallbackTerm): array
{
    $currentYear = (int) date('Y');
    $receiptCheck = $pdo->prepare('SELECT id FROM fees WHERE receipt_no = :receipt_no LIMIT 1');
    $codeCheck = $pdo->prepare("SELECT id FROM fees WHERE UPPER(REPLACE(TRIM(mpesa_code), CHAR(32), '')) = :code LIMIT 1");
    $potentialCheck = $pdo->prepare(
        'SELECT receipt_no FROM fees
         WHERE student_id = :student_id AND year = :year AND term = :term AND payment_date = :payment_date
           AND UPPER(payment_method) = :method AND ABS(amount_paid - :amount_paid) < 0.005
         LIMIT 1'
    );
    $balanceCheck = $pdo->prepare(
        'SELECT id, paid_amount, balance FROM fee_balances
         WHERE student_id = :student_id AND academic_year = :year AND term = :term LIMIT 1'
    );
    $ledgerCheck = $pdo->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) FROM fees
         WHERE student_id = :student_id AND year = :year AND term = :term AND payment_status = 'Recorded'"
    );

    $result = [];
    $seenReceipts = [];
    $seenCodes = [];
    $seenPotential = [];
    $groups = [];

    foreach ($rawRows as $raw) {
        $sourceRow = (int) $raw['source_row'];
        $source = $raw['values'];
        $errors = [];
        $warnings = [];
        $sourceRegistration = trim((string) ($source['admission_no'] ?? ''));
        $sourceName = trim((string) ($source['student_name'] ?? ''));
        $studentMatches = $sourceRegistration !== '' ? fee_import_find_students($pdo, $sourceRegistration) : [];
        $nameMatches = $sourceName !== '' ? fee_import_find_students_by_name($pdo, $sourceName) : [];
        $mappedTo = trim((string) ($mappings[$sourceRow] ?? ''));
        $student = null;
        $needsMapping = false;
        $matchedByName = false;

        if (count($studentMatches) > 1) {
            $errors[] = 'This admission number matches more than one student in the database; resolve the duplicate student records first.';
        } elseif (count($studentMatches) === 1) {
            $student = $studentMatches[0];
            if ($mappedTo !== '' && $mappedTo !== (string) $student['registration_no']) {
                $errors[] = 'A mapping cannot replace a valid admission-number match. Clear the mapping and use the matched student.';
            }
        } elseif (count($nameMatches) === 1) {
            $student = $nameMatches[0];
            $matchedByName = true;
            if ($sourceRegistration !== '') {
                $warnings[] = 'Admission number ' . $sourceRegistration . ' was not found; the unique exact student-name match resolved to ' . $student['registration_no'] . '.';
            }
            if ($mappedTo !== '' && $mappedTo !== (string) $student['registration_no']) {
                $errors[] = 'The selected admission number does not match the unique student-name match.';
            }
        } elseif (count($nameMatches) > 1) {
            if ($mappedTo !== '') {
                $mappedMatches = fee_import_find_students($pdo, $mappedTo);
                $matchingNameIds = array_map(static fn(array $match): int => (int) $match['id'], $nameMatches);
                if (count($mappedMatches) === 1 && in_array((int) $mappedMatches[0]['id'], $matchingNameIds, true)) {
                    $student = $mappedMatches[0];
                    $matchedByName = true;
                } else {
                    $errors[] = 'Choose an admission number belonging to one of the students with this name.';
                    $needsMapping = true;
                }
            } else {
                $errors[] = 'More than one student has this name. Choose the correct admission number in the mapping field.';
                $needsMapping = true;
            }
        } elseif ($mappedTo !== '') {
            $mappedMatches = fee_import_find_students($pdo, $mappedTo);
            if (count($mappedMatches) === 1) {
                $student = $mappedMatches[0];
                $warnings[] = ($sourceRegistration !== '' ? 'Legacy admission number ' . $sourceRegistration : 'Student name ' . ($sourceName ?: '(blank)'))
                    . ' was explicitly mapped to ' . $student['registration_no'] . '.';
            } elseif (count($mappedMatches) > 1) {
                $errors[] = 'The selected mapping admission number is not unique.';
                $needsMapping = true;
            } else {
                $errors[] = 'The mapped admission number ' . $mappedTo . ' does not exist.';
                $needsMapping = true;
            }
        } else {
            $errors[] = $sourceName !== ''
                ? 'No unique student match was found for this name. Enter the correct existing admission number in the mapping field.'
                : 'Student Name or Admission No is required.';
            $needsMapping = true;
        }

        $paymentDate = fee_import_parse_date((string) ($source['payment_date'] ?? ''));
        if (!$paymentDate) {
            $errors[] = 'Payment Date is missing or invalid. Use YYYY-MM-DD, DD/MM/YYYY, or a real Excel date cell.';
        }
        $amount = fee_import_parse_amount((string) ($source['amount_paid'] ?? ''));
        if ($amount === null) {
            $errors[] = 'Amount Paid must be a positive amount with no more than two decimal places.';
        }

        $year = trim($fallbackYear);
        if (!preg_match('/^\d{4}$/', $year) || (int) $year < 1900 || (int) $year > $currentYear + 2) {
            $errors[] = 'Choose a valid four-digit academic year for this import.';
        }
        $term = trim($fallbackTerm);
        if (!in_array($term, term_options(), true)) {
            $errors[] = 'Choose a valid academic term for this import.';
        }

        $method = fee_import_normalize_method((string) ($source['payment_method'] ?? ''));
        if (!$method) {
            $errors[] = 'Payment Method must be M-PESA, Cash, Bank, Cheque, or Other.';
        }
        $status = trim((string) ($source['status'] ?? ''));
        if ($status !== '' && strcasecmp($status, 'Recorded') !== 0) {
            $errors[] = 'Only successful Recorded payments can be imported. Reversed or cancelled rows must not be re-imported.';
        }

        $receiptNo = trim((string) ($source['receipt_no'] ?? ''));
        if (strlen($receiptNo) > 60) {
            $errors[] = 'Receipt No must be 60 characters or fewer.';
        } elseif ($receiptNo !== '') {
            $receiptCheck->execute(['receipt_no' => $receiptNo]);
            if ($receiptCheck->fetchColumn()) {
                $errors[] = 'Receipt No ' . $receiptNo . ' already exists and cannot be imported again.';
            }
            $receiptKey = strtoupper($receiptNo);
            if (isset($seenReceipts[$receiptKey])) {
                $errors[] = 'Receipt No is repeated in the uploaded file (first used on row ' . $seenReceipts[$receiptKey] . ').';
            }
            $seenReceipts[$receiptKey] = $sourceRow;
        } else {
            $warnings[] = 'No original receipt number was supplied. A clearly system-generated receipt will be issued at import time; it is not represented as the historical original.';
        }

        $code = strtoupper(trim(preg_replace('/\s+/', '', (string) ($source['mpesa_code'] ?? ''))));
        if (strlen($code) > 30) {
            $errors[] = 'Transaction/reference code must be 30 characters or fewer.';
        }
        if ($method === 'M-PESA' && $code === '') {
            $errors[] = 'M-PESA payments must include their original transaction code; no code will be invented.';
        } elseif ($method === 'M-PESA' && !preg_match('/^[A-Z0-9]{8,20}$/', $code)) {
            $errors[] = 'M-PESA transaction codes must contain 8 to 20 letters or numbers.';
        }
        if ($code !== '') {
            $codeCheck->execute(['code' => $code]);
            if ($codeCheck->fetchColumn()) {
                $errors[] = 'Transaction/reference code ' . $code . ' already exists and cannot be imported again.';
            }
            if (isset($seenCodes[$code])) {
                $errors[] = 'Transaction/reference code is repeated in the uploaded file (first used on row ' . $seenCodes[$code] . ').';
            }
            $seenCodes[$code] = $sourceRow;
        }

        if ($student && $sourceRegistration !== '' && $mappedTo === '') {
            if ($sourceName !== '' && mb_strtolower(preg_replace('/\s+/', ' ', $sourceName)) !== mb_strtolower(preg_replace('/\s+/', ' ', (string) $student['full_name']))) {
                $warnings[] = 'The spreadsheet name differs from the database. Admission number remains the match key; database name: ' . $student['full_name'] . '.';
            }
        }

        if ($student && $paymentDate && $amount !== null && preg_match('/^\d{4}$/', $year) && in_array($term, term_options(), true) && $method) {
            $potentialCheck->execute([
                'student_id' => (int) $student['id'], 'year' => $year, 'term' => $term,
                'payment_date' => $paymentDate, 'method' => strtoupper($method), 'amount_paid' => $amount,
            ]);
            $possible = $potentialCheck->fetchColumn();
            $potentialKey = implode('|', [(int) $student['id'], $year, $term, $paymentDate, $method, number_format($amount, 2, '.', '')]);
            if ($possible || isset($seenPotential[$potentialKey])) {
                $warnings[] = 'A payment with the same student, amount, date, academic period, and method exists or appears elsewhere in this file. Confirm it is a separate legitimate payment.';
            }
            $seenPotential[$potentialKey] = $sourceRow;

            $groupKey = implode('|', [(int) $student['id'], $year, $term]);
            if (!isset($groups[$groupKey])) {
                $balanceCheck->execute(['student_id' => (int) $student['id'], 'year' => $year, 'term' => $term]);
                $balance = $balanceCheck->fetch();
                $ledgerCheck->execute(['student_id' => (int) $student['id'], 'year' => $year, 'term' => $term]);
                $ledgerPaid = (float) $ledgerCheck->fetchColumn();
                $groups[$groupKey] = [
                    'balance' => $balance,
                    'ledger_paid' => $ledgerPaid,
                    'unledgered_credit' => $balance ? max(0, (float) $balance['paid_amount'] - $ledgerPaid) : 0,
                    'current_balance' => $balance ? (float) $balance['balance'] : 0,
                    'inconsistent' => $balance && $ledgerPaid > (float) $balance['paid_amount'] + 0.01,
                ];
            }
            if (!$groups[$groupKey]['balance']) {
                $errors[] = 'No saved fee balance exists for this student and period. Assess or reconcile that period before importing payments.';
            } elseif ($groups[$groupKey]['inconsistent']) {
                $errors[] = 'The saved paid total is smaller than the existing recorded payment ledger for this period. Reconcile the balance before importing.';
            } else {
                $group =& $groups[$groupKey];
                $covered = min($amount, $group['unledgered_credit']);
                $group['unledgered_credit'] -= $covered;
                $additional = $amount - $covered;
                if ($covered > 0.005) {
                    $warnings[] = 'KES ' . number_format($covered, 2) . ' is already reflected in the saved paid total but not in payment records. This portion will be added to history without reducing the balance again.';
                }
                if ($additional > $group['current_balance'] + 0.005) {
                    $errors[] = 'The amount exceeds the remaining fee balance after reconciling previously credited payments.';
                } else {
                    $group['current_balance'] = max(0, $group['current_balance'] - $additional);
                }
                $rowBalanceAfter = $group['current_balance'];
                $rowBalanceAdjustment = $additional;
                unset($group);
            }
        } else {
            $rowBalanceAfter = null;
            $rowBalanceAdjustment = null;
        }

        $result[] = [
            'source_row' => $sourceRow,
            'source_registration_no' => $sourceRegistration,
            'mapped_registration_no' => $mappedTo,
                'matched_by_name' => $matchedByName,
            'student' => $student,
            'data' => [
                'receipt_no' => $receiptNo,
                'payment_date' => $paymentDate,
                'student_name' => trim((string) ($source['student_name'] ?? '')),
                'enrollment_status' => trim((string) ($source['enrollment_status'] ?? '')),
                'class_level' => trim((string) ($source['class_level'] ?? '')),
                'term' => $term,
                'year' => $year,
                'mpesa_code' => $code,
                'payment_method' => $method,
                'amount_paid' => $amount,
            ],
            'balance_after' => $rowBalanceAfter ?? null,
            'balance_adjustment' => $rowBalanceAdjustment ?? null,
            'needs_mapping' => $needsMapping,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    $errorCount = array_sum(array_map(static fn(array $row): int => count($row['errors']), $result));
    $warningCount = array_sum(array_map(static fn(array $row): int => count($row['warnings']), $result));
    return ['rows' => $result, 'error_count' => $errorCount, 'warning_count' => $warningCount];
}

function fee_import_prepare_receipt_sequences(PDO $pdo, array $rows, DateTimeImmutable $issuedAt): void
{
    $years = [$issuedAt->format('Y') => true];
    foreach ($rows as $row) {
        if (preg_match('/^ATXR-(\d{4})-(\d+)$/i', (string) $row['data']['receipt_no'], $matches)) {
            $years[$matches[1]] = true;
        }
    }
    foreach (array_keys($years) as $year) {
        fee_prepare_receipt_sequence($pdo, new DateTimeImmutable($year . '-01-01 00:00:00', $issuedAt->getTimezone()));
    }
}

function fee_import_preserve_receipt_sequence(PDO $pdo, string $receiptNo): void
{
    if (!preg_match('/^ATXR-(\d{4})-(\d+)$/i', $receiptNo, $matches)) {
        return;
    }
    $year = $matches[1];
    $next = (int) $matches[2] + 1;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $suffix = $driver === 'sqlite' ? '' : ' FOR UPDATE';
    $select = $pdo->prepare('SELECT next_number FROM fee_receipt_sequences WHERE issue_year = :year' . $suffix);
    $select->execute(['year' => $year]);
    $current = (int) $select->fetchColumn();
    if ($current < $next) {
        $update = $pdo->prepare('UPDATE fee_receipt_sequences SET next_number = :next_number WHERE issue_year = :year');
        $update->execute(['next_number' => $next, 'year' => $year]);
    }
}

function fee_import_commit(PDO $pdo, array $rawRows, array $mapping, string $fallbackYear, string $fallbackTerm, string $sourceFilename, string $sourceHash, int $actorId, bool $confirmWarnings): array
{
    $issuedAt = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    fee_import_prepare_receipt_sequences($pdo, fee_import_validate_rows($pdo, $rawRows, $mapping, $fallbackYear, $fallbackTerm)['rows'], $issuedAt);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lockSuffix = $driver === 'sqlite' ? '' : ' FOR UPDATE';

    $referenceLocks = [];
    try {
        if ($driver !== 'sqlite') {
            $codes = array_values(array_unique(array_filter(array_map(
                static fn(array $row): string => (string) $row['values']['mpesa_code'],
                $rawRows
            ))));
            sort($codes, SORT_STRING);
            foreach ($codes as $code) {
                $referenceLock = fee_acquire_payment_reference_lock($pdo, $code);
                if ($referenceLock !== null) {
                    $referenceLocks[] = $referenceLock;
                }
            }
        }

        $pdo->beginTransaction();
        $duplicateBatch = $pdo->prepare('SELECT id FROM fee_payment_imports WHERE source_sha256 = :hash LIMIT 1' . $lockSuffix);
        $duplicateBatch->execute(['hash' => $sourceHash]);
        if ($duplicateBatch->fetchColumn()) {
            throw new RuntimeException('This exact workbook has already been imported. No additional payments were created.');
        }

        $preflight = fee_import_validate_rows($pdo, $rawRows, $mapping, $fallbackYear, $fallbackTerm);
        if ($preflight['error_count'] > 0) {
            throw new RuntimeException('The workbook changed or one or more rows are no longer valid. Review the refreshed preview before importing.');
        }
        if ($preflight['warning_count'] > 0 && !$confirmWarnings) {
            throw new RuntimeException('Review the import warnings and explicitly confirm them before importing.');
        }
        $rows = $preflight['rows'];
        $studentIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['student']['id'], $rows)));
        sort($studentIds, SORT_NUMERIC);
        foreach ($studentIds as $studentId) {
            $studentLock = $pdo->prepare('SELECT id FROM students WHERE id = :id' . $lockSuffix);
            $studentLock->execute(['id' => $studentId]);
            if (!$studentLock->fetchColumn()) {
                throw new RuntimeException('A matched student was removed while the import was being confirmed. No payments were saved.');
            }
        }

        // Revalidate after student locks so payment rows and balances cannot race normal fee entry.
        $preflight = fee_import_validate_rows($pdo, $rawRows, $mapping, $fallbackYear, $fallbackTerm);
        if ($preflight['error_count'] > 0) {
            throw new RuntimeException('A duplicate or balance change was detected during confirmation. No payments were saved; review the refreshed preview.');
        }
        if ($preflight['warning_count'] > 0 && !$confirmWarnings) {
            throw new RuntimeException('New warnings appeared during confirmation. Review and confirm them before retrying.');
        }
        $rows = $preflight['rows'];
        $totalWarnings = $preflight['warning_count'];
        $defaultYear = count(array_unique(array_column(array_column($rows, 'data'), 'year'))) === 1 ? $rows[0]['data']['year'] : null;
        $defaultTerm = count(array_unique(array_column(array_column($rows, 'data'), 'term'))) === 1 ? $rows[0]['data']['term'] : null;

        $insertBatch = $pdo->prepare(
            'INSERT INTO fee_payment_imports (source_filename, source_sha256, actor_id, academic_year, term, total_rows, imported_rows, skipped_rows, rejected_rows, error_count, duplicate_warnings, summary)
             VALUES (:filename, :hash, :actor_id, :year, :term, :total, 0, 0, 0, 0, :warnings, :summary)'
        );
        $insertBatch->execute([
            'filename' => mb_substr(basename($sourceFilename), 0, 255), 'hash' => $sourceHash,
            'actor_id' => $actorId > 0 ? $actorId : null, 'year' => $defaultYear, 'term' => $defaultTerm,
            'total' => count($rows), 'warnings' => $totalWarnings,
            'summary' => json_encode(['warnings' => $totalWarnings, 'academic_year' => $fallbackYear, 'term' => $fallbackTerm], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $importId = (int) $pdo->lastInsertId();

        $balanceLocks = [];
        foreach ($rows as $row) {
            $key = implode('|', [(int) $row['student']['id'], $row['data']['year'], $row['data']['term']]);
            $balanceLocks[$key] = [
                'student_id' => (int) $row['student']['id'], 'year' => $row['data']['year'], 'term' => $row['data']['term'],
            ];
        }
        ksort($balanceLocks);
        $states = [];
        foreach ($balanceLocks as $key => $period) {
            $balanceQuery = $pdo->prepare(
                'SELECT * FROM fee_balances WHERE student_id = :student_id AND academic_year = :year AND term = :term' . $lockSuffix
            );
            $balanceQuery->execute($period);
            $balance = $balanceQuery->fetch();
            if (!$balance) {
                throw new RuntimeException('A saved fee balance is missing for an imported period. No payments were saved.');
            }
            $ledgerQuery = $pdo->prepare(
                "SELECT COALESCE(SUM(amount_paid), 0) FROM fees WHERE student_id = :student_id AND year = :year AND term = :term AND payment_status = 'Recorded'"
            );
            $ledgerQuery->execute($period);
            $ledgerPaid = (float) $ledgerQuery->fetchColumn();
            if ($ledgerPaid > (float) $balance['paid_amount'] + 0.01) {
                throw new RuntimeException('A fee balance does not reconcile with existing payment records. No payments were saved.');
            }
            $states[$key] = [
                'balance_id' => (int) $balance['id'], 'paid' => (float) $balance['paid_amount'],
                'balance' => (float) $balance['balance'], 'unledgered' => max(0, (float) $balance['paid_amount'] - $ledgerPaid),
            ];
        }

        $insertFee = $pdo->prepare(
            "INSERT INTO fees (student_id, receipt_no, amount_paid, mpesa_code, mpesa_reference_text, payment_method, term, year, balance_after_payment, payment_date, payment_status)
             VALUES (:student_id, :receipt_no, :amount_paid, :mpesa_code, :reference_text, :payment_method, :term, :year, :balance_after, :payment_date, 'Recorded')"
        );
        $updateBalance = $pdo->prepare('UPDATE fee_balances SET paid_amount = :paid, balance = :balance WHERE id = :id');
        $insertImportRow = $pdo->prepare(
            'INSERT INTO fee_payment_import_rows (import_id, source_row_number, source_registration_no, student_id, payment_id, outcome, message, row_data)
             VALUES (:import_id, :source_row, :source_regno, :student_id, :payment_id, \'IMPORTED\', :message, :row_data)'
        );

        foreach ($rows as $row) {
            $data = $row['data'];
            $studentId = (int) $row['student']['id'];
            $key = implode('|', [$studentId, $data['year'], $data['term']]);
            $state =& $states[$key];
            $receiptNo = $data['receipt_no'];
            $receiptSource = $receiptNo === '' ? 'generated_on_import' : 'supplied_historical_receipt';
            if ($receiptNo === '') {
                $receiptNo = fee_allocate_receipt_number($pdo, $issuedAt);
            } else {
                fee_import_preserve_receipt_sequence($pdo, $receiptNo);
            }

            $covered = min((float) $data['amount_paid'], (float) $state['unledgered']);
            $state['unledgered'] -= $covered;
            $additional = (float) $data['amount_paid'] - $covered;
            if ($additional > (float) $state['balance'] + 0.005) {
                throw new RuntimeException('A payment exceeds the locked fee balance. No payments were saved.');
            }
            $state['balance'] = max(0, (float) $state['balance'] - $additional);
            $state['paid'] += $additional;

            $insertFee->execute([
                'student_id' => $studentId, 'receipt_no' => $receiptNo, 'amount_paid' => $data['amount_paid'],
                'mpesa_code' => $data['mpesa_code'], 'reference_text' => null,
                'payment_method' => $data['payment_method'], 'term' => $data['term'], 'year' => $data['year'],
                'balance_after' => $state['balance'], 'payment_date' => $data['payment_date'],
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            $updateBalance->execute(['paid' => $state['paid'], 'balance' => $state['balance'], 'id' => $state['balance_id']]);

            $message = array_merge($row['warnings'], $receiptSource === 'generated_on_import' ? ['Receipt number generated at import time; no historical receipt number was supplied.'] : []);
            fee_log_payment_audit($pdo, $paymentId, $studentId, $receiptNo, 'BULK_IMPORTED', $actorId, null, [
                'import_id' => $importId, 'source_row' => $row['source_row'], 'source_filename' => basename($sourceFilename),
                'source_registration_no' => $row['source_registration_no'],
                'matched_registration_no' => $row['student']['registration_no'], 'receipt_source' => $receiptSource,
                'balance_already_credited' => round($covered, 2), 'balance_adjustment' => round($additional, 2),
                'payment_method' => $data['payment_method'], 'mpesa_verified' => false,
            ]);
            $insertImportRow->execute([
                'import_id' => $importId, 'source_row' => $row['source_row'], 'source_regno' => $row['source_registration_no'],
                'student_id' => $studentId, 'payment_id' => $paymentId,
                'message' => $message ? implode(' ', $message) : null,
                'row_data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            unset($state);
        }

        $summary = [
            'total_rows' => count($rows), 'imported_rows' => count($rows), 'skipped_rows' => 0,
            'rejected_rows' => 0, 'error_count' => 0, 'duplicate_warnings' => $totalWarnings,
        ];
        $updateBatch = $pdo->prepare(
            'UPDATE fee_payment_imports SET imported_rows = :imported, summary = :summary WHERE id = :id'
        );
        $updateBatch->execute(['imported' => count($rows), 'summary' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'id' => $importId]);
        $pdo->commit();
        return ['import_id' => $importId] + $summary;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        foreach ($referenceLocks as $referenceLock) {
            fee_release_payment_reference_lock($pdo, $referenceLock);
        }
    }
}
