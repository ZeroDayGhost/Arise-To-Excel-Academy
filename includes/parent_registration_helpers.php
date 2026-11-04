<?php

require_once __DIR__ . '/parent_portal_helpers.php';

function parent_registration_schema_ready(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('parent_profiles', 'parent_activation_tokens', 'student_registration_tokens')");
        return (int) $statement->fetchColumn() === 3;
    }

    $tables = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('parent_profiles', 'parent_activation_tokens', 'student_registration_tokens')"
    );
    if ((int) $tables->fetchColumn() !== 3) {
        return false;
    }
    $columns = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'parent_student_links'
           AND column_name IN ('relationship_type', 'is_primary')"
    );
    return (int) $columns->fetchColumn() === 2;
}

function parent_registration_normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || $digits === '') {
        return null;
    }
    if (str_starts_with($digits, '0')) {
        $digits = '254' . substr($digits, 1);
    } elseif (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
        $digits = '254' . $digits;
    }
    return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
}

function parent_registration_normalize_name(string $name): string
{
    $name = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
    return trim(preg_replace('/\s+/', ' ', (string) $name));
}

function parent_registration_phone_candidates(PDO $pdo, string $normalizedPhone): array
{
    $statement = $pdo->prepare(
        "SELECT p.parent_user_id, p.primary_phone, p.normalized_phone, p.identity_verified_at,
                a.name, a.username, a.status
         FROM parent_profiles p
         JOIN admin a ON a.id = p.parent_user_id
         WHERE p.normalized_phone = :phone
           AND EXISTS (
               SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
               WHERE ur.user_id = a.id AND COALESCE(r.role_name, r.name) = 'Parent'
           )
         ORDER BY a.name, a.id"
    );
    $statement->execute(['phone' => $normalizedPhone]);
    return $statement->fetchAll();
}

function parent_registration_accounts(PDO $pdo): array
{
    return $pdo->query(
        "SELECT a.id, a.name, a.username, a.status
         FROM admin a
         WHERE EXISTS (
             SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = a.id AND COALESCE(r.role_name, r.name) = 'Parent'
         )
         ORDER BY a.name, a.id"
    )->fetchAll();
}

function parent_registration_auto_match(array $candidates, string $parentName): ?int
{
    if (count($candidates) !== 1) {
        return null;
    }
    $candidate = $candidates[0];
    if (empty($candidate['identity_verified_at'])) {
        return null;
    }
    if (parent_registration_normalize_name((string) $candidate['name']) !== parent_registration_normalize_name($parentName)) {
        return null;
    }
    return (int) $candidate['parent_user_id'];
}

function parent_registration_issue_activation(PDO $pdo, int $actorId, int $parentUserId): string
{
    if (!parent_portal_is_parent_account($pdo, $parentUserId, false)) {
        throw new InvalidArgumentException('The selected account is not assigned only the Parent role.');
    }
    $account = $pdo->prepare('SELECT username, status FROM admin WHERE id = :id LIMIT 1');
    $account->execute(['id' => $parentUserId]);
    $row = $account->fetch();
    if (!$row || $row['status'] === 'Active') {
        throw new InvalidArgumentException('Only a pending parent account can receive an activation code.');
    }
    $profile = $pdo->prepare('SELECT activated_at FROM parent_profiles WHERE parent_user_id = :id LIMIT 1');
    $profile->execute(['id' => $parentUserId]);
    $activationState = $profile->fetch();
    if (!$activationState) {
        throw new InvalidArgumentException('This account has no pending parent profile. Use the existing account controls instead.');
    }
    if (!empty($activationState['activated_at'])) {
        throw new InvalidArgumentException('This account was already activated. Use the password reset or account re-enable controls instead.');
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE parent_activation_tokens SET used_at = CURRENT_TIMESTAMP WHERE parent_user_id = :id AND used_at IS NULL')
        ->execute(['id' => $parentUserId]);
    $insert = $pdo->prepare(
        'INSERT INTO parent_activation_tokens (parent_user_id, token_hash, expires_at, created_by)
         VALUES (:parent_id, :token_hash, :expires_at, :actor_id)'
    );
    $insert->execute([
        'parent_id' => $parentUserId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60),
        'actor_id' => $actorId > 0 ? $actorId : null,
    ]);
    parent_portal_audit($pdo, $actorId, $parentUserId, null, 'activation_issued');
    return $token;
}

