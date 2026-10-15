<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Engine;

use Disrex\MagentoPatchInstaller\Diff\FilePatch;

/**
 * What this plugin last wrote to each file: the patches, in order, and the file's hash afterwards. A matching
 * hash proves the file still holds exactly those patches, which is what lets a patch Adobe re-issues under the
 * same id replace the old one instead of ending in a conflict. Verdicts never come from here; they come from
 * asking git about the file.
 */
final class Ledger
{
    private const VERSION = 1;

    /** @var string */
    private $file;

    /** @var array{version: int, files: array<string, array{sha256: string, at: string, steps: array<int, array{patch: string, fingerprint: string, diff: string, adopted?: bool}>}>} */
    private $data;

    /** @var bool */
    private $changed = false;

    public function __construct(string $projectRoot)
    {
        // Always this path, under var/ that Magento gitignores, so a deploy's "git add -A" never commits it.
        $this->file = rtrim($projectRoot, '/') . '/var/magento-patches/ledger.json';
        $this->data = $this->read();
    }

    /**
     * @return array{sha256: string, at: string, steps: array<int, array{patch: string, fingerprint: string, diff: string, adopted?: bool}>}|null
     */
    public function entry(string $path): ?array
    {
        return $this->data['files'][$path] ?? null;
    }

    /**
     * @param array<int, array{patch: string, file: FilePatch, adopted: bool}> $steps adopted: found in place, not written here
     */
    public function record(string $path, string $sha256, array $steps, string $at): void
    {
        $this->data['files'][$path] = [
            'sha256' => $sha256,
            'at' => $at,
            'steps' => array_map(static function (array $step) use ($path): array {
                return array_merge([
                    'patch' => $step['patch'],
                    'fingerprint' => $step['file']->fingerprint(),
                    'diff' => $step['file']->renderFor($path),
                ], $step['adopted'] ? ['adopted' => true] : []);
            }, $steps),
        ];
        $this->changed = true;
    }

    public function forget(string $path): void
    {
        if (isset($this->data['files'][$path])) {
            unset($this->data['files'][$path]);
            $this->changed = true;
        }
    }

    public function lastWrite(): ?string
    {
        $latest = null;

        foreach ($this->data['files'] as $entry) {
            if ($latest === null || strcmp($entry['at'], $latest) > 0) {
                $latest = $entry['at'];
            }
        }

        return $latest;
    }

    public function save(): void
    {
        if (!$this->changed) {
            return;
        }

        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            @file_put_contents($this->file, $json . "\n");
        }

        $this->changed = false;
    }

    /**
     * @return array{version: int, files: array<string, array{sha256: string, at: string, steps: array<int, array{patch: string, fingerprint: string, diff: string, adopted?: bool}>}>}
     */
    private function read(): array
    {
        $empty = ['version' => self::VERSION, 'files' => []];
        $decoded = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || !isset($decoded['files']) || !is_array($decoded['files'])) {
            return $empty;
        }

        $files = [];
        foreach ($decoded['files'] as $path => $entry) {
            if (is_array($entry) && is_string($entry['sha256'] ?? null) && is_string($entry['at'] ?? null) && is_array($entry['steps'] ?? null)) {
                $files[(string) $path] = $entry;
            }
        }

        /** @var array<string, array{sha256: string, at: string, steps: array<int, array{patch: string, fingerprint: string, diff: string, adopted?: bool}>}> $files */
        return ['version' => self::VERSION, 'files' => $files];
    }
}
