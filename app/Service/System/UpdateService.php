<?php
declare(strict_types=1);

namespace LitePic\Service\System;

use LitePic\Core\Migration;
use RuntimeException;
use Throwable;
use ZipArchive;

final class UpdateService
{
    private const REPO = 'gentpan/LitePic';

    /** 独立版本源 —— 由 litepic.io 落地页构建时生成,避免直打 GitHub API 的 60/h 限速。
     *  GitHub Release API 仅作兜底。 */
    private const VERSION_URL = 'https://litepic.io/version.json';

    /** 版本信息缓存时长(秒)—— 自动检测频繁,缓存避免每次都走网络。 */
    private const VERSION_CACHE_TTL = 21600; // 6h

    /** @var string[] */
    private const MANAGED_DIRS = [
        'api',
        'app',
        'assets',
        'static/favicon',
    ];

    /** @var string[] */
    private const MANAGED_FILES = [
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
        'package-lock.json',
        'package.json',
        'worker.php',
        'static/logo.png',
        'static/logo-dark.png',
    ];

    /**
     * Documentation only — paths the updater MUST NOT touch. The actual
     * protection comes from the REPLACEABLE_PATHS whitelist above (we
     * replace nothing outside that list). This const is kept as a
     * human-readable reminder; nothing reads it at runtime.
     *
     * Note: 'uploads' here represents the physical storage directory.
     * Admins can rename it to "files" / "images" / etc. via STORAGE_DIR
     * — whatever the runtime value is, it's still protected because
     * it's not in REPLACEABLE_PATHS.
     *
     * @var string[]
     */
    private const PROTECTED_PATHS = [
        '.env',
        '.user.ini',
        'data',
        'uploads', // see STORAGE_DIR — actual dir name is configurable
        'logs',
        'tmp',
        'static/images',
    ];

    /** @var string[] Small state files worth snapshotting; large protected dirs are never touched by updates. */
    private const CRITICAL_BACKUP_PATHS = [
        '.env',
        '.user.ini',
        'data/litepic.sqlite',
        'data/litepic.sqlite-wal',
        'data/litepic.sqlite-shm',
        'data/watermarks',
        'static/images',
    ];

    public function currentVersion(): string
    {
        return defined('LITEPIC_VERSION') ? (string)LITEPIC_VERSION : (defined('SITE_VERSION') ? (string)SITE_VERSION : '0.0.0');
    }

    /**
     * @return array{current:string,latest:?string,has_update:bool,release?:array<string,mixed>,message?:string}
     */
    public function check(): array
    {
        $current = $this->currentVersion();
        $release = $this->latestRelease();
        $latest = $this->normalizeVersion((string)($release['tag_name'] ?? ''));

        $cmp = $latest !== '' ? version_compare($latest, $this->normalizeVersion($current)) : 0;

        return [
            'current' => $current,
            'latest' => $latest !== '' ? $latest : null,
            'has_update' => $latest !== '' && $cmp > 0,
            'current_ahead' => $latest !== '' && $cmp < 0,
            'release' => [
                'tag_name' => (string)($release['tag_name'] ?? ''),
                'name' => (string)($release['name'] ?? ''),
                'html_url' => (string)($release['html_url'] ?? ''),
                'published_at' => (string)($release['published_at'] ?? ''),
                'body' => mb_substr((string)($release['body'] ?? ''), 0, 1200),
            ],
        ];
    }

