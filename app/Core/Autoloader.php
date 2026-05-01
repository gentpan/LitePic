<?php
declare(strict_types=1);

namespace LitePic\Core;

/**
 * Minimal PSR-4 autoloader. Maps `LitePic\Foo\Bar` -> `<root>/app/Foo/Bar.php`.
 */
final class Autoloader
{
    private string $prefix;
    private string $baseDir;

    public function __construct(string $prefix, string $baseDir)
    {
        $this->prefix = trim($prefix, '\\') . '\\';
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public static function register(string $prefix, string $baseDir): void
    {
        $loader = new self($prefix, $baseDir);
        spl_autoload_register([$loader, 'load']);
    }

    public function load(string $class): bool
    {
        if (strncmp($class, $this->prefix, strlen($this->prefix)) !== 0) {
            return false;
        }

        $relative = substr($class, strlen($this->prefix));
        $path = $this->baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($path)) {
            require $path;
            return true;
        }

        return false;
    }
}
