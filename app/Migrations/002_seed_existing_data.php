<?php
declare(strict_types=1);

/**
 * One-time seed: pull existing data from the JSON files / .env that the
 * pre-SQLite version of LitePic used.
 *
 * - data/remote_delete_queue.json -> remote_delete_queue
 * - LITEPIC_COMPRESSION_API_KEYS  (base64-JSON in .env) -> compression_api_keys
 * - LITEPIC_MANAGED_API_TOKENS    (base64-JSON in .env) -> managed_api_tokens
 *
 * Idempotent in spirit (only runs once because migrations run once), but
 * also tolerant of empty/missing sources.
 */
return function (PDO $pdo): void {
    $config = \LitePic\Core\Config::class;
    $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

    // Remote delete queue
    $queueFile = $appRoot . '/data/remote_delete_queue.json';
    if (is_file($queueFile)) {
        $raw = @file_get_contents($queueFile);
        $queue = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($queue)) {
            $stmt = $pdo->prepare(
                'INSERT INTO remote_delete_queue
                    (object_key, available_at, attempts, last_error, created_at)
                 VALUES (:k, :a, :t, :e, :c)'
            );
            $now = time();
            foreach ($queue as $item) {
                if (!is_array($item)) continue;
                $key = trim((string)($item['object_key'] ?? ''));
                if ($key === '') continue;
                $stmt->execute([
                    ':k' => $key,
                    ':a' => (int)($item['available_at'] ?? $now),
                    ':t' => (int)($item['attempts'] ?? 0),
                    ':e' => isset($item['last_error']) ? (string)$item['last_error'] : null,
                    ':c' => (int)($item['created_at'] ?? $now),
                ]);
            }
        }
    }

    // Compression API keys (legacy storage: base64-JSON in .env)
    $compressionPayload = (string)$config::get('LITEPIC_COMPRESSION_API_KEYS', '');
    $compressionDecoded = $compressionPayload !== '' ? base64_decode($compressionPayload, true) : false;
    $compressionList = $compressionDecoded !== false ? json_decode($compressionDecoded, true) : null;
    if (is_array($compressionList)) {
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO compression_api_keys
                (id, name, api_key, enabled, usage_success, usage_failure,
                 last_status_code, last_error, last_used_at, created_at)
             VALUES (:id, :name, :key, :enabled, :ok, :fail, :code, :err, :used, :created)'
        );
        $now = time();
        foreach ($compressionList as $item) {
            if (!is_array($item)) continue;
            $apiKey = trim((string)($item['api_key'] ?? ''));
            if ($apiKey === '') continue;
            $id = (string)($item['id'] ?? bin2hex(random_bytes(8)));
            $usage = is_array($item['usage'] ?? null) ? $item['usage'] : [];
            $stmt->execute([
                ':id' => $id,
                ':name' => (string)($item['name'] ?? ''),
                ':key' => $apiKey,
                ':enabled' => !empty($item['enabled']) ? 1 : 0,
                ':ok' => (int)($usage['success'] ?? 0),
                ':fail' => (int)($usage['failure'] ?? 0),
                ':code' => isset($usage['last_status_code']) ? (int)$usage['last_status_code'] : null,
                ':err' => isset($usage['last_error']) ? (string)$usage['last_error'] : null,
                ':used' => isset($usage['last_used_at']) ? (int)$usage['last_used_at'] : null,
                ':created' => (int)($item['created_at'] ?? $now),
            ]);
        }
    }

    // Managed API tokens (legacy storage: base64-JSON in .env)
    $tokensPayload = (string)$config::get('LITEPIC_MANAGED_API_TOKENS', '');
    $tokensDecoded = $tokensPayload !== '' ? base64_decode($tokensPayload, true) : false;
    $tokensList = $tokensDecoded !== false ? json_decode($tokensDecoded, true) : null;
    if (is_array($tokensList)) {
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO managed_api_tokens
                (id, name, token_hash, created_at, last_used_at)
             VALUES (:id, :name, :hash, :created, :used)'
        );
        $now = time();
        foreach ($tokensList as $item) {
            if (!is_array($item)) continue;
            $hash = trim((string)($item['hash'] ?? $item['token_hash'] ?? ''));
            if ($hash === '') continue;
            $stmt->execute([
                ':id' => (string)($item['id'] ?? bin2hex(random_bytes(8))),
                ':name' => (string)($item['name'] ?? ''),
                ':hash' => $hash,
                ':created' => (int)($item['created_at'] ?? $now),
                ':used' => isset($item['last_used_at']) ? (int)$item['last_used_at'] : null,
            ]);
        }
    }
};
