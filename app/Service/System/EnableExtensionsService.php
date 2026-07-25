<?php
declare(strict_types=1);

namespace LitePic\Service\System;

use LitePic\Service\Stats\ServerInfo;

/**
 * Starts {@see bin/enable-image-ext.sh} in the background via passwordless
 * sudo so the admin settings UI can one-click enable image extensions.
 *
 * The HTTP request returns immediately; the UI polls {@see status()}.
 *
 * One-time setup (root SSH):
 *   sudo ./bin/enable-image-ext.sh --install-sudoers
 */
final class EnableExtensionsService
{
    public function scriptPath(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        return $root . '/bin/enable-image-ext.sh';
    }

    private function cacheDir(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $dir = $root . '/data/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function logPath(): string
    {
        return $this->cacheDir() . '/enable-ext.log';
    }

    public function pidPath(): string
    {
        return $this->cacheDir() . '/enable-ext.pid';
    }

    /**
     * Whether `sudo -n <script> --help` succeeds (sudoers installed).
     */
    public function sudoReady(): bool
    {
        $script = $this->scriptPath();
        if (!is_file($script)) {
            return false;
        }
        if (!is_executable($script)) {
            @chmod($script, 0755);
        }
        if (!function_exists('exec')) {
            return false;
        }

        $cmd = 'sudo -n ' . escapeshellarg($script) . ' --help >/dev/null 2>&1';
        $code = 1;
        @exec($cmd, $out, $code);
        return $code === 0;
    }

    public function isRunning(): bool
    {
        $pidFile = $this->pidPath();
        if (!is_file($pidFile)) {
            return false;
        }
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid <= 1) {
            return false;
        }
        // /proc is the portable check on Linux without shelling out.
        return is_dir('/proc/' . $pid);
    }

    /**
     * Fire-and-forget: spawn sudo script in background, return immediately.
     *
     * @return array{
     *   ok:bool,
     *   started:bool,
     *   running:bool,
     *   sudo_ready:bool,
     *   log:string,
     *   message:string,
     *   capability: array{gd:bool,imagick:bool,avif:bool,webp:bool,heic:bool},
     *   enablement_hints: array<string,mixed>
     * }
     */
    public function start(?int $uploadMb = null): array
    {
        $script = $this->scriptPath();
        if (!is_file($script)) {
            return $this->payload(false, false, '找不到启用脚本：bin/enable-image-ext.sh', 404);
        }
        if (!is_executable($script)) {
            @chmod($script, 0755);
        }
        if (!$this->sudoReady()) {
            return $this->payload(
                false,
                false,
                '网页一键启用尚未授权。请先 SSH 执行一次：sudo ' . $script . ' --install-sudoers',
                503
            );
        }
        if ($this->isRunning()) {
            return $this->payload(true, true, '启用任务已在运行，请稍候…', 0);
        }

        if ($uploadMb === null && defined('MAX_FILE_SIZE')) {
            $uploadMb = max(1, (int)ceil(MAX_FILE_SIZE / 1048576));
        }
        $uploadMb = max(1, min(2048, (int)($uploadMb ?? 20)));

        $log = $this->logPath();
        $pidFile = $this->pidPath();
        @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] 后台启动 enable-image-ext.sh --web-run --upload {$uploadMb}M\n");
        @file_put_contents($pidFile, '');

        ServerInfo::clearCapabilityCache();

        // Detach completely so apt + restart can outlive this HTTP request.
        $cmd = sprintf(
            'nohup sudo -n %s --web-run --upload %s >>%s 2>&1 & echo $! >%s',
            escapeshellarg($script),
            escapeshellarg($uploadMb . 'M'),
            escapeshellarg($log),
            escapeshellarg($pidFile)
        );
        @exec($cmd);

        // Brief settle so pid file is written.
        usleep(150000);
        if (!$this->isRunning() && !is_file($log)) {
            return $this->payload(false, false, '无法启动后台启用任务', 500);
        }

        return $this->payload(
            true,
            true,
            '已在后台启动：安装扩展并重启服务。请稍候，页面会自动刷新状态。',
            0
        );
    }

    /**
     * Poll progress for the settings UI.
     *
     * @return array{
     *   ok:bool,
     *   started:bool,
     *   running:bool,
     *   finished:bool,
     *   sudo_ready:bool,
     *   log:string,
     *   message:string,
     *   capability: array{gd:bool,imagick:bool,avif:bool,webp:bool,heic:bool},
     *   enablement_hints: array<string,mixed>
     * }
     */
    public function status(): array
    {
        $running = $this->isRunning();
        $log = is_file($this->logPath())
            ? (string)@file_get_contents($this->logPath())
            : '';
        $finished = !$running && (
            str_contains($log, '==> 完成')
            || str_contains($log, 'WEB_RUN_DONE=1')
        );
        $failed = !$running && $log !== '' && !$finished && (
            str_contains($log, 'exit')
            || preg_match('/\berror\b|\bfailed\b|失败/i', $log) === 1
        );

        if ($finished || (!$running && $log !== '')) {
            ServerInfo::clearCapabilityCache();
        }

        $hints = (new ServerInfo())->enablementHints(
            defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : null
        );
        $hints['sudo_ready'] = $this->sudoReady();
        $cap = ServerInfo::compressionCapability();

        $message = '尚未开始';
        if ($running) {
            $message = '正在安装 / 配置中…';
        } elseif ($finished) {
            $message = !empty($hints['has_gaps'])
                ? '脚本已跑完，但仍有未开启项，请查看日志'
                : '已启用成功';
        } elseif ($failed) {
            $message = '启用可能失败，请查看日志';
        } elseif ($log !== '') {
            $message = '任务已结束';
        }

        return [
            'ok' => !$failed,
            'started' => $log !== '',
            'running' => $running,
            'finished' => $finished,
            'sudo_ready' => $this->sudoReady(),
            'log' => $log,
            'message' => $message,
            'capability' => $cap,
            'enablement_hints' => $hints,
            'exit_code' => $failed ? 1 : 0,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   started:bool,
     *   running:bool,
     *   finished?:bool,
     *   sudo_ready:bool,
     *   log:string,
     *   message:string,
     *   capability: array{gd:bool,imagick:bool,avif:bool,webp:bool,heic:bool},
     *   enablement_hints: array<string,mixed>,
     *   exit_code:int
     * }
     */
    private function payload(bool $ok, bool $started, string $message, int $exitCode): array
    {
        $hints = (new ServerInfo())->enablementHints(
            defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : null
        );
        $hints['sudo_ready'] = $this->sudoReady();
        return [
            'ok' => $ok,
            'started' => $started,
            'running' => $this->isRunning(),
            'finished' => false,
            'sudo_ready' => $this->sudoReady(),
            'log' => is_file($this->logPath()) ? (string)@file_get_contents($this->logPath()) : '',
            'message' => $message,
            'capability' => ServerInfo::compressionCapability(),
            'enablement_hints' => $hints,
            'exit_code' => $exitCode,
        ];
    }
}
