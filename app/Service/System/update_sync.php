<?php
declare(strict_types=1);

/**
 * Package-side update sync entrypoint.
 *
 * Must be a plain `return function(...)` (not a class) so installLatest() can
 * `require` this file from the downloaded ZIP and always run the NEW sync
 * policy — even when an older UpdateApply class is already autoloaded.
 *
 * @return callable(string,string):list<string>
 */
return static function (string $sourceRoot, string $appRoot): array {
    $sourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');
    $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
    if ($sourceRoot === '' || $appRoot === '' || !is_dir($sourceRoot) || !is_dir($appRoot)) {
        throw new RuntimeException('更新同步路径无效');
    }

    $managedDirs = [
        'api',
        'app',
        'assets',
        'static/favicon',
    ];
    $managedFiles = [
        '.env.example',
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'action.php',
        'bootstrap.php',
        'config.php',
        'favicon.ico',
        'footer.php',
        'header.php',
        'image.php',
        'index.php',
        'nginx-litepic.conf',
        'Caddyfile',
        '.user.ini.example',
        'package-lock.json',
        'package.json',
        'worker.php',
        'dav.php',
        'static/logo.png',
        'static/logo-dark.png',
    ];

    $mkdir = static function (string $dir): void {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('创建目录失败：' . $dir);
        }
    };

    $copyDir = static function (string $src, string $dst) use ($mkdir): void {
        $mkdir($dst);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $rel = str_replace('\\', '/', (string)substr($item->getPathname(), strlen($src) + 1));
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                $mkdir($target);
                continue;
            }
            $mkdir(dirname($target));
            if (!@copy($item->getPathname(), $target)) {
                throw new RuntimeException('复制文件失败：' . $rel);
            }
        }
    };

    $pruneDir = static function (string $src, string $dst, string $relPrefix): array {
        $removed = [];
        if (!is_dir($dst)) {
            return $removed;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dst, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $abs = str_replace('\\', '/', $item->getPathname());
            $rel = substr($abs, strlen(rtrim($dst, '/')) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            $rel = str_replace('\\', '/', $rel);
            $srcPath = $src . '/' . $rel;
            $publicRel = $relPrefix . '/' . $rel;

            if ($item->isDir()) {
                if (!is_dir($srcPath)) {
                    @rmdir($abs);
                }
                continue;
            }

            if (is_file($srcPath) || is_link($srcPath)) {
                continue;
            }

            if (!@unlink($abs)) {
                throw new RuntimeException('清理废弃文件失败：' . $publicRel);
            }
            $removed[] = $publicRel;
        }
        return $removed;
    };

    $removed = [];

    foreach ($managedDirs as $rel) {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        $src = $sourceRoot . '/' . $rel;
        $dst = $appRoot . '/' . $rel;
        if (!is_dir($src)) {
            continue;
        }
        $copyDir($src, $dst);
        $removed = array_merge($removed, $pruneDir($src, $dst, $rel));
    }

    foreach ($managedFiles as $rel) {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $src = $sourceRoot . '/' . $rel;
        $dst = $appRoot . '/' . $rel;
        if (is_file($src)) {
            $mkdir(dirname($dst));
            if (!@copy($src, $dst)) {
                throw new RuntimeException('复制文件失败：' . $rel);
            }
            continue;
        }
        if (is_file($dst) || is_link($dst)) {
            if (!@unlink($dst)) {
                throw new RuntimeException('清理废弃文件失败：' . $rel);
            }
            $removed[] = $rel;
        }
    }

    return array_values(array_unique($removed));
};
