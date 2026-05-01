<?php
declare(strict_types=1);

namespace LitePic\Service\Cleanup;

use LitePic\Core\Database;
use LitePic\Service\Image\PathService;
use PDO;

/**
 * Conservative residual-data cleaner — only deletes records we can prove
 * are no longer referenced anywhere. Each category is opt-in (the UI
 * sends a checkbox list); scan() reports counts without deleting.
 *
 * Categories (intentionally narrow — when in doubt, don't delete):
 *
 *   missing_files       images table rows whose filename doesn't exist
 *                       on disk under uploads/. These are stale DB rows
 *                       left behind by manual `rm` operations on the
 *                       filesystem. NEVER deletes any disk file.
 *
 *   done_queue          import_queue rows with status='done' older than
 *                       7 days. The processed image is already linked
 *                       in the images table; queue row is bookkeeping.
 *
 *   failed_queue        import_queue rows with status='failed' AND
 *                       attempts >= 3 AND older than 30 days. The
 *                       multi-condition gate avoids dropping freshly
 *                       failed items the user might still retry.
 *
 *   expired_attempts    login_attempts rows where the lockout has long
 *                       passed (last_failure_at older than 24h AND
 *                       blocked_until either null or in the past).
 *
 *   expired_challenges  webauthn_challenges past their expires_at.
 *                       These are one-shot tokens, safe to drop.
 *
 * NOT touched: images rows with files present (real content), active
 * queue items (pending/in_progress), settings, tokens, passkey
 * credentials, compression API keys.
 */
final class OrphanCleaner
{
    public const CATEGORIES = [
        'missing_files',
        'done_queue',
        'failed_queue',
        'expired_attempts',
        'expired_challenges',
    ];

    private const DONE_QUEUE_AGE_SECONDS    = 7  * 86400;
    private const FAILED_QUEUE_AGE_SECONDS  = 30 * 86400;
    private const FAILED_QUEUE_MIN_ATTEMPTS = 3;
    private const ATTEMPTS_AGE_SECONDS      = 86400;

    /**
     * Dry run — counts only, no DELETE. Returns same shape as clean()
     * but with a top-level 'dry_run' => true marker for the UI.
     *
     * @return array{
     *   dry_run: true,
     *   counts: array<string, int>,
     *   total: int,
     *   examples: array<string, array<int, string>>,
     * }
     */
    public function scan(): array
    {
        $counts   = [];
        $examples = [];

        $missing = $this->scanMissingFiles();
        $counts['missing_files']   = count($missing);
        $examples['missing_files'] = array_slice($missing, 0, 5);

        $counts['done_queue']        = $this->countDoneQueue();
        $counts['failed_queue']      = $this->countFailedQueue();
        $counts['expired_attempts']  = $this->countExpiredAttempts();
        $counts['expired_challenges'] = $this->countExpiredChallenges();

        return [
            'dry_run'  => true,
            'counts'   => $counts,
            'total'    => array_sum($counts),
            'examples' => $examples,
        ];
    }

    /**
     * Actually delete the rows in the requested categories. Each
     * category is independently transacted; a failure in one doesn't
     * roll back the others.
     *
     * @param string[] $categories Subset of self::CATEGORIES.
     * @return array{
     *   dry_run: false,
     *   deleted: array<string, int>,
     *   total: int,
     *   errors: array<string, string>,
     * }
     */
    public function clean(array $categories): array
    {
        $allowed = array_values(array_intersect(self::CATEGORIES, $categories));
        $deleted = array_fill_keys(self::CATEGORIES, 0);
        $errors  = [];

        foreach ($allowed as $cat) {
            try {
                $deleted[$cat] = match ($cat) {
                    'missing_files'      => $this->deleteMissingFiles(),
                    'done_queue'         => $this->deleteDoneQueue(),
                    'failed_queue'       => $this->deleteFailedQueue(),
                    'expired_attempts'   => $this->deleteExpiredAttempts(),
                    'expired_challenges' => $this->deleteExpiredChallenges(),
                };
            } catch (\Throwable $e) {
                $errors[$cat] = $e->getMessage();
            }
        }

        return [
            'dry_run' => false,
            'deleted' => $deleted,
            'total'   => array_sum($deleted),
            'errors'  => $errors,
        ];
    }

