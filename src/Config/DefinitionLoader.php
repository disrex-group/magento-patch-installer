<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Config;

final class DefinitionLoader
{
    /** @var string[] */
    private $errors = [];

    /**
     * @param array<int, array{name: string, config: array<string, mixed>, dir: string}> $packages installed packages
     */
    public function load(Settings $settings, string $rootName, string $rootDir, array $packages): Catalog
    {
        $this->errors = [];
        $reader = new DefinitionReader();
        $byOwner = [$rootName => $reader->read($rootName, $settings->config(), $rootDir)];

        foreach ($packages as $package) {
            $config = $package['config'];

            if (!DefinitionReader::declaresPatches($config)) {
                continue;
            }

            // Silence here would read exactly like a fully patched store.
            if (!$settings->trusts($package['name'])) {
                $this->errors[] = sprintf('%s declares patches but is not trusted; add it to extra.magento-patches.trust', $package['name']);
                continue;
            }

            $byOwner[$package['name']] = $reader->read($package['name'], $config, $package['dir']);
        }

        $this->errors = array_merge($this->errors, $reader->errors());

        $all = [];
        foreach ($byOwner as $owner => $definitions) {
            $this->link((string) $owner, $definitions);
            $all = array_merge($all, $definitions);
        }

        $ordered = $this->order($all);
        $this->applySkips($settings->sources(), $ordered);

        return new Catalog($ordered, $this->errors);
    }

    /**
     * @param Definition[] $definitions one owner's, in declaration order
     */
    private function link(string $owner, array $definitions): void
    {
        $byLine = [];
        $byId = [];

        foreach ($definitions as $definition) {
            $line = (string) $definition->line();

            if (isset($byLine[$line][$definition->id()])) {
                $this->errors[] = sprintf('%s: "%s" is declared twice%s', $owner, $definition->id(), $line === '' ? '' : ' in line ' . $line);
            }

            $byLine[$line][$definition->id()] = $definition;
            $byId[$definition->id()][] = $definition;
        }

        $previous = [];

        foreach ($definitions as $definition) {
            $line = (string) $definition->line();
            $requires = [];

            if ($definition->isCumulative() && isset($previous[$line])) {
                $requires[] = $previous[$line];
            }

            if ($definition->isCumulative()) {
                $previous[$line] = $definition->key();
            }

            foreach ($definition->dependsOn() as $id) {
                $target = $byLine[$line][$id] ?? null;

                if ($target === null && count($byId[$id] ?? []) === 1) {
                    $target = $byId[$id][0];
                }

                if ($target === null) {
                    $this->errors[] = sprintf(
                        '%s: patch "%s" depends on "%s", which %s',
                        $owner,
                        $definition->label(),
                        $id,
                        isset($byId[$id]) ? 'exists in several lines; declare both in the same line' : 'is not declared'
                    );
                    continue;
                }

                if ($target === $definition) {
                    $this->errors[] = sprintf('%s: patch "%s" depends on itself', $owner, $definition->label());
                    continue;
                }

                $requires[] = $target->key();
            }

            $definition->setRequires(array_values(array_unique($requires)));
        }
    }

    /**
     * @param Definition[] $definitions
     *
     * @return Definition[] each definition after everything it requires; otherwise in declaration order
     */
    private function order(array $definitions): array
    {
        $position = [];
        $waitingOn = [];
        $dependents = [];

        foreach ($definitions as $index => $definition) {
            $position[$definition->key()] = $index;
        }

        foreach ($definitions as $index => $definition) {
            $waitingOn[$index] = 0;

            foreach ($definition->requires() as $key) {
                if (isset($position[$key])) {
                    $waitingOn[$index]++;
                    $dependents[$position[$key]][] = $index;
                }
            }
        }

        $ready = new \SplMinHeap();
        foreach ($waitingOn as $index => $count) {
            if ($count === 0) {
                $ready->insert($index);
            }
        }

        $ordered = [];
        while (!$ready->isEmpty()) {
            $index = $ready->extract();
            $ordered[$index] = $definitions[$index];

            foreach ($dependents[$index] ?? [] as $dependent) {
                if (--$waitingOn[$dependent] === 0) {
                    $ready->insert($dependent);
                }
            }
        }

        foreach ($definitions as $index => $definition) {
            if (!isset($ordered[$index])) {
                $this->errors[] = sprintf('%s: patch "%s" is part of a dependency cycle', $definition->owner(), $definition->label());
                $ordered[$index] = $definition;
            }
        }

        return array_values($ordered);
    }

