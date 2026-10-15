<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Config;

use Composer\Semver\VersionParser;

/**
 * Reads one package's declarations. Every malformed entry becomes an error rather than an exception: the
 * config can come from a third-party package, and an exception would stop composer install for every consumer.
 */
final class DefinitionReader
{
    /** @var string[] */
    private $errors = [];

    /** @var int */
    private $sequence = 0;

    /**
     * @param array<string, mixed> $config the package's extra.magento-patches block
     *
     * @return Definition[]
     */
    public function read(string $owner, array $config, string $packageDir): array
    {
        $packageDir = rtrim($packageDir, '/');
        $definitions = $this->fromBlock($owner, $config, $packageDir, $packageDir, 'composer.json');

        foreach ($this->includes($owner, $config, $packageDir) as $file => $included) {
            $definitions = array_merge($definitions, $this->fromBlock($owner, $included, $packageDir, dirname($file), basename($file)));
        }

        return $definitions;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function declaresPatches(array $config): bool
    {
        foreach (['patches', 'lines', 'include'] as $key) {
            if (!empty($config[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return Definition[]
     */
    private function fromBlock(string $owner, array $block, string $packageDir, string $sourceDir, string $file): array
    {
        $definitions = [];

        foreach ($this->entries($owner, $block['patches'] ?? [], $file) as $entry) {
            $base = $entry['base'] ?? [];
            if (!is_array($base)) {
                $this->errors[] = sprintf('%s: patch "%s" has a "base" that is not an object', $owner, (string) $entry['id']);
                continue;
            }
            $definition = $this->definition($owner, $entry, $base, null, false, $packageDir, $sourceDir);
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        $lines = $block['lines'] ?? [];
        if (!is_array($lines)) {
            $this->errors[] = sprintf('%s: "lines" in %s must be an object keyed by line name', $owner, $file);

            return $definitions;
        }

        foreach ($lines as $line => $spec) {
            $line = (string) $line;

            if (!is_array($spec) || !isset($spec['base']) || !is_array($spec['base']) || $spec['base'] === []) {
                $this->errors[] = sprintf('%s: line "%s" needs a "base" object naming at least one package', $owner, $line);
                continue;
            }

            $cumulative = ($spec['cumulative'] ?? false) === true;

            foreach ($this->entries($owner, $spec['patches'] ?? [], $file . ' line ' . $line) as $entry) {
                if ($cumulative && !empty($entry['depends'])) {
                    $this->errors[] = sprintf('%s: patch "%s" declares "depends" in cumulative line "%s", where the list order is the chain', $owner, (string) $entry['id'], $line);
                    continue;
                }

                // A chain with a link switched off would leave every later link without the code it was cut against.
                if ($cumulative && ($entry['optional'] ?? false) === true) {
                    $this->errors[] = sprintf('%s: patch "%s" is marked "optional" in cumulative line "%s", where every link is needed', $owner, (string) $entry['id'], $line);
                    continue;
                }

                $definition = $this->definition($owner, $entry, $spec['base'], $line, $cumulative, $packageDir, $sourceDir);
                if ($definition !== null) {
                    $definitions[] = $definition;
                }
            }
        }

        return $definitions;
    }

    /**
     * @param mixed $raw
     *
     * @return array<int, array<string, mixed>>
     */
    private function entries(string $owner, $raw, string $where): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            $this->errors[] = sprintf('%s: "patches" in %s must be a list', $owner, $where);

            return [];
        }

        $entries = [];

        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['id'], $entry['source']) || !is_scalar($entry['id']) || !is_scalar($entry['source'])
                || trim((string) $entry['id']) === '' || trim((string) $entry['source']) === ''
            ) {
                $this->errors[] = sprintf('%s: a patch in %s has no usable "id" and "source"', $owner, $where);
                continue;
            }

            foreach (['label', 'description'] as $field) {
                if (isset($entry[$field]) && !is_scalar($entry[$field])) {
                    $this->errors[] = sprintf('%s: patch "%s" has a %s that is not text', $owner, (string) $entry['id'], $field);
                    continue 2;
                }
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<mixed> $base
     */
    private function definition(string $owner, array $entry, array $base, ?string $line, bool $cumulative, string $packageDir, string $sourceDir): ?Definition
    {
        $id = trim((string) $entry['id']);
        $constraints = [];

        foreach ($base as $package => $constraint) {
            if (!is_string($constraint) && !is_int($constraint) && !is_float($constraint)) {
                $this->errors[] = sprintf('%s: patch "%s" has a base constraint for %s that is not text', $owner, $id, (string) $package);

                return null;
            }

            try {
                (new VersionParser())->parseConstraints((string) $constraint);
            } catch (\UnexpectedValueException $e) {
                $this->errors[] = sprintf('%s: patch "%s" has an unreadable base constraint for %s: %s', $owner, $id, (string) $package, (string) $constraint);

                return null;
            }

            $constraints[(string) $package] = (string) $constraint;
        }

        $dependsOn = [];
        foreach ((array) ($entry['depends'] ?? []) as $dependency) {
            if (!is_scalar($dependency)) {
                $this->errors[] = sprintf('%s: patch "%s" has a "depends" entry that is not text', $owner, $id);

                return null;
            }
            $dependsOn[] = (string) $dependency;
        }

        $optional = $entry['optional'] ?? false;
        if (!is_bool($optional)) {
            $this->errors[] = sprintf('%s: patch "%s" has an "optional" that is not true or false', $owner, $id);

            return null;
        }

        $source = $this->confine($owner, $id, (string) $entry['source'], $packageDir, $sourceDir);
        if ($source === null) {
            return null;
        }

        return new Definition(
            $owner . '#' . $this->sequence++,
            $id,
            isset($entry['label']) ? trim((string) $entry['label']) : null,
            $owner,
            $source,
            (string) $entry['source'],
            $constraints,
            $line,
            $cumulative,
            isset($entry['description']) ? trim((string) $entry['description']) : null,
            $dependsOn,
            $optional
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>> absolute file => decoded contents
     */
    private function includes(string $owner, array $config, string $packageDir): array
    {
        $paths = $config['include'] ?? [];

        if ($paths === []) {
            return [];
        }

        if (!is_array($paths)) {
            $this->errors[] = sprintf('%s: "include" must be a list of files', $owner);

            return [];
        }

        $files = [];

        foreach ($paths as $path) {
            if (!is_string($path)) {
                $this->errors[] = sprintf('%s: "include" has an entry that is not a path', $owner);
                continue;
            }

            $file = $this->confine($owner, 'include', $path, $packageDir, $packageDir);
            if ($file === null) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                $this->errors[] = sprintf('%s: included file %s is not valid JSON', $owner, $path);
                continue;
            }

            if (isset($decoded['include'])) {
                $this->errors[] = sprintf('%s: included file %s includes others, which only composer.json may do', $owner, $path);
                continue;
            }

            // A typo in "lines" would otherwise drop every patch in the file and still exit 0.
            if (!isset($decoded['patches']) && !isset($decoded['lines'])) {
                $this->errors[] = sprintf('%s: included file %s declares neither "patches" nor "lines"', $owner, $path);
                continue;
            }

            $files[$file] = $decoded;
        }

        return $files;
    }

    private function confine(string $owner, string $what, string $path, string $packageDir, string $sourceDir): ?string
    {
        if (strpos($path, "\0") !== false) {
            $this->errors[] = sprintf('%s: %s has a NUL byte in its path', $owner, $what);

            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            $this->errors[] = sprintf('%s: %s points at a URL; patches must ship inside the package', $owner, $what);

            return null;
        }

        $real = realpath($sourceDir . '/' . ltrim($path, '/'));
        $package = realpath($packageDir);

        if ($real === false || $package === false) {
            $this->errors[] = sprintf('%s: %s points at %s, which does not exist', $owner, $what, $path);

            return null;
        }

        if (strpos($real, rtrim($package, '/') . '/') !== 0) {
            $this->errors[] = sprintf('%s: %s points at %s, outside the package', $owner, $what, $path);

            return null;
        }

        // A FIFO passes every check above and blocks the read forever.
        if (!is_file($real)) {
            $this->errors[] = sprintf('%s: %s points at %s, which is not a regular file', $owner, $what, $path);

            return null;
        }

        return $real;
    }
}
