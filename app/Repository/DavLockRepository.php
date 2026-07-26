<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;
use PDO;

/**
 * WebDAV write locks (`dav_locks`), the storage behind DAV class 2.
 *
 * Expiry is lazy: {@see self::purgeExpired()} runs at the start of every
 * WebDAV request rather than on a timer, so an abandoned lock cannot outlive
 * its timeout even on installs with no cron. There is no separate sweep job to
 * forget about.
 *
 * Lock scope follows RFC 4918 §6: a depth-0 lock covers exactly its path, a
 * depth-infinity lock (`depth = -1`) covers its whole subtree. Ancestor lookup
 * is done by enumerating the path's ancestors in PHP and matching them with an
 * `IN (…)` — the tree is at most a handful of segments deep, which makes that
 * cheaper and far easier to reason about than a `LIKE` prefix scan.
 */
final class DavLockRepository
{
    public function purgeExpired(): void
    {
        Database::connection()
            ->prepare('DELETE FROM dav_locks WHERE expires_at <= :now')
            ->execute([':now' => time()]);
    }

    /**
     * @return array{token:string,path:string,depth:int,scope:string,type:string,owner:string,timeout:int,created_at:int,expires_at:int}|null
     */
    public function findByToken(string $token): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT token, path, depth, scope, type, owner, timeout, created_at, expires_at
             FROM dav_locks WHERE token = :token AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([':token' => $token, ':now' => time()]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Every live lock that governs $path: one on the path itself, plus any
     * depth-infinity lock on an ancestor.
     *
     * @return array<int,array{token:string,path:string,depth:int,scope:string,type:string,owner:string,timeout:int,created_at:int,expires_at:int}>
     */
    public function findGoverning(string $path): array
    {
        $ancestors = self::ancestorsOf($path);
        $placeholders = implode(',', array_fill(0, count($ancestors), '?'));

        $sql = 'SELECT token, path, depth, scope, type, owner, timeout, created_at, expires_at
                FROM dav_locks
                WHERE expires_at > ?
                  AND ((path = ?) OR (depth = -1 AND path IN (' . $placeholders . ')))';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(array_merge([time(), $path], $ancestors));
        return array_map([self::class, 'hydrate'], $stmt->fetchAll() ?: []);
    }

    /**
     * Every live lock at or below $path. Needed before DELETE or MOVE of a
     * collection: RFC 4918 §9.6.1 requires the whole subtree to be unlocked (or
     * locked by the submitting client) before it can be removed.
     *
     * @return array<int,array{token:string,path:string,depth:int,scope:string,type:string,owner:string,timeout:int,created_at:int,expires_at:int}>
     */
    public function findInSubtree(string $path): array
    {
        $prefix = $path === '/' ? '/' : rtrim($path, '/') . '/';
        $stmt = Database::connection()->prepare(
            'SELECT token, path, depth, scope, type, owner, timeout, created_at, expires_at
             FROM dav_locks
             WHERE expires_at > :now AND (path = :exact OR path LIKE :prefix ESCAPE \'\\\')'
        );
        $stmt->execute([
            ':now' => time(),
            ':exact' => $path,
            ':prefix' => self::escapeLike($prefix) . '%',
        ]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll() ?: []);
    }

    public function create(
        string $token,
        string $path,
        int $depth,
        string $scope,
        string $type,
        string $owner,
        int $timeout
    ): void {
        $now = time();
        Database::connection()->prepare(
            'INSERT INTO dav_locks (token, path, depth, scope, type, owner, timeout, created_at, expires_at)
             VALUES (:token, :path, :depth, :scope, :type, :owner, :timeout, :created, :expires)'
        )->execute([
            ':token' => $token,
            ':path' => $path,
            ':depth' => $depth,
            ':scope' => $scope,
            ':type' => $type,
            ':owner' => $owner,
            ':timeout' => $timeout,
            ':created' => $now,
            ':expires' => $now + $timeout,
        ]);
    }

    public function refresh(string $token, int $timeout): void
    {
        Database::connection()->prepare(
            'UPDATE dav_locks SET timeout = :timeout, expires_at = :expires WHERE token = :token'
        )->execute([
            ':timeout' => $timeout,
            ':expires' => time() + $timeout,
            ':token' => $token,
        ]);
    }

    public function delete(string $token): void
    {
        Database::connection()->prepare('DELETE FROM dav_locks WHERE token = :token')->execute([':token' => $token]);
    }

    /**
     * Re-point locks after a MOVE, so a client that locked a file and then
     * renamed it keeps a valid lock instead of being told 423 on its own file.
     */
    public function moveSubtree(string $from, string $to): void
    {
        foreach ($this->findInSubtree($from) as $lock) {
            $suffix = substr($lock['path'], strlen(rtrim($from, '/')));
            Database::connection()
                ->prepare('UPDATE dav_locks SET path = :path WHERE token = :token')
                ->execute([':path' => rtrim($to, '/') . $suffix, ':token' => $lock['token']]);
        }
    }

    /** Drop every lock at or below a path. Used after a successful DELETE. */
    public function deleteSubtree(string $path): void
    {
        foreach ($this->findInSubtree($path) as $lock) {
            $this->delete($lock['token']);
        }
    }

    /**
     * Ancestors of a path, excluding the path itself, root first.
     *
     * @return string[]
     */
    private static function ancestorsOf(string $path): array
    {
        $ancestors = ['/'];
        $segments = trim($path, '/');
        if ($segments === '') {
            return $ancestors;
        }

        $current = '';
        $parts = explode('/', $segments);
        // Stop one short: the last element is the path itself, matched separately.
        array_pop($parts);
        foreach ($parts as $part) {
            $current .= '/' . $part;
            $ancestors[] = $current;
        }
        return $ancestors;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{token:string,path:string,depth:int,scope:string,type:string,owner:string,timeout:int,created_at:int,expires_at:int}
     */
    private static function hydrate(array $row): array
    {
        return [
            'token' => (string)$row['token'],
            'path' => (string)$row['path'],
            'depth' => (int)$row['depth'],
            'scope' => (string)$row['scope'],
            'type' => (string)$row['type'],
            'owner' => (string)$row['owner'],
            'timeout' => (int)$row['timeout'],
            'created_at' => (int)$row['created_at'],
            'expires_at' => (int)$row['expires_at'],
        ];
    }
}
