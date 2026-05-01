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
     * Pull just the imagick/gd/avif/webp capability flags off the
     * runtime metrics. Settings tabs use this for "should I show the
     * AVIF toggle?" gating.
     *
     * @return array{gd:bool,imagick:bool,avif:bool,webp:bool}
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
        if (function_exists('shell_exec')) {
            $boot = @shell_exec('sysctl -n kern.boottime 2>/dev/null');
            if (is_string($boot) && preg_match('/sec\s*=\s*(\d+)/', $boot, $m)) {
                return max(0, time() - (int)$m[1]);
            }
        }
        return null;
    }

    /**
     * @return array{type:string,label:string,raw:string,uses_htaccess:bool,uses_nginx_rules:bool,uses_caddyfile:bool}
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
            elseif (str_contains($lower, 'caddy')) { $type = 'caddy'; $label = 'Caddy'; }
            elseif (str_contains($lower, 'apache') || str_contains($lower, 'httpd')) { $type = 'apache'; $label = 'Apache'; }
            elseif (str_contains($lower, 'litespeed')) { $type = 'litespeed'; $label = 'LiteSpeed'; }
            elseif (str_contains($lower, 'php') && str_contains($lower, 'development')) {
                $type = 'php-built-in';
                $label = 'PHP 内置开发服务器';
            }
        }

        return [
            'type' => $type,
            'label' => $label,
            'raw' => $raw,
            'uses_htaccess' => in_array($type, ['apache', 'litespeed'], true),
            'uses_nginx_rules' => in_array($type, ['nginx', 'openresty'], true),
            'uses_caddyfile' => $type === 'caddy',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeMetrics(): array
    {
        $phpUploadLimit = function_exists('get_php_upload_limit_bytes') ? \LitePic\Service\Upload\UploadService::phpUploadLimitBytes() : 0;
        $configuredUploadLimit = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : 0;
        $uptimeSeconds = $this->uptimeSeconds();
        $availability24h = $uptimeSeconds !== null
            ? round((min($uptimeSeconds, 86400) / 86400) * 100, 2)
            : null;

        $memoryLimitBytes = function_exists('ini_size_to_bytes')
            ? \LitePic\Service\Upload\UploadService::iniSizeToBytes((string)ini_get('memory_limit'))
            : 0;
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
        $fmt = static fn (int $b) => function_exists('format_filesize') ? \LitePic\Core\Format::filesize($b) : ($b . ' B');

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
                'avif' => function_exists('imagecreatefromavif') && function_exists('imageavif'),
                'webp' => function_exists('imagewebp'),
            ],
        ];
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
                $meminfo = function_exists('shell_exec') ? @shell_exec('cat /proc/meminfo 2>/dev/null') : '';
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
        } elseif (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
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

    private static function cpuCores(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo) && $cpuinfo !== '') {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($count > 0) return $count;
        }
        if (function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (is_string($nproc) && ctype_digit(trim($nproc))) {
                return (int)trim($nproc);
            }
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (is_string($sysctl) && ctype_digit(trim($sysctl))) {
                return (int)trim($sysctl);
            }
        }
        return null;
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
}
