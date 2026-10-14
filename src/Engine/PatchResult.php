<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Engine;

use Disrex\MagentoPatchInstaller\Config\Definition;

final class PatchResult
{
    public const APPLIES = 'applies';
    public const OTHER_VERSION = 'other-version';
    public const SKIPPED = 'skipped';
    public const BLOCKED = 'blocked';
    public const UNREADABLE = 'unreadable';

    /** @var Definition */
    private $definition;

    /** @var string */
    private $status;

    /** @var string|null */
    private $reason;

    /** @var TargetResult[] */
    private $targets;

    /**
     * @param TargetResult[] $targets
     */
    public function __construct(Definition $definition, string $status, ?string $reason, array $targets = [])
    {
        $this->definition = $definition;
        $this->status = $status;
        $this->reason = $reason;
        $this->targets = $targets;
    }

    public function definition(): Definition
    {
        return $this->definition;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return TargetResult[]
     */
    public function targets(): array
    {
        return $this->targets;
    }

    public function isComplete(): bool
    {
        foreach ($this->targets as $target) {
            if ($target->isProblem() || $target->isOutstanding()) {
                return false;
            }
        }

        return true;
    }
}
