<?php

declare(strict_types=1);

/**
 * VIS user management for the OakEngine installer.
 *
 * Reads/writes the runner's user file <target>/var/vis/users.json directly.
 * The file format matches Oak\Vis\Storage\JsonUserStorage:
 *
 *   {"users": [{"id": "...", "email": "...", "password": "<bcrypt>",
 *               "roles": ["ROLE_USER", ...], "createdAt": "<ISO-8601>"}]}
 *
 * Passwords are hashed with password_hash(..., PASSWORD_BCRYPT) which is the
 * same algorithm the runner (Symfony password hasher, default bcrypt) uses,
 * so the runner can authenticate these users without migration.
 */

const VIS_USERS_MIN_PASSWORD_LENGTH = 8;
const VIS_USERS_BASE_ROLES = ['ROLE_USER', 'ROLE_ADMIN'];

/**
 * @return string absolute path to the runner user file
 */
function resolveVisUsersFilePath(string $targetDir): string
{
    return rtrim($targetDir, '/').'/var/vis/users.json';
}

/**
 * @return list<array{id: string, email: string, password: string, roles: list<string>, createdAt: string}>
 */
function loadVisUsers(string $targetDir): array
{
    $path = resolveVisUsersFilePath($targetDir);
    if (!is_file($path)) {
        return [];
    }

    $content = @file_get_contents($path);
    if (false === $content || '' === trim($content)) {
        return [];
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded) || !is_array($decoded['users'] ?? null)) {
        return [];
    }

    $users = [];
    foreach ($decoded['users'] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $email = is_scalar($row['email'] ?? null) ? strtolower(trim((string) $row['email'])) : '';
        if ('' === $email) {
            continue;
        }

        /** @var list<string> $roles */
        $roles = [];
        foreach ((array) ($row['roles'] ?? []) as $role) {
            if (is_scalar($role) && '' !== trim((string) $role)) {
                $roles[] = trim((string) $role);
            }
        }

        $users[] = [
            'id' => is_scalar($row['id'] ?? null) ? (string) $row['id'] : generateVisUserId(),
            'email' => $email,
            'password' => is_scalar($row['password'] ?? null) ? (string) $row['password'] : '',
            'roles' => array_values(array_unique($roles)),
            'createdAt' => is_scalar($row['createdAt'] ?? null) ? (string) $row['createdAt'] : '',
        ];
    }

    return $users;
}

/**
 * @param list<array{id: string, email: string, password: string, roles: list<string>, createdAt: string}> $users
 */
function saveVisUsers(string $targetDir, array $users): bool
{
    $path = resolveVisUsersFilePath($targetDir);
    $directory = dirname($path);
    if (!is_dir($directory) && !\Oak\Engine\Installer\createDirectoryTree($directory, 0o755)) {
        return false;
    }

    $payload = ['users' => array_values($users)];
    $encoded = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    // Atomic write: temp file + rename, so a crash never leaves a broken file.
    $temporaryPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    if (false === @file_put_contents($temporaryPath, $encoded)) {
        return false;
    }

    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);

        return false;
    }
    @chmod($path, 0o664);

    return true;
}

/**
 * @return array{ok: bool, error: string}
 */
function createVisUser(string $targetDir, string $email, string $password, array $roles): array
{
    $email = strtolower(trim($email));
    if ('' === $email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'vis_users_error_invalid_email'];
    }

    $passwordError = validateVisUserPassword($password);
    if ('' !== $passwordError) {
        return ['ok' => false, 'error' => $passwordError];
    }

    $users = loadVisUsers($targetDir);
    foreach ($users as $user) {
        if (0 === strcasecmp($user['email'], $email)) {
            return ['ok' => false, 'error' => 'vis_users_error_email_exists'];
        }
    }

    $users[] = [
        'id' => generateVisUserId(),
        'email' => $email,
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'roles' => normalizeVisUserRoles($roles),
        'createdAt' => date('Y-m-d\TH:i:sP'),
    ];

    $saved = saveVisUsers($targetDir, $users);

    return ['ok' => $saved, 'error' => $saved ? '' : 'vis_users_error_save_failed'];
}

/**
 * @return array{ok: bool, error: string}
 */
function updateVisUserPassword(string $targetDir, string $userId, string $password): array
{
    $passwordError = validateVisUserPassword($password);
    if ('' !== $passwordError) {
        return ['ok' => false, 'error' => $passwordError];
    }

    $users = loadVisUsers($targetDir);
    foreach ($users as $index => $user) {
        if ($user['id'] === $userId) {
            $users[$index]['password'] = password_hash($password, PASSWORD_BCRYPT);

            return ['ok' => saveVisUsers($targetDir, $users), 'error' => ''];
        }
    }

    return ['ok' => false, 'error' => 'vis_users_error_not_found'];
}

