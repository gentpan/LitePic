<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;
use PDO;

/**
 * Read/write the `users` table — multi-user accounts.
 *
 * The administrator (id 1) is seeded by migration 019 and keeps using the
 * legacy master-password auth; regular users authenticate with username +
 * password (bcrypt) or a bound OAuth account. Password hashes for OAuth-only
 * users stay empty — empty hash never matches password_verify().
 */
final class UserRepository
{
    private const ALL_COLUMNS = 'id, username, email, display_name, password_hash, role, status, quota_bytes, created_at, last_login_at';

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ALL_COLUMNS . ' FROM users WHERE id = :i'
        );
        $stmt->execute([':i' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ALL_COLUMNS . ' FROM users WHERE username = :u COLLATE NOCASE'
        );
        $stmt->execute([':u' => trim($username)]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function findByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') return null;
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ALL_COLUMNS . ' FROM users WHERE email = :e COLLATE NOCASE'
        );
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function create(array $data): int
    {
        $username = trim((string)($data['username'] ?? ''));
        if ($username === '') {
            throw new \InvalidArgumentException('UserRepository::create() requires `username`.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (username, email, display_name, password_hash, role, status, quota_bytes, created_at)
             VALUES (:u, :e, :d, :p, :r, :s, :q, :t)'
        );
        $stmt->execute([
            ':u' => $username,
            ':e' => ($email = trim((string)($data['email'] ?? ''))) !== '' ? $email : null,
            ':d' => trim((string)($data['display_name'] ?? '')),
            ':p' => (string)($data['password_hash'] ?? ''),
            ':r' => ($data['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
            ':s' => ($data['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
            ':q' => max(0, (int)($data['quota_bytes'] ?? 0)),
            ':t' => time(),
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $assignments = [];
        $params = [':id' => $id];

        if (array_key_exists('email', $data)) {
            $email = trim((string)$data['email']);
            $assignments[] = 'email = :email';
            $params[':email'] = $email !== '' ? $email : null;
        }
        if (array_key_exists('display_name', $data)) {
            $assignments[] = 'display_name = :display_name';
            $params[':display_name'] = trim((string)$data['display_name']);
        }
        if (array_key_exists('password_hash', $data)) {
            $assignments[] = 'password_hash = :password_hash';
            $params[':password_hash'] = (string)$data['password_hash'];
        }
        if (array_key_exists('role', $data)) {
            $assignments[] = 'role = :role';
            $params[':role'] = $data['role'] === 'admin' ? 'admin' : 'user';
        }
        if (array_key_exists('status', $data)) {
            $assignments[] = 'status = :status';
            $params[':status'] = $data['status'] === 'disabled' ? 'disabled' : 'active';
        }
        if (array_key_exists('quota_bytes', $data)) {
            $assignments[] = 'quota_bytes = :quota_bytes';
            $params[':quota_bytes'] = max(0, (int)$data['quota_bytes']);
        }
        if (array_key_exists('last_login_at', $data)) {
            $assignments[] = 'last_login_at = :last_login_at';
            $params[':last_login_at'] = (int)$data['last_login_at'];
        }

        if ($assignments === []) return;
        $sql = 'UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = :id';
        Database::connection()->prepare($sql)->execute($params);
    }

    /**
     * Delete a user. Content tables keep their rows (their user_id columns
     * have no FK), so callers should decide what to do with the user's
     * images first — the settings UI blocks deletion while content exists.
     */
    public function delete(int $id): bool
    {
        Database::connection()
            ->prepare('DELETE FROM user_oauth_accounts WHERE user_id = :i')
            ->execute([':i' => $id]);
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = :i');
        $stmt->execute([':i' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function countAll(): int
    {
        return (int)Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /**
     * Admin user-management list: every user plus live usage stats.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allWithUsage(): array
    {
        $sql = 'SELECT u.' . str_replace(', ', ', u.', self::ALL_COLUMNS) . ',
                       (SELECT COUNT(*) FROM images i WHERE i.user_id = u.id) AS image_count,
                       (SELECT COALESCE(SUM(i2.size), 0) FROM images i2 WHERE i2.user_id = u.id) AS used_bytes
                FROM users u
                ORDER BY u.id ASC';
        $rows = Database::connection()->query($sql)->fetchAll() ?: [];
        return array_map(static function (array $r): array {
            $row = self::cast($r);
            $row['image_count'] = (int)($r['image_count'] ?? 0);
            $row['used_bytes'] = (int)($r['used_bytes'] ?? 0);
            return $row;
        }, $rows);
    }

    /** Sum of image bytes the user currently owns. Used for quota checks. */
    public function usedBytes(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(size), 0) FROM images WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function imageCount(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM images WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * True when the user can store `additionalBytes` more. Quota 0 = unlimited.
     */
    public function hasQuotaFor(int $userId, int $additionalBytes): bool
    {
        $user = $this->find($userId);
        if ($user === null) return false;
        $quota = (int)$user['quota_bytes'];
        if ($quota <= 0) return true;
        return $this->usedBytes($userId) + max(0, $additionalBytes) <= $quota;
    }

    private static function cast(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'email' => $row['email'] !== null ? (string)$row['email'] : null,
            'display_name' => (string)($row['display_name'] ?? ''),
            'password_hash' => (string)($row['password_hash'] ?? ''),
            'role' => (string)$row['role'] === 'admin' ? 'admin' : 'user',
            'status' => (string)$row['status'] === 'disabled' ? 'disabled' : 'active',
            'quota_bytes' => (int)($row['quota_bytes'] ?? 0),
            'created_at' => (int)($row['created_at'] ?? 0),
            'last_login_at' => isset($row['last_login_at']) ? (int)$row['last_login_at'] : null,
        ];
    }
}
