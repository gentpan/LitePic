<?php
declare(strict_types=1);

namespace LitePic\Service\System;

use LitePic\Service\Stats\ServerInfo;

/**
 * Runs {@see bin/enable-image-ext.sh} via passwordless sudo so the admin
 * settings UI can one-click enable GD / Imagick / HEIC on Debian+FrankenPHP.
 *
 * Prerequisites (one-time, as root):
 *   sudo ./bin/enable-image-ext.sh --install-sudoers
 */
final class EnableExtensionsService
{
    public function scriptPath(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        return $root . '/bin/enable-image-ext.sh';
    }

    /**
     * Whether `sudo -n <script> --help` succeeds (sudoers installed).
     */
    public function sudoReady(): bool
    {
        $script = $this->scriptPath();
        if (!is_file($script) || !is_executable($script)) {
            return false;
        }
        if (!function_exists('exec') && !function_exists('proc_open')) {
            return false;
        }

        $cmd = 'sudo -n ' . escapeshellarg($script) . ' --help >/dev/null 2>&1';
        $code = 1;
        if (function_exists('exec')) {
            @exec($cmd, $out, $code);
        } else {
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $desc, $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
            }
        }
        return $code === 0;
    }

    /**
     * @return array{
     *   ok:bool,
     *   exit_code:int,
     *   output:string,
     *   sudo_ready:bool,
     *   restart_scheduled:bool,
     *   capability: array{gd:bool,imagick:bool,avif:bool,webp:bool,heic:bool},
     *   enablement_hints: array<string,mixed>,
     *   message:string
     * }
     */
    public function run(?int $uploadMb = null): array
    {
        $script = $this->scriptPath();
        if (!is_file($script)) {
            return $this->fail(404, '找不到启用脚本：bin/enable-image-ext.sh');
        }
        if (!is_executable($script)) {
            @chmod($script, 0755);
        }

        if (!$this->sudoReady()) {
            return $this->fail(
                503,
                '网页一键启用尚未授权。请先在服务器 SSH 执行一次：'
                . ' sudo ' . $script . ' --install-sudoers'
            );
        }

        $args = [$script, '--web-run'];
        if ($uploadMb !== null && $uploadMb > 0) {
            $args[] = '--upload';
            $args[] = $uploadMb . 'M';
        }

        // Clear capability cache before/after so UI refresh sees new flags.
        ServerInfo::clearCapabilityCache();

        $cmd = 'sudo -n';
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';

        $output = '';
        $code = 1;
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, dirname($script), [
            'DEBIAN_FRONTEND' => 'noninteractive',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);
        if (!is_resource($proc)) {
            return $this->fail(500, '无法启动启用脚本（proc_open 失败）');
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

        ServerInfo::clearCapabilityCache();
        $info = new ServerInfo();
        $hints = $info->enablementHints(
            defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : null
        );
        $cap = ServerInfo::compressionCapability();
        $restartScheduled = str_contains($output, 'WEB_RESTART_SCHEDULED=1');

        if ($code !== 0) {
            return [
                'ok' => false,
                'exit_code' => $code,
                'output' => $output,
                'sudo_ready' => true,
                'restart_scheduled' => $restartScheduled,
                'capability' => $cap,
                'enablement_hints' => $hints,
                'message' => '启用脚本失败（exit ' . $code . '）',
            ];
        }

        return [
            'ok' => true,
            'exit_code' => 0,
            'output' => $output,
            'sudo_ready' => true,
            'restart_scheduled' => $restartScheduled,
            'capability' => $cap,
            'enablement_hints' => $hints,
            'message' => $restartScheduled
                ? '扩展已安装，服务将在约 2 秒后重启，请稍候刷新'
                : '扩展已启用',
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   exit_code:int,
     *   output:string,
     *   sudo_ready:bool,
     *   restart_scheduled:bool,
     *   capability: array{gd:bool,imagick:bool,avif:bool,webp:bool,heic:bool},
     *   enablement_hints: array<string,mixed>,
     *   message:string
     * }
     */
    private function fail(int $httpish, string $message): array
    {
        $info = new ServerInfo();
        return [
            'ok' => false,
            'exit_code' => $httpish,
            'output' => '',
            'sudo_ready' => $this->sudoReady(),
            'restart_scheduled' => false,
            'capability' => ServerInfo::compressionCapability(),
            'enablement_hints' => $info->enablementHints(
                defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : null
            ),
            'message' => $message,
        ];
    }
}
