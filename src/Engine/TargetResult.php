<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Engine;

final class TargetResult
{
    public const PRESENT = 'present';
    public const APPLIED = 'applied';
    public const MISSING = 'missing';
    public const NOT_COVERED = 'not-covered';
    public const CONFLICT = 'conflict';
    public const REVERTED = 'reverted';
    public const UNWANTED = 'unwanted';
    public const ABSENT = 'absent';

    /** @var string */
    private $path;

    /** @var string */
    private $state;

    /** @var string|null */
    private $reason;

    /** @var string[] */
    private $written;

    /**
     * @param string[] $written
     */
    public function __construct(string $path, string $state, ?string $reason = null, array $written = [])
    {
        $this->path = $path;
        $this->state = $state;
        $this->reason = $reason;
        $this->written = $written;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return string[]
     */
    public function written(): array
    {
        return $this->written;
    }

    public function isProblem(): bool
    {
        return $this->state === self::CONFLICT;
    }

    public function isOutstanding(): bool
    {
        return $this->state === self::MISSING || $this->state === self::UNWANTED;
    }

    public function isInPlace(): bool
    {
        return in_array($this->state, [self::PRESENT, self::APPLIED], true);
    }
}
