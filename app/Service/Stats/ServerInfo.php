<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

/**
 * Reports OS / web server / PHP runtime / hardware utilisation for the
 * settings dashboard.
 *
 * The detection paths defensively handle sandboxed environments
 * (open_basedir on shared hosts, BT-panel etc.) by trying each source
 * with `@file_get_contents` first and only falling back to
 * `shell_exec` when that's blocked. Never warns the user, just leaves
 * the field at its `null` / "unknown" default.
 */
final class ServerInfo
{
    /**
     * Pull just the imagick/gd/avif/webp/heic capability flags off the
     * runtime metrics. Settings tabs use this for "should I show the
     * AVIF toggle?" gating.
     *
     * @return array{gd:bool,imagick:bool,avif:bool,webp:bool,heic:bool}
     */
    public static function compressionCapability(): array
    {
        $metrics = (new self())->runtimeMetrics();
        $cap = is_array($metrics['capability'] ?? null) ? $metrics['capability'] : [];
        return [
            'gd' => !empty($cap['gd']),
            'imagick' => !empty($cap['imagick']),
            'avif' => !empty($cap['avif']),
            'webp' => !empty($cap['webp']),
            'heic' => !empty($cap['heic']),
        ];
    }

    /**
     * Build the open_basedir value that should appear in php.ini
     * to make the runtime-metrics paths readable on sandboxed hosts.
     * Reads any existing open_basedir from `$iniPath` (so we don't
     * shrink the user's allowlist) and unions with our minimum set.
     */
    public static function openBasedirValue(string $iniPath): string
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $paths = [
            rtrim($appRoot, '/') . '/',
            '/tmp/',
            '/proc/cpuinfo',
            '/proc/meminfo',
            '/proc/uptime',
            '/etc/os-release',
        ];

