<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Support;

final class Workspace
{
    /** @var string */
    private $root;

    private function __construct(string $root)
    {
        $this->root = $root;
    }

    public static function create(): self
    {
        $root = sys_get_temp_dir() . '/mpi-test-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return new self(realpath($root) ?: $root);
    }

    public function inside(string $relative): self
    {
        $root = $this->path($relative);
        mkdir($root, 0777, true);

        return new self($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    public function write(string $relative, string $content, int $mode = 0644): void
    {
        $file = $this->path($relative);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        chmod($file, $mode);
    }

    public function read(string $relative): ?string
    {
        $file = $this->path($relative);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function remove(): void
    {
        self::delete($this->root);
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
