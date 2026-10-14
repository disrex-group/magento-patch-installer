<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Project;

use Disrex\MagentoPatchInstaller\Diff\FilePatch;

final class Project
{
    /** @var string */
    private $root;

    /** @var array<string, string> */
    private $versions;

    /** @var array<string, string> */
    private $replacedBy;

    /** @var RootFileMap */
    private $rootFiles;

    /**
     * @param array<string, string> $versions package => pretty version, the root package included
     * @param array<string, string> $replacedBy replaced package => package that replaces it
     * @param array<int, array{install: string, map: mixed}> $rootMaps
     */
    public function __construct(string $root, array $versions, array $replacedBy, array $rootMaps)
    {
        // Canonical, so resolved paths can be compared to it as a prefix even when the docroot is a symlink.
        $this->root = rtrim(realpath($root) ?: $root, '/');
        $this->versions = $versions;
        $this->replacedBy = $replacedBy;
        $this->rootFiles = new RootFileMap($rootMaps, function (string $path): bool {
            return is_dir($this->absolute($path));
        });
    }

    public function root(): string
    {
        return $this->root;
    }

    public function version(string $package): ?string
    {
        return $this->versions[$package] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    public function rootFiles(): RootFileMap
    {
        return $this->rootFiles;
    }

    public function absolute(string $path): string
    {
        return $this->root . '/' . ltrim($path, '/');
    }

    public function isFile(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function hash(string $path): ?string
    {
        $file = $this->absolute($path);

        return is_file($file) ? (hash_file('sha256', $file) ?: null) : null;
    }

    /**
     * The project-relative path to write instead of $path, with every symlink in it followed, or null when it
     * leads outside the project. git apply refuses to write "beyond a symbolic link", and both the symlink
     * deploy strategy and Composer path repositories put links in exactly these paths.
     */
    public function writablePath(string $path): ?string
    {
        $parts = explode('/', ltrim($path, '/'));
        $tail = [];

        while (true) {
            $real = realpath($this->root . ($parts === [] ? '' : '/' . implode('/', $parts)));

            if ($real !== false) {
                $resolved = rtrim($real, '/') . ($tail === [] ? '' : '/' . implode('/', $tail));

                if ($resolved === $this->root) {
                    return null;
                }

                return strpos($resolved, $this->root . '/') === 0 ? substr($resolved, strlen($this->root) + 1) : null;
            }

            if ($parts === []) {
                return null;
            }

            array_unshift($tail, (string) array_pop($parts));
        }
    }

    public function whyAbsent(FilePatch $patch): string
    {
        $package = $patch->package();

        if ($package === null) {
            return 'not present in this installation';
        }

        if (isset($this->replacedBy[$package])) {
            return sprintf('%s is replaced by %s', $package, $this->replacedBy[$package]);
        }

        if (!isset($this->versions[$package])) {
            return $package . ' is not installed';
        }

        return $package . ' does not ship this file';
    }
}
