<?php
declare(strict_types=1);

/**
 * Add `priority` (DESC ordering — higher = run sooner) and `worker_id`
 * (claim-and-process lock) columns to import_queue.
 *
 * Defaults preserve current behaviour:
 *   • priority=0 → matches the existing FIFO `ORDER BY id` ordering
 *     (rows tie-break by id, so older rows still go first within
 *     a priority bucket)
 *   • worker_id=NULL → unclaimed; nextBatch() can grab it
 *
 * Used by:
 *   • "立即压缩" / "重试失败" UI buttons (priority bump)
 *   • Future multi-worker setups where two parallel drains race for
 *     the same row — UPDATE ... SET worker_id=? WHERE worker_id IS NULL
 *     gives strict claim semantics.
 *
 * The current ImageProcessor doesn't yet use either column — this
 * migration just makes them available for the next iteration.
 */
return function (PDO $pdo): void {
    // SQLite: ALTER TABLE ADD COLUMN is supported and non-blocking
    $pdo->exec('ALTER TABLE import_queue ADD COLUMN priority INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE import_queue ADD COLUMN worker_id TEXT');

    // Replace the status-only index with a composite that matches the
    // future query shape `WHERE status='pending' AND worker_id IS NULL
    // ORDER BY priority DESC, id ASC`.
    $pdo->exec('DROP INDEX IF EXISTS idx_iq_status');
    $pdo->exec('CREATE INDEX idx_iq_dispatch ON import_queue(status, priority DESC, id ASC)');
};