    // ============================================================
    // missing_files
    // ============================================================

    /**
     * Returns the filenames in the images table whose disk file doesn't
     * exist. Streams via a cursor so we don't blow up memory on big libs.
     *
     * @return string[]
     */
    private function scanMissingFiles(): array
    {
        $missing = [];
        $stmt = Database::connection()->query('SELECT filename FROM images');
        if ($stmt === false) {
            return $missing;
        }
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $filename = (string)($row['filename'] ?? '');
            if ($filename === '') {
                continue;
            }
            $diskPath = PathService::resolveFilePath($filename);
            if (!is_file($diskPath)) {
                $missing[] = $filename;
            }
        }
        return $missing;
    }

    private function deleteMissingFiles(): int
    {
        $missing = $this->scanMissingFiles();
        if (empty($missing)) {
            return 0;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM images WHERE filename = :f');
            $deleted = 0;
            foreach ($missing as $filename) {
                $stmt->execute([':f' => $filename]);
                $deleted += $stmt->rowCount();
            }
            $pdo->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ============================================================
    // done_queue / failed_queue
    // ============================================================

    private function countDoneQueue(): int
    {
        $cutoff = time() - self::DONE_QUEUE_AGE_SECONDS;
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM import_queue WHERE status = 'done' AND updated_at < :c"
        );
        $stmt->execute([':c' => $cutoff]);
        return (int)$stmt->fetchColumn();
    }

    private function deleteDoneQueue(): int
    {
        $cutoff = time() - self::DONE_QUEUE_AGE_SECONDS;
        $stmt = Database::connection()->prepare(
            "DELETE FROM import_queue WHERE status = 'done' AND updated_at < :c"
        );
        $stmt->execute([':c' => $cutoff]);
        return $stmt->rowCount();
    }

    private function countFailedQueue(): int
    {
        $cutoff = time() - self::FAILED_QUEUE_AGE_SECONDS;
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM import_queue
             WHERE status = 'failed' AND attempts >= :a AND updated_at < :c"
        );
        $stmt->execute([':a' => self::FAILED_QUEUE_MIN_ATTEMPTS, ':c' => $cutoff]);
        return (int)$stmt->fetchColumn();
    }

    private function deleteFailedQueue(): int
    {
        $cutoff = time() - self::FAILED_QUEUE_AGE_SECONDS;
        $stmt = Database::connection()->prepare(
            "DELETE FROM import_queue
             WHERE status = 'failed' AND attempts >= :a AND updated_at < :c"
        );
        $stmt->execute([':a' => self::FAILED_QUEUE_MIN_ATTEMPTS, ':c' => $cutoff]);
        return $stmt->rowCount();
    }

    // ============================================================
    // expired_attempts
    // ============================================================

    private function countExpiredAttempts(): int
    {
        $cutoff = time() - self::ATTEMPTS_AGE_SECONDS;
        $now = time();
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE last_failure_at < :c AND (blocked_until IS NULL OR blocked_until < :n)"
        );
        $stmt->execute([':c' => $cutoff, ':n' => $now]);
        return (int)$stmt->fetchColumn();
    }

    private function deleteExpiredAttempts(): int
    {
        $cutoff = time() - self::ATTEMPTS_AGE_SECONDS;
        $now = time();
        $stmt = Database::connection()->prepare(
            "DELETE FROM login_attempts
             WHERE last_failure_at < :c AND (blocked_until IS NULL OR blocked_until < :n)"
        );
        $stmt->execute([':c' => $cutoff, ':n' => $now]);
        return $stmt->rowCount();
    }

    // ============================================================
    // expired_challenges
    // ============================================================

    private function countExpiredChallenges(): int
    {
        // Table may not exist on older schemas (passkey migration #006).
        $now = time();
        try {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM webauthn_challenges WHERE expires_at < :n'
            );
            $stmt->execute([':n' => $now]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $_) {
            return 0;
        }
    }

    private function deleteExpiredChallenges(): int
    {
        $now = time();
        try {
            $stmt = Database::connection()->prepare(
                'DELETE FROM webauthn_challenges WHERE expires_at < :n'
            );
            $stmt->execute([':n' => $now]);
            return $stmt->rowCount();
        } catch (\Throwable $_) {
            return 0;
        }
    }
}
