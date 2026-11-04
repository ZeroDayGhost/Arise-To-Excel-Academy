<?php

function format_registration_no(int $sequence): string
{
    if ($sequence < 1) {
        throw new InvalidArgumentException('Admission number sequence must be positive.');
    }

    return 'ATX' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
}

function registration_no_exists(PDO $pdo, string $registrationNo): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM students WHERE registration_no = :registration_no');
    $statement->execute(['registration_no' => $registrationNo]);
    return (int) $statement->fetchColumn() > 0;
}

/** Allocates a number under the caller's transaction lock. */
function generate_registration_no(PDO $pdo): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Admission numbers must be allocated inside the student transaction.');
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $existingSequence = $pdo->query('SELECT next_number FROM student_admission_sequence WHERE singleton_id = 1')->fetchColumn();
    if ($existingSequence === false) {
        $existingStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
        if ($existingStudents > 0) {
            throw new RuntimeException('Admission number sequence is missing for existing students. Apply the student identity migration.');
        }

        if ($driver === 'mysql') {
            $pdo->exec('INSERT IGNORE INTO student_admission_sequence (singleton_id, next_number) VALUES (1, 1)');
        } elseif ($driver === 'sqlite') {
            $pdo->exec('INSERT OR IGNORE INTO student_admission_sequence (singleton_id, next_number) VALUES (1, 1)');
        } else {
            throw new RuntimeException('Admission number sequence initialization is unsupported for this database driver.');
        }
    }

    $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $statement = $pdo->query('SELECT next_number FROM student_admission_sequence WHERE singleton_id = 1' . $lockClause);
    $sequence = (int) $statement->fetchColumn();
    if ($sequence < 1) {
        throw new RuntimeException('Admission number sequence could not be initialized. Please try again.');
    }

    $update = $pdo->prepare('UPDATE student_admission_sequence SET next_number = :next_number WHERE singleton_id = 1');
    $update->execute(['next_number' => $sequence + 1]);

    return format_registration_no($sequence);
}
