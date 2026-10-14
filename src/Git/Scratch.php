<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Git;

use Disrex\MagentoPatchInstaller\Diff\FilePatch;

final class Scratch
{
    private const NAME = 'target';

    /** @var string */
    private $directory;

    /** @var GitApply */
    private $git;

    private function __construct(string $directory)
    {
        $this->directory = $directory;
        $this->git = new GitApply($directory);
    }

    public static function of(?string $source): self
    {
        $directory = sys_get_temp_dir() . '/magento-patch-installer-' . bin2hex(random_bytes(8));

        // mkdir with 0700 fails if someone pre-created the name, so the copy cannot be swapped underneath us.
        if (!@mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create a scratch directory at ' . $directory);
        }

        $scratch = new self($directory);

        if ($source !== null && is_file($source)) {
            copy($source, $scratch->file());
            @chmod($scratch->file(), fileperms($source) & 0777);
        }

        return $scratch;
    }

    public function file(): string
    {
        return $this->directory . '/' . self::NAME;
    }

    public function exists(): bool
    {
        return is_file($this->file());
    }

    public function isApplied(FilePatch $patch): bool
    {
        return $this->git->isApplied($patch->renderFor(self::NAME));
    }

    public function canApply(FilePatch $patch): bool
    {
        return $this->git->canApply($patch->renderFor(self::NAME));
    }

    public function apply(FilePatch $patch): bool
    {
        return $this->git->apply($patch->renderFor(self::NAME));
    }

    public function revert(FilePatch $patch): bool
    {
        return $this->git->revert($patch->renderFor(self::NAME));
    }

    public function contents(): ?string
    {
        return $this->exists() ? (string) file_get_contents($this->file()) : null;
    }

    public function __destruct()
    {
        @unlink($this->file());
        @rmdir($this->directory);
    }
}