function parent_registration_start_handoff(string $username, string $activationCode, string $parentName): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('A live session is required to prepare parent password setup.');
    }

    $staffSession = $_SESSION;
    if (empty($staffSession['admin_id'])) {
        throw new RuntimeException('A signed-in staff session is required to prepare parent password setup.');
    }

    // Keep staff authenticated data server-side, but expose only the limited setup state during activation.
    session_regenerate_id(true);
    $_SESSION = [
        '_csrf_token' => bin2hex(random_bytes(32)),
        'parent_activation_handoff' => [
            'username' => $username,
            'activation_code' => $activationCode,
            'parent_name' => $parentName,
            'expires_at' => time() + 20 * 60,
        ],
        'parent_admin_session_restore' => $staffSession,
        'parent_activation_return_to' => 'admin/parent_accounts.php',
    ];
}

function parent_registration_restore_staff_session(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $staffSession = $_SESSION['parent_admin_session_restore'] ?? null;
    if (!is_array($staffSession) || empty($staffSession['admin_id'])) {
        return null;
    }

    $returnTo = (string) ($_SESSION['parent_activation_return_to'] ?? '');
    $_SESSION = $staffSession;
    session_regenerate_id(true);
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

    return $returnTo === 'admin/parent_accounts.php' ? $returnTo : 'admin/parent_accounts.php';
}

