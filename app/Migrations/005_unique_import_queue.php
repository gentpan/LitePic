<?php
declare(strict_types=1);

/**
 * Make import_queue.filename UNIQUE so re-enqueueing the same file
 * coalesces (OR-merges flags) instead of inserting a duplicate task.
 */
return function (PDO $pdo): void {
    // Coalesce any duplicates first — keep the row with the highest id
    // (most recently enqueued), since that's what the legacy in-PHP merge
    // would have effectively done.
    $pdo->exec('CREATE TEMP TABLE _iq_keep AS
                SELECT filename, MAX(id) AS id
                FROM import_queue
                GROUP BY filename');
    $pdo->exec('DELETE FROM import_queue
                WHERE id NOT IN (SELECT id FROM _iq_keep)');
    $pdo->exec('DROP TABLE _iq_keep');

    $pdo->exec('CREATE UNIQUE INDEX idx_iq_filename ON import_queue(filename)');
};