    /**
     * @return array{current:string,latest:string,updated:bool,backup:?string,migrations:array<int,string>,message:string}
     */
    public function installLatest(): array
    {
        $check = $this->check();
        if (empty($check['latest'])) {
            throw new RuntimeException('无法获取最新版本信息');
        }
        if (empty($check['has_update'])) {
            return [
                'current' => $check['current'],
                'latest' => (string)$check['latest'],
                'updated' => false,
                'backup' => null,
                'migrations' => [],
                'message' => '当前已经是最新版本',
            ];
        }

        $release = $this->latestRelease();
        $downloadUrl = $this->releaseZipUrl($release);
        $tag = (string)($release['tag_name'] ?? $check['latest']);
        $workDir = $this->workDir('litepic-update-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)));
        $zipPath = $workDir . '/release.zip';
        $extractDir = $workDir . '/extract';
        $backupPath = null;

        $this->ensureRuntime();
        $this->mkdir($extractDir);

        try {
            $this->download($downloadUrl, $zipPath);
            $this->assertSafeZip($zipPath);
            $this->extract($zipPath, $extractDir);
            $sourceRoot = $this->findSourceRoot($extractDir);

            $backupPath = $this->backupManagedFiles($tag);
            $this->writeMaintenance($tag);
            $this->syncManagedFiles($sourceRoot);

            $migrations = (new Migration(APP_ROOT . '/app/Migrations'))->run();
            $this->clearMaintenance();
            $this->removeDir($workDir);

            return [
                'current' => $check['current'],
                'latest' => $this->normalizeVersion($tag),
                'updated' => true,
                'backup' => $backupPath,
                'migrations' => $migrations,
                'message' => '更新完成',
            ];
        } catch (Throwable $e) {
            $this->clearMaintenance();
            if (is_string($backupPath) && $backupPath !== '') {
                try {
                    $this->restoreBackup($backupPath);
                } catch (Throwable) {
                    // Keep the original update error; the backup path is returned in the log.
                }
            }
            throw new RuntimeException('更新失败：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function latestRelease(): array
    {
        $cached = $this->readVersionCache();
        if ($cached !== null) {
            return $cached;
        }
        $release = $this->fetchVersionInfo();
        $this->writeVersionCache($release);
        return $release;
    }

    /**
     * 优先查独立源 litepic.io/version.json(无 GitHub 限速),失败再兜底 GitHub Release API。
     * @return array<string,mixed>
     */
    private function fetchVersionInfo(): array
    {
        try {
            $json = $this->httpGet(self::VERSION_URL);
            $data = json_decode($json, true);
            if (is_array($data) && !empty($data['tag_name'])) {
                return [
                    'tag_name'     => (string)$data['tag_name'],
                    'name'         => (string)($data['name'] ?? $data['tag_name']),
                    'html_url'     => (string)($data['html_url'] ?? ''),
                    'published_at' => (string)($data['published_at'] ?? ''),
                    'body'         => (string)($data['body'] ?? ''),
                    'zip_url'      => (string)($data['zip_url'] ?? ''),
                    'source'       => 'litepic.io',
                ];
            }
        } catch (Throwable $_) {
            // 独立源不可用 → 落到 GitHub 兜底
        }

        $json = $this->httpGet('https://api.github.com/repos/' . self::REPO . '/releases/latest');
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['tag_name'])) {
            throw new RuntimeException('无法获取版本信息(独立源与 GitHub 均失败)');
        }
        $data['source'] = 'github';
        return $data;
    }

    private function versionCacheFile(): string
    {
        return APP_ROOT . '/data/update-cache/version.json';
    }

    /** @return array<string,mixed>|null */
    private function readVersionCache(): ?array
    {
        $f = $this->versionCacheFile();
        if (!is_file($f) || (time() - (int)@filemtime($f)) > self::VERSION_CACHE_TTL) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($f), true);
        return (is_array($data) && !empty($data['tag_name'])) ? $data : null;
    }

    /** @param array<string,mixed> $release */
    private function writeVersionCache(array $release): void
    {
        $dir = APP_ROOT . '/data/update-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->versionCacheFile(), json_encode($release, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string,mixed> $release
     */
    private function releaseZipUrl(array $release): string
    {
        // 独立源(version.json)直接给了 zip_url
        $direct = (string)($release['zip_url'] ?? '');
        if ($direct !== '') {
            return $direct;
        }
        foreach (($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $name = (string)($asset['name'] ?? '');
            $url = (string)($asset['browser_download_url'] ?? '');
            if ($url !== '' && preg_match('/litepic.*\.zip$/i', $name)) {
                return $url;
            }
        }

        $zipball = (string)($release['zipball_url'] ?? '');
        if ($zipball === '') {
            throw new RuntimeException('Release 未提供 ZIP 下载地址');
        }
        return $zipball;
    }

    private function ensureRuntime(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('当前 PHP 未启用 ZipArchive，无法在线解压更新包');
        }
        foreach ([APP_ROOT . '/data', APP_ROOT . '/data/update-cache', APP_ROOT . '/data/update-backups'] as $dir) {
            $this->mkdir($dir);
            if (!is_writable($dir)) {
                throw new RuntimeException($dir . ' 不可写');
            }
        }
        if (!is_writable(APP_ROOT)) {
            throw new RuntimeException('站点根目录不可写，无法替换程序文件');
        }
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('无法初始化 curl');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'LitePic-Updater/' . $this->currentVersion(),
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            if (!is_string($body) || $body === '' || $code >= 400) {
                throw new RuntimeException('请求更新源失败：HTTP ' . $code . ($err !== '' ? '，' . $err : ''));
            }
            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: LitePic-Updater/" . $this->currentVersion() . "\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 60,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('请求更新源失败，请检查 allow_url_fopen 或服务器网络');
        }
        return $body;
    }

    private function download(string $url, string $target): void
    {
        $body = $this->httpGet($url);
        if (strlen($body) < 1024) {
            throw new RuntimeException('下载到的更新包异常');
        }
        if (@file_put_contents($target, $body) === false) {
            throw new RuntimeException('写入更新包失败');
        }
    }

    private function assertSafeZip(string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('无法打开更新 ZIP');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '../') || str_starts_with($name, '/')) {
                $zip->close();
                throw new RuntimeException('更新包包含不安全路径');
            }
        }
        $zip->close();
    }

    private function extract(string $zipPath, string $targetDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('无法打开更新 ZIP');
        }
        if (!$zip->extractTo($targetDir)) {
            $zip->close();
            throw new RuntimeException('解压更新 ZIP 失败');
        }
        $zip->close();
    }

    private function findSourceRoot(string $extractDir): string
    {
        if (is_file($extractDir . '/bootstrap.php') && is_dir($extractDir . '/app')) {
            return $extractDir;
        }
        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $candidate = $extractDir . '/' . $entry;
            if (is_dir($candidate) && is_file($candidate . '/bootstrap.php') && is_dir($candidate . '/app')) {
                return $candidate;
            }
        }
        throw new RuntimeException('更新包不像 LitePic 程序包：缺少 bootstrap.php / app/');
    }

    private function backupManagedFiles(string $tag): string
    {
        $name = 'litepic-before-' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $tag) . '-' . date('Ymd-His') . '.zip';
        $path = APP_ROOT . '/data/update-backups/' . $name;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('创建更新前备份失败');
        }

        foreach (array_merge(self::MANAGED_DIRS, self::MANAGED_FILES, self::CRITICAL_BACKUP_PATHS) as $rel) {
            $abs = APP_ROOT . '/' . $rel;
            if (!file_exists($abs)) continue;
            $this->addToZip($zip, $abs, $rel);
        }
        $zip->close();
        return $path;
    }

    private function restoreBackup(string $zipPath): void
    {
        $this->assertSafeZip($zipPath);
        $this->extract($zipPath, APP_ROOT);
    }

    private function syncManagedFiles(string $sourceRoot): void
    {
        foreach (self::MANAGED_DIRS as $rel) {
            $src = $sourceRoot . '/' . $rel;
            $dst = APP_ROOT . '/' . $rel;
            if (!is_dir($src)) continue;
            $this->copyDir($src, $dst);
        }

        foreach (self::MANAGED_FILES as $rel) {
            $src = $sourceRoot . '/' . $rel;
            $dst = APP_ROOT . '/' . $rel;
            if (!is_file($src)) continue;
            $this->mkdir(dirname($dst));
            if (!@copy($src, $dst)) {
                throw new RuntimeException('复制文件失败：' . $rel);
            }
        }
    }

    private function writeMaintenance(string $tag): void
    {
        @file_put_contents(APP_ROOT . '/.maintenance', json_encode([
            'type' => 'update',
            'target' => $tag,
            'started_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function clearMaintenance(): void
    {
        if (is_file(APP_ROOT . '/.maintenance')) {
            @unlink(APP_ROOT . '/.maintenance');
        }
    }

    private function workDir(string $name): string
    {
        $dir = APP_ROOT . '/data/update-cache/' . $name;
        $this->mkdir($dir);
        return $dir;
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('创建目录失败：' . $dir);
        }
    }

    private function copyDir(string $src, string $dst): void
    {
        $this->mkdir($dst);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . '/' . str_replace('\\', '/', $rel);
            if ($item->isDir()) {
                $this->mkdir($target);
            } else {
                $this->mkdir(dirname($target));
                if (!@copy($item->getPathname(), $target)) {
                    throw new RuntimeException('复制文件失败：' . $rel);
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!file_exists($dir)) return;
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function addToZip(ZipArchive $zip, string $abs, string $rel): void
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if (is_file($abs)) {
            $zip->addFile($abs, $rel);
            return;
        }
        if (!is_dir($abs)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $itemRel = $rel . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($abs) + 1));
            if ($item->isDir()) {
                $zip->addEmptyDir($itemRel);
            } else {
                $zip->addFile($item->getPathname(), $itemRel);
            }
        }
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), "vV \t\n\r\0\x0B");
    }
}