function parent_registration_activate_account(PDO $pdo, string $username, string $token, string $password): void
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)
        || !preg_match('/^[a-f0-9]{64}$/i', $token)
        || strlen($password) < 12) {
        throw new InvalidArgumentException('The activation details are invalid.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $lookup = $pdo->prepare(
            "SELECT t.id AS token_id, t.parent_user_id
             FROM parent_activation_tokens t
             JOIN admin a ON a.id = t.parent_user_id
             WHERE t.token_hash = :token_hash
               AND t.used_at IS NULL AND t.expires_at > :now
             LIMIT 1" . $lock
        );
        $lookup->execute([
            'token_hash' => hash('sha256', strtolower($token)),
            'now' => date('Y-m-d H:i:s'),
        ]);
        $activation = $lookup->fetch();
        if (!$activation || !parent_portal_is_parent_account($pdo, (int) ($activation['parent_user_id'] ?? 0), false)) {
            throw new InvalidArgumentException('The activation code is invalid, expired, or already used. Contact the school for a new code.');
        }

        $parentId = (int) $activation['parent_user_id'];
        $usernameCheck = $pdo->prepare('SELECT id FROM admin WHERE LOWER(username) = LOWER(:username) AND id <> :parent_id LIMIT 1');
        $usernameCheck->execute(['username' => $username, 'parent_id' => $parentId]);
        if ($usernameCheck->fetchColumn() !== false) {
            throw new InvalidArgumentException('That username is already in use. Choose another one.');
        }

        $update = $pdo->prepare("UPDATE admin SET username = :username, password_hash = :password_hash, status = 'Active' WHERE id = :id AND status <> 'Active'");
        try {
            $update->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $parentId,
            ]);
        } catch (PDOException $exception) {
            $sqlState = (string) $exception->getCode();
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($sqlState === '23000' || $sqlState === '23505' || $driverCode === 19 || $driverCode === 1062) {
                throw new InvalidArgumentException('That username is already in use. Choose another one.');
            }
            throw $exception;
        }
        if ($update->rowCount() !== 1) {
            throw new InvalidArgumentException('This parent account is already active. Sign in or contact the school for assistance.');
        }
        $pdo->prepare('UPDATE parent_profiles SET activated_at = :activated_at WHERE parent_user_id = :parent_id')
            ->execute([
                'activated_at' => date('Y-m-d H:i:s'),
                'parent_id' => $parentId,
            ]);
        $markUsed = $pdo->prepare('UPDATE parent_activation_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id AND used_at IS NULL');
        $markUsed->execute(['id' => (int) $activation['token_id']]);
        if ($markUsed->rowCount() !== 1) {
            throw new InvalidArgumentException('This activation code has already been used. Contact the school for a new code.');
        }
        $audit = $pdo->prepare(
            "INSERT INTO parent_link_audit (actor_admin_id, parent_user_id, student_id, action, details)
             VALUES (NULL, :parent_id, NULL, 'account_activated', 'One-time activation code used')"
        );
        $audit->execute(['parent_id' => (int) $activation['parent_user_id']]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function parent_registration_resolve(
    PDO $pdo,
    int $actorId,
    array $details,
    int $selectedParentId,
    bool $identityConfirmed,
    bool $updateParentDetails,
    array $phoneCandidates,
    bool $preserveExistingLink = false
): array {
    $name = trim((string) ($details['parent_name'] ?? ''));
    $phone = trim((string) ($details['parent_phone'] ?? ''));
    $normalizedPhone = parent_registration_normalize_phone($phone);
    if ($name === '' || mb_strlen($name) > 100 || $normalizedPhone === null) {
        throw new InvalidArgumentException('Enter a parent name and a valid primary phone number.');
    }
    if (!filter_var((string) ($details['parent_email'] ?? ''), FILTER_VALIDATE_EMAIL)
        && trim((string) ($details['parent_email'] ?? '')) !== '') {
        throw new InvalidArgumentException('Enter a valid parent email address.');
    }

    $forceNew = $selectedParentId === -1;
    $autoParentId = parent_registration_auto_match($phoneCandidates, $name);
    if ($autoParentId === null && $identityConfirmed && count($phoneCandidates) === 1
        && parent_registration_normalize_name((string) $phoneCandidates[0]['name']) === parent_registration_normalize_name($name)) {
        $autoParentId = (int) $phoneCandidates[0]['parent_user_id'];
    }
    if (!$forceNew && $selectedParentId <= 0 && $autoParentId !== null) {
        $selectedParentId = $autoParentId;
    }
    if (!$forceNew && $selectedParentId <= 0 && $phoneCandidates !== []) {
        throw new InvalidArgumentException('Choose the matching parent account and confirm the guardian identity before saving.');
    }
    if ($forceNew && $phoneCandidates !== [] && !$identityConfirmed) {
        throw new InvalidArgumentException('Confirm in person that this is a different guardian before creating a separate account for a contact number already on file.');
    }

    $created = false;
    $activationToken = null;
    if ($forceNew || $selectedParentId <= 0) {
        ensure_permissions_exist($pdo);
        $roleId = find_or_create_role($pdo, 'Parent', 'Restricted parent portal access.');
        parent_portal_clear_role_grants($pdo, $roleId);
        $username = 'parent_' . bin2hex(random_bytes(6));
        while (permission_user_exists($pdo, $username)) {
            $username = 'parent_' . bin2hex(random_bytes(6));
        }
        $insert = $pdo->prepare(
            "INSERT INTO admin (name, username, password_hash, status, class_level)
             VALUES (:name, :username, :password_hash, 'Inactive', NULL)"
        );
        $insert->execute([
            'name' => $name,
            'username' => $username,
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ]);
        $selectedParentId = (int) $pdo->lastInsertId();
        set_user_role($pdo, $selectedParentId, $roleId);
        $created = true;
    } elseif (!parent_portal_is_parent_account($pdo, $selectedParentId, false)) {
        throw new InvalidArgumentException('Select a valid Parent account. Administrator and teacher accounts cannot be linked here.');
    }

    $profileStatement = $pdo->prepare('SELECT * FROM parent_profiles WHERE parent_user_id = :id LIMIT 1');
    $profileStatement->execute(['id' => $selectedParentId]);
    $profile = $profileStatement->fetch() ?: null;
    $accountStatement = $pdo->prepare('SELECT name, status, username FROM admin WHERE id = :id LIMIT 1');
    $accountStatement->execute(['id' => $selectedParentId]);
    $account = $accountStatement->fetch();
    if (!$account) {
        throw new InvalidArgumentException('The selected parent account could not be found.');
    }

    if (!$created) {
        $matchingVerifiedIdentity = $profile
            && !empty($profile['identity_verified_at'])
            && (string) $profile['normalized_phone'] === $normalizedPhone
            && parent_registration_normalize_name((string) $account['name']) === parent_registration_normalize_name($name);
        if (!$matchingVerifiedIdentity && !$identityConfirmed && !$preserveExistingLink) {
            throw new InvalidArgumentException('Confirm in person that the selected account belongs to this guardian before linking the child.');
        }
        $phoneChanged = $profile && (string) $profile['normalized_phone'] !== $normalizedPhone;
        $nameChanged = parent_registration_normalize_name((string) $account['name']) !== parent_registration_normalize_name($name);
        if (($phoneChanged || ($nameChanged && (!$preserveExistingLink || $profile))) && !$updateParentDetails && !$created) {
            if ($phoneChanged) {
                throw new InvalidArgumentException('This account already has different contact details. Confirm the identity and select the shared-contact update option, or choose another account.');
            }
            if ($nameChanged) {
                throw new InvalidArgumentException('The parent name differs from the selected account. Confirm the identity and select the shared-contact update option, or choose another account.');
            }
        }
        if (($phoneChanged || ($profile && $nameChanged)) && !$identityConfirmed && !$created) {
            throw new InvalidArgumentException("Confirm the parent's updated identity in person before changing shared contact details.");
        }
    }

    $verifiedAt = $identityConfirmed ? date('Y-m-d H:i:s') : null;
    if (!$profile) {
        $insertProfile = $pdo->prepare(
            'INSERT INTO parent_profiles
                (parent_user_id, primary_phone, normalized_phone, alternate_phone, email, address, identity_verified_at, identity_verified_by, created_by, updated_by)
             VALUES
                (:parent_id, :phone, :normalized_phone, :alternate_phone, :email, :address, :verified_at, :verified_by, :actor_id, :updated_by)'
        );
        $insertProfile->execute([
            'parent_id' => $selectedParentId,
            'phone' => $phone,
            'normalized_phone' => $normalizedPhone,
            'alternate_phone' => trim((string) ($details['alternate_phone'] ?? '')) ?: null,
            'email' => trim((string) ($details['parent_email'] ?? '')) ?: null,
            'address' => trim((string) ($details['parent_address'] ?? '')) ?: null,
            'verified_at' => $verifiedAt,
            'verified_by' => $identityConfirmed ? $actorId : null,
            'actor_id' => $actorId,
            'updated_by' => $actorId,
        ]);
        if ($updateParentDetails && parent_registration_normalize_name((string) $account['name']) !== parent_registration_normalize_name($name)) {
            $pdo->prepare('UPDATE admin SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $selectedParentId]);
        }
    } elseif ($updateParentDetails) {
        $update = $pdo->prepare(
            'UPDATE parent_profiles
             SET primary_phone = :phone, normalized_phone = :normalized_phone,
                 alternate_phone = :alternate_phone, email = :email, address = :address,
                 identity_verified_at = CASE WHEN :set_verified = 1 THEN :verified_at ELSE identity_verified_at END,
                 identity_verified_by = CASE WHEN :set_verified_by = 1 THEN :verified_by ELSE identity_verified_by END,
                 updated_by = :updated_by
             WHERE parent_user_id = :parent_id'
        );
        $update->execute([
            'phone' => $phone,
            'normalized_phone' => $normalizedPhone,
            'alternate_phone' => trim((string) ($details['alternate_phone'] ?? '')) ?: null,
            'email' => trim((string) ($details['parent_email'] ?? '')) ?: null,
            'address' => trim((string) ($details['parent_address'] ?? '')) ?: null,
            'set_verified' => $identityConfirmed ? 1 : 0,
            'verified_at' => $verifiedAt,
            'set_verified_by' => $identityConfirmed ? 1 : 0,
            'verified_by' => $identityConfirmed ? $actorId : null,
            'updated_by' => $actorId,
            'parent_id' => $selectedParentId,
        ]);
        if (parent_registration_normalize_name((string) $account['name']) !== parent_registration_normalize_name($name)) {
            $pdo->prepare('UPDATE admin SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $selectedParentId]);
        }
    } elseif ($identityConfirmed && empty($profile['identity_verified_at'])) {
        $pdo->prepare(
            'UPDATE parent_profiles SET identity_verified_at = :verified_at, identity_verified_by = :actor_id, updated_by = :updated_by WHERE parent_user_id = :parent_id'
        )->execute([
            'verified_at' => $verifiedAt,
            'actor_id' => $actorId,
            'updated_by' => $actorId,
            'parent_id' => $selectedParentId,
        ]);
    }

    if ($created) {
        parent_portal_audit($pdo, $actorId, $selectedParentId, null, 'account_created_pending_activation');
        $activationToken = parent_registration_issue_activation($pdo, $actorId, $selectedParentId);
    }

    return [
        'parent_user_id' => $selectedParentId,
        'created' => $created,
        'activation_token' => $activationToken,
        'username' => (string) $account['username'],
    ];
}
