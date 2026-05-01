<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

/**
 * OS / web server / runtime metadata for the settings dashboard.
 */
final class ServerInfo
{
    public function distro(): array
    {
        return get_distro_info();
    }

    public function uptimeSeconds(): ?int
    {
        return get_server_uptime_seconds();
    }

    public function webServer(?string $software = null): array
    {
        return detect_web_server_software($software);
    }

    public function runtimeMetrics(): array
    {
        return get_server_runtime_metrics();
    }
}
