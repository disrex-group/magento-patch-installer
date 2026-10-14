<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Project;

/**
 * magento/magento2-base copies ~120 files to the project root through its extra.map. The root copy is the one
 * that is served and the package copy is the one the next deploy copies back, so a patch belongs in both.
 */
final class RootFileMap
{
    /** @var array<int, array{package: string, root: string, isDir: bool}> */
    private $entries = [];

    /**
     * @param array<int, array{install: string, map: mixed}> $packages install dir relative to the project root
     * @param callable(string): bool $isDirectory
     */
    public function __construct(array $packages, callable $isDirectory)
    {
        $installs = array_map(static function (array $package): string {
            return trim($package['install'], '/');
        }, $packages);

        foreach ($packages as $package) {
            $install = trim($package['install'], '/');

            foreach (is_array($package['map']) ? $package['map'] : [] as $pair) {
                if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_string($pair[0]) || !is_string($pair[1])) {
                    continue;
                }

                $source = trim($pair[0], '/');
                $destination = trim($pair[1], '/');

                // extra.map comes from every installed package, trusted or not: refuse anything that could aim a
                // write outside the project or take over the counterpart of a file another package deploys.
                if (!self::isPlainRelative($source) || !self::isPlainRelative($destination)
                    || self::insideAnyOf($destination, array_diff($installs, [$install]))
                ) {
                    continue;
                }

                $packagePath = $install . '/' . $source;
                $this->entries[] = ['package' => $packagePath, 'root' => $destination, 'isDir' => $isDirectory($packagePath)];
            }
        }

        // A file entry must win over the directory entry it sits in.
        usort($this->entries, static function (array $a, array $b): int {
            return strlen($b['root']) <=> strlen($a['root']);
        });
    }

    public function counterpart(string $path): ?string
    {
        foreach ($this->entries as $entry) {
            $rest = self::below($path, $entry['root'], $entry['isDir']);
            if ($rest !== null) {
                return $entry['package'] . $rest;
            }

            $rest = self::below($path, $entry['package'], $entry['isDir']);
            if ($rest !== null) {
                return $entry['root'] . $rest;
            }
        }

        return null;
    }

    public function key(string $path): string
    {
        $other = $this->counterpart($path);

        return $other === null || strcmp($path, $other) < 0 ? $path : $other;
    }

    private static function below(string $path, string $base, bool $isDir): ?string
    {
        if ($path === $base) {
            return '';
        }

        return $isDir && strpos($path, $base . '/') === 0 ? substr($path, strlen($base)) : null;
    }

    private static function isPlainRelative(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return false;
        }

        foreach (preg_split('#[/\\\\]#', $path) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $directories
     */
    private static function insideAnyOf(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if ($directory !== '' && ($path === $directory || strpos($path, $directory . '/') === 0)) {
                return true;
            }
        }

        return false;
    }
}