/**
 * @param list<string> $roles
 *
 * @return array{ok: bool, error: string}
 */
function updateVisUserRoles(string $targetDir, string $userId, array $roles): array
{
    $users = loadVisUsers($targetDir);
    $wasAdmin = false;
    foreach ($users as $user) {
        if ($user['id'] === $userId) {
            $wasAdmin = in_array('ROLE_ADMIN', $user['roles'], true);
        }
    }

    $normalizedRoles = normalizeVisUserRoles($roles);
    if ($wasAdmin && !in_array('ROLE_ADMIN', $normalizedRoles, true) && 1 === countVisAdminUsers($users) && visUserExists($users, $userId)) {
        return ['ok' => false, 'error' => 'vis_users_error_last_admin'];
    }

    foreach ($users as $index => $user) {
        if ($user['id'] === $userId) {
            $users[$index]['roles'] = $normalizedRoles;

            return ['ok' => saveVisUsers($targetDir, $users), 'error' => ''];
        }
    }

    return ['ok' => false, 'error' => 'vis_users_error_not_found'];
}

/**
 * @return array{ok: bool, error: string}
 */
function deleteVisUser(string $targetDir, string $userId): array
{
    $users = loadVisUsers($targetDir);
    $targetIsAdmin = false;
    foreach ($users as $user) {
        if ($user['id'] === $userId) {
            $targetIsAdmin = in_array('ROLE_ADMIN', $user['roles'], true);
        }
    }

    if ($targetIsAdmin && 1 === countVisAdminUsers($users)) {
        return ['ok' => false, 'error' => 'vis_users_error_last_admin'];
    }

    $remaining = array_values(array_filter(
        $users,
        static fn (array $user): bool => $user['id'] !== $userId,
    ));
    if (count($remaining) === count($users)) {
        return ['ok' => false, 'error' => 'vis_users_error_not_found'];
    }

    return ['ok' => saveVisUsers($targetDir, $remaining), 'error' => ''];
}

/**
 * Writes the initial VIS admin during a fresh installation. An existing
 * users file is never overwritten (update-safety).
 *
 * @return array{ok: bool, error: string, skipped: bool}
 */
function seedInitialVisAdmin(string $targetDir, string $email, string $password): array
{
    if (is_file(resolveVisUsersFilePath($targetDir))) {
        return ['ok' => true, 'error' => '', 'skipped' => true];
    }

    $result = createVisUser($targetDir, $email, $password, ['ROLE_USER', 'ROLE_ADMIN']);

    return ['ok' => $result['ok'], 'error' => $result['error'], 'skipped' => false];
}

/**
 * @param list<array{id: string, email: string, password: string, roles: list<string>, createdAt: string}> $users
 */
function countVisAdminUsers(array $users): int
{
    return count(array_filter(
        $users,
        static fn (array $user): bool => in_array('ROLE_ADMIN', $user['roles'], true),
    ));
}

/**
 * @param list<array{id: string, email: string, password: string, roles: list<string>, createdAt: string}> $users
 */
function visUserExists(array $users, string $userId): bool
{
    foreach ($users as $user) {
        if ($user['id'] === $userId) {
            return true;
        }
    }

    return false;
}

/**
 * ROLE_USER is always included; unknown roles are dropped; duplicates removed.
 *
 * @param list<string> $roles
 *
 * @return list<string>
 */
function normalizeVisUserRoles(array $roles): array
{
    $normalized = [];
    foreach ($roles as $role) {
        if (!is_scalar($role)) {
            continue;
        }
        $role = trim((string) $role);
        if ('' === $role) {
            continue;
        }
        $normalized[] = $role;
    }

    return array_values(array_unique(array_merge(['ROLE_USER'], $normalized)));
}

/**
 * All roles known in this installation: base roles + everything existing
 * users carry (page/section roles like ROLE_PAGE_HOME, ROLE_SECTION_*).
 *
 * @param list<array{id: string, email: string, password: string, roles: list<string>, createdAt: string}> $users
 *
 * @return list<string>
 */
function collectVisRoles(array $users): array
{
    $roles = VIS_USERS_BASE_ROLES;
    foreach ($users as $user) {
        foreach ($user['roles'] as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
    }

    return $roles;
}

function validateVisUserPassword(string $password): string
{
    return strlen($password) < VIS_USERS_MIN_PASSWORD_LENGTH ? 'vis_users_error_password_short' : '';
}

/**
 * UUID v7 (time-ordered), same id family the runner generates.
 */
function generateVisUserId(): string
{
    $milliseconds = (int) floor(microtime(true) * 1000);
    $random = random_bytes(10);

    $timeBytes = '';
    for ($shift = 40; $shift >= 0; $shift -= 8) {
        $timeBytes .= chr(($milliseconds >> $shift) & 0xFF);
    }

    $bytes = $timeBytes.$random;
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // version 7
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12),
    );
}