    /**
     * Only the root package can switch a patch off: which patches a store takes is the store's decision.
     *
     * @param mixed $sources
     * @param Definition[] $ordered
     */
    private function applySkips($sources, array $ordered): void
    {
        if ($sources === [] || $sources === null) {
            return;
        }

        if (!is_array($sources)) {
            $this->errors[] = '"sources" must be an object keyed by package name';

            return;
        }

        $dependedOn = [];
        foreach ($ordered as $definition) {
            foreach ($definition->requires() as $key) {
                $dependedOn[$key] = true;
            }
        }

        foreach ($sources as $owner => $rules) {
            $owner = (string) $owner;
            $mine = array_filter($ordered, static function (Definition $definition) use ($owner): bool {
                return $definition->owner() === $owner;
            });

            if (!is_array($rules) || array_diff(array_keys($rules), ['skip']) !== []) {
                $this->errors[] = sprintf('sources: "%s" may only contain "skip"', $owner);
                continue;
            }

            if ($mine === []) {
                $this->errors[] = sprintf('sources: "%s" declares no patches in this project', $owner);
                continue;
            }

            $skip = $rules['skip'] ?? [];
            if (!is_array($skip)) {
                $this->errors[] = sprintf('sources: "%s" has a "skip" that is not an object of id => reason', $owner);
                continue;
            }

            foreach ($skip as $identifier => $reason) {
                $identifier = (string) $identifier;
                $parts = explode('@', $identifier, 2);
                $matches = array_filter($mine, static function (Definition $definition) use ($parts): bool {
                    return $definition->id() === $parts[0] && (!isset($parts[1]) || $parts[1] === $definition->line());
                });

                if (!is_scalar($reason)) {
                    $this->errors[] = sprintf('sources: the reason for skipping "%s" must be text', $identifier);
                    continue;
                }

                if ($matches === []) {
                    $this->errors[] = sprintf('sources: "%s" has no patch "%s" to skip', $owner, $identifier);
                    continue;
                }

                // A bare id matches that id in every line, and a line published later would silently lose its chain.
                if (!isset($parts[1]) && $this->anyDependedOn($matches, $dependedOn)) {
                    $this->errors[] = sprintf('sources: "%s" is built on by other patches; skip it as "%s@<line>"', $identifier, $identifier);
                    continue;
                }

                foreach ($matches as $definition) {
                    $definition->skip(trim((string) $reason) === '' ? 'skipped by this project' : trim((string) $reason));
                }
            }
        }

        $labels = [];
        foreach ($ordered as $definition) {
            $labels[$definition->key()] = $definition;

            if ($definition->skipReason() !== null) {
                continue;
            }

            foreach ($definition->requires() as $key) {
                if (isset($labels[$key]) && $labels[$key]->skipReason() !== null) {
                    $definition->skip(sprintf('depends on %s, which this project skips', $labels[$key]->label()));
                    break;
                }
            }
        }
    }

    /**
     * @param Definition[] $definitions
     * @param array<string, bool> $dependedOn
     */
    private function anyDependedOn(array $definitions, array $dependedOn): bool
    {
        foreach ($definitions as $definition) {
            if (isset($dependedOn[$definition->key()])) {
                return true;
            }
        }

        return false;
    }
}