        if (is_file($iniPath)) {
            $lines = file($iniPath, FILE_IGNORE_NEW_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    if (!is_string($line) || !preg_match('/^\s*open_basedir\s*=\s*(.+)\s*$/', $line, $m)) {
                        continue;
                    }
                    foreach (explode(PATH_SEPARATOR, trim((string)$m[1])) as $path) {
                        $path = trim($path);
                        if ($path !== '') $paths[] = $path;
                    }
                    break;
                }
            }
        }

        $normalized = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') continue;
            if ($path === $appRoot) {
                $path = rtrim($appRoot, '/') . '/';
            }
            $normalized[$path] = true;
        }
        return implode(PATH_SEPARATOR, array_keys($normalized));
    }

    /**
     * @return array{id:string,name:string,version:string,pretty:string}
     */
    public function distro(): array
    {
        $fallback = self::distroFromKernel();

        $raw = @file_get_contents('/etc/os-release');
        if ((!is_string($raw) || $raw === '') && function_exists('shell_exec')) {
            $raw = @shell_exec('cat /etc/os-release 2>/dev/null');
        }
        if (!is_string($raw) || $raw === '') {
            return $fallback;
        }

        $kv = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq + 1));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $kv[$k] = $v;
        }

        $id = strtolower($kv['ID'] ?? $fallback['id']);
        $name = $kv['NAME'] ?? $fallback['name'];
        $version = $kv['VERSION_ID'] ?? '';

        // Strip the generic "GNU/Linux" suffix so "Debian GNU/Linux" reads as "Debian".
        $brand = trim((string)preg_replace('~\s*(GNU/)?Linux\s*$~i', '', $name));
        if ($brand === '') $brand = $name;
        $pretty = $version !== '' ? trim($brand . ' ' . $version) : $brand;

        return ['id' => $id, 'name' => $name, 'version' => $version, 'pretty' => $pretty];
    }

    public function uptimeSeconds(): ?int
    {
        // is_readable() honours open_basedir noisily; @file_get_contents is silent.
        $raw = @file_get_contents('/proc/uptime');
        if (is_string($raw) && preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $raw, $m)) {
            return max(0, (int)floor((float)$m[1]));
        }
        if (self::canShellExec()) {
            $boot = @shell_exec('sysctl -n kern.boottime 2>/dev/null');
            if (is_string($boot) && preg_match('/sec\s*=\s*(\d+)/', $boot, $m)) {
                return max(0, time() - (int)$m[1]);
            }
        }

        // 受限 PHP-FPM(BT 等)上回退到 CLI worker 写的 snapshot。
        // 由于 uptime 是单调递增的,我们用 (snapshot.uptime + 当前时间 - 抓取时间) 做一次外推,
        // 这样即使 worker 几分钟前才跑过,显示出来的 uptime 仍然合理近实时。
        $snap = self::readSnapshot();
        if (isset($snap['uptime_seconds']) && (int)$snap['uptime_seconds'] > 0) {
            $base    = (int)$snap['uptime_seconds'];
            $age     = max(0, time() - (int)($snap['captured_at'] ?? time()));
            return $base + $age;
        }
        return null;
    }

    /**
     * @return array{type:string,label:string,raw:string,uses_nginx_rules:bool}
     */
    public function webServer(?string $software = null): array
    {
        $raw = trim((string)($software ?? ($_SERVER['SERVER_SOFTWARE'] ?? '')));
        $lower = strtolower($raw);
        $type = 'unknown';
        $label = '未知服务器';

        if ($lower !== '') {
            if (str_contains($lower, 'openresty')) { $type = 'openresty'; $label = 'OpenResty'; }
            elseif (str_contains($lower, 'nginx')) { $type = 'nginx'; $label = 'Nginx'; }
        }

        return [
            'type' => $type,
            'label' => $label,
            'raw' => $raw,
            'uses_nginx_rules' => in_array($type, ['nginx', 'openresty'], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeMetrics(): array
    {
        $phpUploadLimit = \LitePic\Service\Upload\UploadService::phpUploadLimitBytes();
        $configuredUploadLimit = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : 0;
        $uptimeSeconds = $this->uptimeSeconds();
        $availability24h = $uptimeSeconds !== null
            ? round((min($uptimeSeconds, 86400) / 86400) * 100, 2)
            : null;

        $memoryLimitBytes = \LitePic\Service\Upload\UploadService::iniSizeToBytes((string)ini_get('memory_limit'));
        $memoryUsedBytes = (int)memory_get_usage(true);
        $memoryPeakBytes = (int)memory_get_peak_usage(true);

        [$systemMemTotal, $systemMemUsed] = self::systemMemory();

        if ($systemMemTotal > 0) {
            $memoryTotalBytes = $systemMemTotal;
            $memoryUsedDisplay = $systemMemUsed;
        } else {
            $memoryTotalBytes = $memoryLimitBytes;
            $memoryUsedDisplay = $memoryUsedBytes;
        }

        $diskRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $diskTotal = @disk_total_space($diskRoot);
        $diskFree = @disk_free_space($diskRoot);
        $diskTotalBytes = is_numeric($diskTotal) ? (int)$diskTotal : 0;
        $diskFreeBytes = is_numeric($diskFree) ? (int)$diskFree : 0;
        $diskUsedBytes = max(0, $diskTotalBytes - $diskFreeBytes);

        $loadAvg = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        $load1 = (is_array($loadAvg) && isset($loadAvg[0])) ? (float)$loadAvg[0] : null;
        $load5 = (is_array($loadAvg) && isset($loadAvg[1])) ? (float)$loadAvg[1] : null;
        $load15 = (is_array($loadAvg) && isset($loadAvg[2])) ? (float)$loadAvg[2] : null;

        $cpuCores = self::cpuCores();
        $uptimeText = self::uptimeText($uptimeSeconds);

        $memoryUsagePercent = $memoryTotalBytes > 0
            ? round(($memoryUsedDisplay / $memoryTotalBytes) * 100, 2)
            : 0.0;
        $diskUsagePercent = $diskTotalBytes > 0
            ? round(($diskUsedBytes / $diskTotalBytes) * 100, 2)
            : 0.0;

        $distro = $this->distro();
        $fmt = static fn (int $b) => \LitePic\Core\Format::filesize($b);

        return [
            'server_ip' => self::serverIp(),
            'os' => $distro['pretty'],
            'distro' => $distro,
            'web_server' => $this->webServer(),
            'php_version' => PHP_VERSION,
            'php_sapi' => (string)php_sapi_name(),
            'php_upload_limit_bytes' => $phpUploadLimit,
            'config_upload_limit_bytes' => $configuredUploadLimit,
            'php_upload_limit_text' => $fmt($phpUploadLimit),
            'config_upload_limit_text' => $fmt($configuredUploadLimit),
            'upload_limit_ok' => $phpUploadLimit >= $configuredUploadLimit,
            'uptime_text' => $uptimeText,
            'uptime_seconds' => $uptimeSeconds,
            'availability_24h_percent' => $availability24h,
            'cpu_cores' => $cpuCores,
            'cpu_load' => [
                'load_1' => $load1,
                'load_5' => $load5,
                'load_15' => $load15,
                'text' => ($load1 !== null && $load5 !== null && $load15 !== null)
                    ? sprintf('%.2f / %.2f / %.2f', $load1, $load5, $load15)
                    : '不可用',
            ],
            'memory' => [
                'limit_bytes' => $memoryTotalBytes,
                'used_bytes' => $memoryUsedDisplay,
                'peak_bytes' => $memoryPeakBytes,
                'usage_percent' => $memoryUsagePercent,
                'text' => $fmt($memoryUsedDisplay) . ' / ' . $fmt($memoryTotalBytes > 0 ? $memoryTotalBytes : $memoryUsedDisplay),
                'peak_text' => $fmt($memoryPeakBytes),
            ],
            'disk' => [
                'total_bytes' => $diskTotalBytes,
                'used_bytes' => $diskUsedBytes,
                'free_bytes' => $diskFreeBytes,
                'usage_percent' => $diskUsagePercent,
                'text' => $fmt($diskUsedBytes) . ' / ' . $fmt($diskTotalBytes > 0 ? $diskTotalBytes : $diskUsedBytes),
                'free_text' => $fmt($diskFreeBytes),
            ],
            'capability' => [
                'gd' => extension_loaded('gd'),
                'imagick' => extension_loaded('imagick'),
                'avif' => (function_exists('imagecreatefromavif') && function_exists('imageavif')) || self::imagickFormatSupported(['AVIF']),
                'webp' => function_exists('imagewebp') || self::imagickFormatSupported(['WEBP']),
                'heic' => self::imagickFormatSupported(['HEIC', 'HEIF']),
            ],
        ];
    }

    private static function imagickFormatSupported(array $formats): bool
    {
        if (!class_exists(\Imagick::class)) return false;
        try {
            $im = new \Imagick();
            foreach ($formats as $format) {
                if (!empty($im->queryFormats((string)$format))) {
                    $im->clear();
                    return true;
                }
            }
            $im->clear();
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /**
     * @return array{id:string,name:string,version:string,pretty:string}
     */
    private static function distroFromKernel(): array
    {
        $kernelVersion = (string)php_uname('v');
        $kernelRelease = (string)php_uname('r');
        $brands = [
            'debian' => 'Debian', 'ubuntu' => 'Ubuntu', 'fedora' => 'Fedora',
            'centos' => 'CentOS', 'rhel' => 'RHEL', 'redhat' => 'Red Hat',
            'suse' => 'SUSE', 'opensuse' => 'openSUSE',
            'arch' => 'Arch', 'alpine' => 'Alpine',
        ];
        $needle = $kernelVersion . ' ' . $kernelRelease;
        $detectedId = '';
        $detectedBrand = '';
        foreach ($brands as $key => $brand) {
            if (stripos($needle, $key) !== false) {
                $detectedId = $key;
                $detectedBrand = $brand;
                break;
            }
        }

        $version = '';
        if ($detectedId !== '' && preg_match(
            '/(?:' . preg_quote(substr($detectedId, 0, 3), '/') . ')(\d+)/i',
            $kernelRelease, $m
        )) {
            $version = $m[1];
        } elseif ($detectedId !== '' && preg_match(
            '/' . preg_quote($detectedId, '/') . '\D*(\d+(?:\.\d+)?)/i',
            $kernelVersion, $m
        )) {
            $version = $m[1];
        }

        if ($detectedBrand !== '') {
            return [
                'id' => $detectedId,
                'name' => $detectedBrand,
                'version' => $version,
                'pretty' => $version !== '' ? ($detectedBrand . ' ' . $version) : $detectedBrand,
            ];
        }
        return [
            'id' => strtolower(PHP_OS_FAMILY ?: 'unknown'),
            'name' => php_uname('s'),
            'version' => '',
            'pretty' => php_uname('s'),
        ];
    }

    /**
     * @return array{0:int,1:int} [total, used] in bytes; both 0 if unknown
     */
    private static function systemMemory(): array
    {
        if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'BSD') {
            $meminfo = @file_get_contents('/proc/meminfo');
            if (!is_string($meminfo) || $meminfo === '') {
                $meminfo = self::canShellExec() ? @shell_exec('cat /proc/meminfo 2>/dev/null') : '';
            }
            if (is_string($meminfo) && $meminfo !== '') {
                $memTotal = 0;
                $memAvailable = 0;
                if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                    $memTotal = (int)$m[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                    $memAvailable = (int)$m[1] * 1024;
                } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) {
                    $memAvailable = (int)$m[1] * 1024;
                }
                if ($memTotal > 0) {
                    return [$memTotal, max(0, $memTotal - $memAvailable)];
                }
            }

            // Restricted PHP-FPM(BT 等)上 /proc 读不到时,回退到 CLI worker
            // 写入的 snapshot。Snapshot 由 worker.php 每次启动时自动刷新。
            $snap = self::readSnapshot();
            if (isset($snap['mem_total']) && (int)$snap['mem_total'] > 0) {
                return [(int)$snap['mem_total'], (int)($snap['mem_used'] ?? 0)];
            }
        } elseif (PHP_OS_FAMILY === 'Darwin' && self::canShellExec()) {
            $hwMemsize = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
            $total = (is_string($hwMemsize) && is_numeric(trim($hwMemsize))) ? (int)trim($hwMemsize) : 0;

            $vmStat = @shell_exec('vm_stat 2>/dev/null');
            $used = 0;
            if (is_string($vmStat) && $vmStat !== '') {
                $pageSize = 4096;
                if (preg_match('/page size of (\d+) bytes/', $vmStat, $m)) {
                    $pageSize = (int)$m[1];
                }
                $pages = ['active' => 0, 'inactive' => 0, 'wired down' => 0, 'speculative' => 0];
                foreach ($pages as $key => &$val) {
                    if (preg_match('/Pages ' . preg_quote($key, '/') . ':\s+(\d+)\./', $vmStat, $m)) {
                        $val = (int)$m[1];
                    }
                }
                unset($val);
                $used = ($pages['active'] + $pages['inactive'] + $pages['wired down'] + $pages['speculative']) * $pageSize;
            }
            if ($total > 0) {
                return [$total, $used];
            }
        }
        return [0, 0];
    }

    /**
     * CPU core count — admin-facing.
     *
     * Reads from settings cache first (populated by any successful CLI or
     * unrestricted-FPM probe via {@see probeAndCacheCpuCoresIfMissing}).
     * If still empty, attempts a probe right now. On restricted shared
     * hosting (BT panel) all probe methods will fail in HTTP context, so
     * we fall back to **1** silently — the gauge stays meaningful, and
     * the next CLI worker run will refresh the cache to the real value.
     */
    private static function cpuCores(): int
    {
        $cached = (int)\LitePic\Core\Config::get('CPU_CORES_OVERRIDE', 0);
        if ($cached > 0) return $cached;

        // Try probing now (cheap, ~5 file reads max). If it succeeds it
        // also caches itself, so the next call is instant.
        $probed = self::probeCpuCores();
        if ($probed !== null) {
            self::rememberCpuCores($probed);
            return $probed;
        }

        // Final fallback — assume 1 core. The gauge keeps showing a sane
        // number; if the real value is e.g. 4, the percent will read 4×
        // higher than reality until a CLI worker run repopulates cache.
        return 1;
    }

    /**
     * Probe-and-cache entry point — called from worker.php / bootstrap CLI
     * branch. CLI typically has access to /proc/cpuinfo and shell_exec
     * even on hosts that lock down PHP-FPM (BT panel etc.), so a single
     * cron-fired worker run is enough to populate the cache forever.
     *
     * Public so worker.php can wire it as a one-liner. No-op if cache
     * already has a value, or if all probe methods fail.
     */
    public static function probeAndCacheCpuCoresIfMissing(): void
    {
        $cached = (int)\LitePic\Core\Config::get('CPU_CORES_OVERRIDE', 0);
        if ($cached > 0) return;

        $probed = self::probeCpuCores();
        if ($probed !== null) {
            self::rememberCpuCores($probed);
        }
    }

    /**
     * Snapshot dynamic server stats (memory used, uptime, load avg) into
     * `settings.SERVER_STATS_SNAPSHOT` as JSON. Same restricted-host
     * workaround as the CPU probe: CLI can read /proc/* even when HTTP
     * PHP-FPM can't, so any CLI worker run refreshes the snapshot, and
     * HTTP just reads the snapshot when its own probes fail.
     *
     * Designed to be called every time worker.php runs (cron entry).
     * Snapshot includes captured_at so the UI can show "数据更新于 N 分钟前".
     */
    public static function probeAndCacheServerStats(): void
    {
        $snapshot = ['captured_at' => time()];

        // Memory (Linux /proc/meminfo)
        $meminfo = @file_get_contents('/proc/meminfo');
        if (is_string($meminfo) && $meminfo !== '') {
            $memTotal = 0;
            $memAvail = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memTotal = (int)$m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memAvail = (int)$m[1] * 1024;
            } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memAvail = (int)$m[1] * 1024;
            }
            if ($memTotal > 0) {
                $snapshot['mem_total'] = $memTotal;
                $snapshot['mem_used']  = max(0, $memTotal - $memAvail);
            }
        }

        // Uptime (Linux /proc/uptime: "12345.67 9876.54")
        $uptime = @file_get_contents('/proc/uptime');
        if (is_string($uptime) && preg_match('/^(\d+)/', trim($uptime), $m)) {
            $snapshot['uptime_seconds'] = (int)$m[1];
        }

        // Load average (works in HTTP too via syscall, but cache anyway
        // so a stale snapshot at least has SOMETHING when the gauge first paints)
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if (is_array($load) && isset($load[0])) {
            $snapshot['load1']  = (float)$load[0];
            $snapshot['load5']  = (float)($load[1] ?? 0);
            $snapshot['load15'] = (float)($load[2] ?? 0);
        }

        // Always write — even an "empty-ish" snapshot is useful (it tells
        // the UI when the last probe ran). Skip only if NOTHING new beyond
        // captured_at (e.g. all reads failed → no point overwriting good
        // cache with empty cache).
        if (count($snapshot) <= 1) return;

        try {
            (new \LitePic\Repository\SettingsRepository())
                ->set('SERVER_STATS_SNAPSHOT', (string)json_encode($snapshot, JSON_UNESCAPED_UNICODE));
            \LitePic\Core\Config::warmSettings();
        } catch (\Throwable $_) { /* best-effort */ }
    }

    /**
     * Read the cached server-stats snapshot. Returns empty array if no cache.
     * @return array<string, mixed>
     */
    private static function readSnapshot(): array
    {
        $raw = (string)\LitePic\Core\Config::get('SERVER_STATS_SNAPSHOT', '');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Run every detection method we have. Returns the first success, or null
     * if all methods fail. Doesn't write anywhere — pure detection.
     */
    private static function probeCpuCores(): ?int
    {
        // /proc/cpuinfo — Linux, works when open_basedir whitelists /proc
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($count > 0) return $count;
        }

        // /sys/devices/system/cpu/online — alt path, format like "0-1" or "0-3,5"
        $online = @file_get_contents('/sys/devices/system/cpu/online');
        if (is_string($online) && trim($online) !== '') {
            $total = 0;
            foreach (explode(',', trim($online)) as $part) {
                if (preg_match('/^(\d+)(?:-(\d+))?$/', trim($part), $m)) {
                    $total += isset($m[2]) ? ((int)$m[2] - (int)$m[1] + 1) : 1;
                }
            }
            if ($total > 0) return $total;
        }

        // shell_exec fallbacks — disabled on BT, but works on many hosts in CLI
        if (self::canShellExec()) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (is_string($nproc) && ctype_digit(trim($nproc))) {
                return (int)trim($nproc);
            }
            $getconf = @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null');
            if (is_string($getconf) && ctype_digit(trim($getconf))) {
                return (int)trim($getconf);
            }
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (is_string($sysctl) && ctype_digit(trim($sysctl))) {
                return (int)trim($sysctl);
            }
        }

        // Windows env
        $envCores = (string)getenv('NUMBER_OF_PROCESSORS');
        if (ctype_digit($envCores) && (int)$envCores > 0) {
            return (int)$envCores;
        }

        // Swoole extension exposes it directly (rare but free if installed)
        if (function_exists('swoole_cpu_num')) {
            $n = (int)@swoole_cpu_num();
            if ($n > 0) return $n;
        }

        return null;
    }

    /**
     * Persist a detected core count into settings. Silent and idempotent —
     * doesn't overwrite an existing value, doesn't break a request on DB
     * trouble, and refreshes the in-memory Config cache so the same
     * request can read the new value immediately.
     */
    private static function rememberCpuCores(int $count): void
    {
        if ($count <= 0) return;
        try {
            $repo = new \LitePic\Repository\SettingsRepository();
            $existing = $repo->get('CPU_CORES_OVERRIDE');
            if ($existing === null || $existing === '') {
                $repo->set('CPU_CORES_OVERRIDE', (string)$count);
                \LitePic\Core\Config::warmSettings();
            }
        } catch (\Throwable $_) {
            // Ignore — caching is best-effort.
        }
    }

    /**
     * True iff `shell_exec` is callable AND not listed in disable_functions.
     * `function_exists()` alone returns false when disabled, but some
     * configurations leak the entry — belt-and-braces check.
     */
    private static function canShellExec(): bool
    {
        if (!function_exists('shell_exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('shell_exec', $disabled, true);
    }

    private static function uptimeText(?int $seconds): string
    {
        if ($seconds === null) {
            if (function_exists('shell_exec')) {
                $raw = @shell_exec('uptime 2>/dev/null');
                if (is_string($raw) && trim($raw) !== '') return trim($raw);
            }
            return '当前环境不支持读取';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $mins = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) $parts[] = $days . ' 天';
        if ($hours > 0) $parts[] = $hours . ' 小时';
        if ($mins > 0 || empty($parts)) $parts[] = $mins . ' 分钟';
        return implode(' ', $parts);
    }

    private static function serverIp(): string
    {
        // NAT/CDN 架构下优先返回公网IP (内网IP无意义), 带文件缓存避免频繁查询
        $cacheFile = sys_get_temp_dir() . '/litepic_public_ip.cache';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
            $cached = trim((string)file_get_contents($cacheFile));
            if ($cached !== '' && filter_var($cached, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $cached;
            }
        }
        // 查询公网IP (从云厂商元数据或外部服务)
        if (function_exists('shell_exec')) {
            foreach ([
                // 阿里云元数据
                'curl -s --max-time 3 http://100.100.100.200/latest/meta-data/eipv4 2>/dev/null',
                // 腾讯云元数据
                'curl -s --max-time 3 http://metadata.tencentyun.com/latest/meta-data/public-ipv4 2>/dev/null',
                // 通用公网IP服务
                'curl -s --max-time 3 https://api.ipify.org 2>/dev/null',
                'curl -s --max-time 3 https://ifconfig.me 2>/dev/null',
            ] as $cmd) {
                $ip = @shell_exec($cmd);
                if (is_string($ip)) {
                    $trimmed = trim($ip);
                    if ($trimmed !== '' && filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        @file_put_contents($cacheFile, $trimmed);
                        return $trimmed;
                    }
                }
            }
        }
        // 公网IP获取失败时, 回退到原来的内网IP逻辑
        if (function_exists('shell_exec')) {
            if (PHP_OS_FAMILY === 'Darwin') {
                foreach (['en0', 'en1', 'en2', 'en3'] as $iface) {
                    $ip = @shell_exec('ipconfig getifaddr ' . $iface . ' 2>/dev/null');
                    if (is_string($ip)) {
                        $trimmed = trim($ip);
                        if ($trimmed !== '' && $trimmed !== '127.0.0.1' && $trimmed !== '::1') {
                            return $trimmed;
                        }
                    }
                }
            }
            if (in_array(PHP_OS_FAMILY, ['Linux', 'BSD', 'Darwin'], true)) {
                $ip = @shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'");
                if (is_string($ip)) {
                    $trimmed = trim($ip);
                    if ($trimmed !== '' && $trimmed !== '127.0.0.1') return $trimmed;
                }
                $ip = @shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '/src/ {print \$7; exit}'");
                if (is_string($ip)) {
                    $trimmed = trim($ip);
                    if ($trimmed !== '' && $trimmed !== '127.0.0.1') return $trimmed;
                }
            }
        }
        $addr = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if ($addr !== '' && $addr !== '127.0.0.1' && $addr !== '::1' && $addr !== 'localhost') {
            return $addr;
        }
        $host = gethostbyname((string)gethostname());
        if ($host !== (string)gethostname() && $host !== '127.0.0.1') return $host;
        return $addr ?: '127.0.0.1';
    }

    /**
     * SQLite database snapshot for the settings system tab — file path,
     * size, schema version, table list with row counts. Read-only,
     * never writes; safe to call from any settings render.
     *
     * @return array{
     *   path:string, exists:bool, size_bytes:int, size_text:string,
     *   schema_version:?int, tables:array<int,array{name:string,rows:int}>,
     *   page_size_bytes:?int, journal_mode:?string,
     * }
     */
    public function databaseSummary(): array
    {
        // Per-process cache — settings page re-renders during a single
        // request would otherwise run COUNT(*) against every table on each
        // call. The per-table COUNT(*) on a 130k-row table (liveness_pings
        // on legacy installs) is a full scan; doing it 3× per request when
        // multiple panels share this data is wasteful.
        //
        // Invalidation: process-scoped, so a fresh request always gets
        // a fresh snapshot — settings UI never goes more than a few seconds
        // stale relative to actual table sizes.
        static $cached = null;
        if ($cached !== null) return $cached;

        $path = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/data/litepic.sqlite';
        $size = is_file($path) ? (int)@filesize($path) : 0;

        $tables = [];
        $version = null;
        $pageSize = null;
        $journalMode = null;

        try {
            $pdo = \LitePic\Core\Database::connection();

            // Per-table row count (skip sqlite internal tables)
            $rows = $pdo->query("SELECT name FROM sqlite_master
                                 WHERE type='table' AND name NOT LIKE 'sqlite_%'
                                 ORDER BY name")->fetchAll() ?: [];
            foreach ($rows as $r) {
                $name = (string)$r['name'];
                // Quote identifier defensively, even though our table names are static
                $count = (int)$pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"')
                                  ->fetchColumn();
                $tables[] = ['name' => $name, 'rows' => $count];
            }

            // Schema migrations max version
            $versionRow = $pdo->query("SELECT MAX(CAST(version AS INTEGER)) FROM schema_migrations")
                              ->fetchColumn();
            if ($versionRow !== false && $versionRow !== null) {
                $version = (int)$versionRow;
            }

            // PRAGMA introspection
            $pageSize = (int)$pdo->query('PRAGMA page_size')->fetchColumn() ?: null;
            $journalMode = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            // DB not initialised yet — return what we have
        }

        return $cached = [
            'path'             => $path,
            'exists'           => is_file($path),
            'size_bytes'       => $size,
            'size_text'        => \LitePic\Core\Format::filesize($size),
            'schema_version'   => $version,
            'tables'           => $tables,
            'page_size_bytes'  => $pageSize,
            'journal_mode'     => $journalMode,
        ];
    }
}
