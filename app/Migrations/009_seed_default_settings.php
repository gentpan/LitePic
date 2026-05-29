<?php
declare(strict_types=1);

/**
 * Seed the `settings` table with sensible defaults so LitePic can run
 * without a .env file on a fresh deploy.
 *
 * Every key that config.php reads via env_value/env_bool/env_csv is
 * pre-populated here. On first login the admin uses the default password
 * (12345678) and is prompted to change it.
 *
 * Uses INSERT OR IGNORE — existing installs are completely unaffected.
 */
return function (PDO $pdo): void {
    $now = time();

    // Admin credentials — default password pre-hashed so first login works
    // out of the box. The session_secret signs the cookie.
    $defaultPassword = '12345678';
    $passwordHash = password_hash($defaultPassword, PASSWORD_BCRYPT);
    $sessionSecret = bin2hex(random_bytes(32));

    $defaults = [
        // ---- Identity ----
        'SITE_NAME'                     => 'LitePic',
        'SITE_DESCRIPTION'              => '轻量级图床程序',

        // ---- Upload ----
        'MAX_FILE_SIZE_MB'              => '20',
        'UPLOAD_MAX_FILES'              => '100',
        'UPLOAD_MAX_CONCURRENT'         => '3',
        'UPLOAD_ALLOWED_TYPES'          => 'jpg,jpeg,png,gif,webp,avif,ico,svg,bmp,tiff,tif',
        'IMAGE_PROCESS_MAX_PIXELS'      => '100000000',

        // ---- Image processing ----
        'WEBP_QUALITY'                  => '80',
        'AVIF_QUALITY'                  => '80',
        'CONVERSION_ENGINE'             => 'auto',
        'AUTO_COMPRESS_ON_UPLOAD'       => 'false',
        'AUTO_CONVERT_WEBP_ON_UPLOAD'   => 'false',
        'AUTO_CONVERT_AVIF_ON_UPLOAD'   => 'false',
        'CONVERT_PREFERRED_FORMAT'      => 'webp',
        'KEEP_ORIGINAL_AFTER_PROCESS'   => 'false',
        'COMPRESSION_MODE'              => 'imagemagick',

        // ---- Watermark ----
        'WATERMARK_ENABLED'             => 'false',
        'WATERMARK_TYPE'                => 'text',
        'WATERMARK_TEXT'                => 'LitePic',
        'WATERMARK_POSITION'            => 'bottom-right',
        'WATERMARK_OPACITY'             => '100',
        'WATERMARK_FONT_SIZE'           => '18',
        'WATERMARK_MARGIN'              => '18',
        'WATERMARK_COLOR'               => '#ffffff',
        'WATERMARK_FONT_PATH'           => '',
        'WATERMARK_IMAGE_PATH'          => '',
        'WATERMARK_IMAGE_WIDTH'         => '160',
        'WATERMARK_PANEL_ENABLED'       => 'true',
        'WATERMARK_PANEL_OPACITY'       => '34',
        'WATERMARK_PANEL_PADDING'       => '10',
        'WATERMARK_PANEL_RADIUS'        => '10',

        // ---- Hotlink protection ----
        'HOTLINK_PROTECTION_ENABLED'    => 'false',
        'HOTLINK_ALLOWED_DOMAINS'       => '',
        'HOTLINK_ALLOW_EMPTY_REFERER'   => 'true',

        // ---- Image serving ----
        'IMAGE_VIEW_COUNTER_ENABLED'    => 'true',
        'URL_PREFIX'                    => '/uploads/',
        // 物理存储目录名（默认 uploads）。改成 files/images/storage 等任意名字
        // 都行，但磁盘上的目录要同步重命名，否则会找不到文件。详见 config.php
        // 中的 STORAGE_DIR 定义。
        'STORAGE_DIR'                   => 'uploads',

        // ---- Remote storage (S3/R2) ----
        'REMOTE_STORAGE_USAGE'          => 'backup',
        'S3_BUCKET'                     => '',
        'S3_REGION'                     => 'auto',
        'S3_ENDPOINT'                   => '',
        'S3_KEY'                        => '',
        'S3_SECRET'                     => '',
        'S3_PATH_PREFIX'                => 'uploads',
        'S3_PUBLIC_BASE_URL'            => '',
        'REMOTE_STORAGE_DELETE_DELAY_SECONDS' => '86400',

        // ---- Compression API ----
        'TINIFY_API_KEYS'               => '',

        // ---- CORS ----
        'CORS_ALLOWED_ORIGINS'          => '*',

        // ---- Debug ----
        'DEBUG'                         => 'false',

        // ---- Heartbeat scheduler ----
        'LITEPIC_HEARTBEAT_DISABLED'    => 'false',
        'LITEPIC_HEARTBEAT_INTERVAL_HOURS' => '24',

        // ---- DB backup ----
        'DB_BACKUP_ENABLED'             => 'false',
        'DB_BACKUP_INTERVAL_HOURS'      => '24',
        'DB_BACKUP_KEEP_COUNT'          => '7',
        'DB_BACKUP_TO_REMOTE'           => 'false',

        // ---- Auth ----
        'ADMIN_API_KEY'                 => $defaultPassword,
        'ADMIN_PASSWORD_HASH'           => $passwordHash,
        'ADMIN_SESSION_SECRET'          => $sessionSecret,
    ];

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (key, value, updated_at)
         VALUES (:k, :v, :t)'
    );

    foreach ($defaults as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value, ':t' => $now]);
    }
};
