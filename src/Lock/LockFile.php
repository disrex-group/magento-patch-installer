<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Lock;

use Disrex\MagentoPatchInstaller\Config\Catalog;
use Disrex\MagentoPatchInstaller\Engine\Engine;
use Disrex\MagentoPatchInstaller\Project\Project;

/**
 * magento-patches.lock records which patches this project takes, so a merge request shows every patch that
 * arrives, changes or goes. Like composer.lock it is written by update, never by install: install runs on
 * servers, and a rewritten tracked file there breaks the next git pull.
 */
final class LockFile
{
    public const NAME = 'magento-patches.lock';

    /** @var string */
    private $file;

    public function __construct(string $projectRoot)
    {
        $this->file = rtrim($projectRoot, '/') . '/' . self::NAME;
    }

    /**
     * @return array{_readme: string[], content-hash: string, patches: array<int, array<string, mixed>>}
     */
    public static function build(Catalog $catalog, Engine $engine, Project $project): array
    {
        $patches = [];

        foreach ($catalog->definitions() as $definition) {
            $files = $engine->filePatches($definition);

            if ($engine->baseMismatch($definition) !== null || !is_array($files)) {
                continue;
            }

            $patches[] = [
                'package' => $definition->owner(),
                'version' => $project->version($definition->owner()),
                'id' => $definition->id(),
                'line' => $definition->line(),
                'label' => $definition->label(),
                'source' => $definition->sourceName(),
                'sha256' => hash_file('sha256', $definition->source()) ?: null,
                'skipped' => $definition->skipReason(),
                'files' => array_values(array_unique(array_map(static function ($patch): string {
                    return $patch->path();
                }, $files))),
            ];
        }

        return [
            '_readme' => [
                'The security patches this project takes, written by disrex/magento-patch-installer.',
                'composer update and composer magento-patches:lock rewrite it. Commit it.',
            ],
            'content-hash' => hash('sha256', (string) json_encode($patches)),
            'patches' => $patches,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        return is_array($data) ? $data : null;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /**
     * @param array{content-hash: string} $lock
     */
    public function matches(array $lock): bool
    {
        $current = $this->read();

        return $current !== null && ($current['content-hash'] ?? null) === $lock['content-hash'];
    }

    /**
     * @param array<string, mixed> $lock
     *
     * @return bool whether the file changed
     */
    public function write(array $lock): bool
    {
        $json = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        if (is_file($this->file) && file_get_contents($this->file) === $json) {
            return false;
        }

        return file_put_contents($this->file, $json) !== false;
    }
}
