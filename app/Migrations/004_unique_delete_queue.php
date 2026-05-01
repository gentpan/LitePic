<?php
declare(strict_types=1);

/**
 * Make remote_delete_queue.object_key UNIQUE so we can use SQL UPSERT
 * for deduplication. Coalesces any pre-existing duplicates first
 * (keeps the row with the earliest available_at).
 */
return function (PDO $pdo): void {
    // Coalesce duplicates first.
    $pdo->exec('DELETE FROM remote_delete_queue
                WHERE id NOT IN (
                    SELECT MIN(id) FROM remote_delete_queue
                    GROUP BY object_key
                    HAVING MIN(available_at) = available_at
                )');
    // The HAVING clause above isn't quite the right shape; do a simpler pass:
    $pdo->exec('CREATE TEMP TABLE _dedup AS
                SELECT object_key, MIN(available_at) AS keep_at
                FROM remote_delete_queue
                GROUP BY object_key');
    $pdo->exec('DELETE FROM remote_delete_queue
                WHERE id NOT IN (
                    SELECT q.id FROM remote_delete_queue q
                    JOIN _dedup d ON d.object_key = q.object_key
                    WHERE q.available_at = d.keep_at
                    GROUP BY q.object_key
                )');
    $pdo->exec('DROP TABLE _dedup');

    $pdo->exec('CREATE UNIQUE INDEX idx_rdq_object_key ON remote_delete_queue(object_key)');
};
