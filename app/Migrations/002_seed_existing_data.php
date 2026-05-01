<?php
declare(strict_types=1);

/**
 * One-time seed: pull existing data from the JSON files / .env that the
 * pre-SQLite version of LitePic used.
 *
 *   data/remote_delete_queue.json     -> remote_delete_queue
 *   COMPRESSION_API_KEYS_JSON env     -> compression_api_keys (preferred source)
 *   data/compression_api_keys.json    -> compression_api_keys (fallback)
 *   MANAGED_API_TOKENS_JSON env       -> managed_api_tokens (preferred)
 *   data/api_tokens.json              -> managed_api_tokens (fallback)
 */
return function (PDO $pdo): void {
    $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

    $parseTs = static function ($value): ?int {
        if ($value === null || $value === '') return null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
        $ts = strtotime((string)$value);
        return $ts === false ? null : $ts;
    };

    $decode = static function (string $envValue, string $fallbackFile): array {
        $envValue = trim($envValue);
        if ($envValue !== '') {
            $decoded = json_decode($envValue, true);
            if (is_array($decoded)) return $decoded;
            $b64 = base64_decode($envValue, true);
            if ($b64 !== false) {
                $decoded = json_decode($b64, true);
                if (is_array($decoded)) return $decoded;
            }
        }
        if (is_file($fallbackFile)) {
            $raw = @file_get_contents($fallbackFile);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) return $decoded;
            }
        }
        return [];
    };

    // --- remote_delete_queue ---
    $queueFile = $appRoot . '/data/remote_delete_queue.json';
    if (is_file($queueFile)) {
        $raw = @file_get_contents($queueFile);
        $queue = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($queue)) {
            $stmt = $pdo->prepare(
                'INSERT INTO remote_delete_queue (object_key, available_at, attempts, last_error, created_at)
                 VALUES (:k, :a, :t, :e, :c)'
            );
            $now = time();
            foreach ($queue as $item) {
                if (!is_array($item)) continue;
                $key = trim((string)($item['object_key'] ?? ''));
                if ($key === '') continue;
                $stmt->execute([
                    ':k' => $key,
                    ':a' => (int)($item['due_at'] ?? $item['available_at'] ?? $now),
                    ':t' => (int)($item['attempts'] ?? 0),
                    ':e' => isset($item['last_error']) ? (string)$item['last_error'] : null,
                    ':c' => (int)($item['created_at'] ?? $now),
                ]);
            }
        }
    }

    // --- compression_api_keys ---
    $compressionList = $decode(
        (string)\LitePic\Core\Config::get('COMPRESSION_API_KEYS_JSON', ''),
        $appRoot . '/data/compression_api_keys.json'
    );
    if ($compressionList !== []) {
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
            $usage = is_array($item['usage'] ?? null) ? $item['usage'] : [];
            $stmt->execute([
                ':id' => (string)($item['id'] ?? bin2hex(random_bytes(8))),
                ':name' => (string)($item['name'] ?? ''),
                ':key' => $apiKey,
                ':enabled' => !empty($item['enabled']) || !array_key_exists('enabled', $item) ? 1 : 0,
                ':ok' => (int)($usage['success'] ?? 0),
                ':fail' => (int)($usage['failure'] ?? 0),
                ':code' => isset($usage['last_status_code']) ? (int)$usage['last_status_code'] : null,
                ':err' => isset($usage['last_error']) ? (string)$usage['last_error'] : null,
                ':used' => isset($usage['last_used_at']) ? (int)$usage['last_used_at'] : null,
                ':created' => $parseTs($item['created_at'] ?? null) ?? $now,
            ]);
        }
    }

    // --- managed_api_tokens ---
    $tokensList = $decode(
        (string)\LitePic\Core\Config::get('MANAGED_API_TOKENS_JSON', ''),
        $appRoot . '/data/api_tokens.json'
    );
    if ($tokensList !== []) {
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO managed_api_tokens
                (id, name, token_hash, created_at, last_used_at)
             VALUES (:id, :name, :hash, :created, :used)'
        );
        $now = time();
        foreach ($tokensList as $item) {
            if (!is_array($item)) continue;
            if (!empty($item['revoked_at'])) continue; // skip revoked tokens
            $hash = trim((string)($item['token_hash'] ?? $item['hash'] ?? ''));
            if ($hash === '') continue;
            $stmt->execute([
                ':id' => (string)($item['id'] ?? bin2hex(random_bytes(8))),
                ':name' => (string)($item['name'] ?? ''),
                ':hash' => $hash,
                ':created' => $parseTs($item['created_at'] ?? null) ?? $now,
                ':used' => $parseTs($item['last_used_at'] ?? null),
            ]);
        }
    }
};
