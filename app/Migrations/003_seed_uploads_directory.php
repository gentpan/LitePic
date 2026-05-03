<?php
declare(strict_types=1);

/**
 * First-run scan: index every supported image already sitting under uploads/
 * into the `images` table. This keeps existing installs from "losing" their
 * library when they upgrade to the SQLite-backed version.
 *
 * Companion variants (.thumb.*, .webp, .avif) are detected per-file and
 * recorded as flags on the parent row rather than as separate entries.
 */

if (!function_exists('litepic_migration_has_original_sibling')) {
    function litepic_migration_has_original_sibling(string $path): bool
    {
        $base = preg_replace('/\.(webp|avif)$/i', '', $path);
        if (!is_string($base)) return false;
        foreach (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif'] as $candidate) {
            if (is_file($base . '.' . $candidate)) {
                return true;
            }
        }
        return false;
    }
}

return function (PDO $pdo): void {
    $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    $uploadsDir = $appRoot . '/uploads';
    if (!is_dir($uploadsDir)) {
        return;
    }

    $supportedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'svg', 'bmp', 'tiff', 'tif'];
    $extLookup = array_flip($supportedExts);

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO images
            (filename, ext, size, created_at, has_thumbnail, has_webp, has_avif)
         VALUES (:f, :e, :s, :c, :t, :w, :a)'
    );

    foreach ($iter as $info) {
        if (!$info->isFile()) continue;
        $name = $info->getFilename();

        // Skip companion files; they're tracked as flags on the parent.
        if (preg_match('/\.thumb\.[a-z0-9]+$/i', $name)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($extLookup[$ext])) continue;

        // .webp / .avif are companions when a sibling original exists.
        $abs = $info->getPathname();
        if (in_array($ext, ['webp', 'avif'], true) && litepic_migration_has_original_sibling($abs)) {
            continue;
        }

        $relative = ltrim(str_replace('\\', '/', substr($abs, strlen($uploadsDir))), '/');
        $thumbPath = $abs . '.thumb.' . $ext;
        // Common alternative thumbnail extensions
        $thumbAlt = preg_replace('/\.[a-z0-9]+$/i', '.thumb.jpg', $abs);

        $stmt->execute([
            ':f' => $relative,
            ':e' => $ext,
            ':s' => (int)$info->getSize(),
            ':c' => (int)$info->getMTime(),
            ':t' => (is_file($thumbPath) || ($thumbAlt !== null && is_file($thumbAlt))) ? 1 : 0,
            ':w' => is_file($abs . '.webp') ? 1 : 0,
            ':a' => is_file($abs . '.avif') ? 1 : 0,
        ]);
    }
};
