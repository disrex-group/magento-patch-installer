<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Report;

use Disrex\MagentoPatchInstaller\Config\Catalog;
use Disrex\MagentoPatchInstaller\Engine\Engine;
use Disrex\MagentoPatchInstaller\Engine\PatchResult;

final class JsonReport
{
    public static function results(Summary $summary): string
    {
        $patches = array_map(static function (PatchResult $result): array {
            $definition = $result->definition();
            $targets = [];

            foreach ($result->targets() as $target) {
                $targets[] = array_filter([
                    'path' => $target->path(),
                    'state' => $target->state(),
                    'reason' => $target->reason(),
                    'written' => $target->written(),
                ], static function ($value): bool {
                    return $value !== null && $value !== [];
                });
            }

            return [
                'id' => $definition->id(),
                'label' => $definition->label(),
                'owner' => $definition->owner(),
                'source' => $definition->sourceName(),
                'line' => $definition->line(),
                'status' => $result->status(),
                'applicable' => $result->status() === PatchResult::APPLIES,
                'blocked' => $result->status() === PatchResult::BLOCKED,
                'skipped' => $definition->skipReason(),
                'reason' => $result->reason(),
                'targets' => $targets,
            ];
        }, $summary->results());

        return self::encode(['exit_code' => $summary->exitCode(), 'errors' => $summary->errors(), 'patches' => $patches]);
    }

    /**
     * @param string[] $errors
     */
    public static function catalog(Catalog $catalog, Engine $engine, array $errors): string
    {
        $labels = [];
        foreach ($catalog->definitions() as $definition) {
            $labels[$definition->key()] = $definition->id();
        }

        $patches = [];
        foreach ($catalog->definitions() as $definition) {
            $files = $engine->filePatches($definition);
            $mismatch = $engine->baseMismatch($definition);

            $patches[] = [
                'id' => $definition->id(),
                'label' => $definition->label(),
                'owner' => $definition->owner(),
                'source' => $definition->sourceName(),
                'line' => $definition->line(),
                'description' => $definition->description(),
                'depends' => array_values(array_map(static function (string $key) use ($labels): string {
                    return $labels[$key] ?? $key;
                }, $definition->requires())),
                'targets' => is_array($files) ? count($files) : 0,
                'applicable' => $mismatch === null && $definition->skipReason() === null && is_array($files),
                'skipped' => $definition->skipReason(),
                'reason' => $definition->skipReason() ?? $mismatch ?? (is_string($files) ? $files : null),
            ];
        }

        return self::encode(['exit_code' => $errors === [] ? Summary::OK : Summary::CONFIG_ERROR, 'errors' => $errors, 'patches' => $patches]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        // A git error or a quoted path can carry invalid UTF-8, and json_encode would then return false.
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
