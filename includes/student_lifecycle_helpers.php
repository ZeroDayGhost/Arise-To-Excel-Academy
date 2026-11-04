<?php

require_once __DIR__ . '/parent_registration_helpers.php';

function student_enrollment_statuses(): array
{
    return ['Active', 'Transferred Out', 'Graduated', 'Withdrawn'];
}

function student_status_is_active(?string $status): bool
{
    return $status === 'Active';
}

function find_existing_student_identity(PDO $pdo, string $fullName, string $guardianPhone, int $excludeStudentId = 0): ?array
{
    $normalizedName = parent_registration_normalize_name($fullName);
    $normalizedPhone = parent_registration_normalize_phone($guardianPhone);
    if ($normalizedName === '' || $normalizedPhone === null) {
        return null;
    }

    $sql = 'SELECT id, registration_no, full_name, guardian_phone, enrollment_status
            FROM students
            WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(:full_name))';
    $params = ['full_name' => $fullName];
    if ($excludeStudentId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeStudentId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if (parent_registration_normalize_phone((string) $candidate['guardian_phone']) === $normalizedPhone) {
            return $candidate;
        }
    }

    return null;
}
