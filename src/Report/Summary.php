<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Report;

use Disrex\MagentoPatchInstaller\Engine\PatchResult;
use Disrex\MagentoPatchInstaller\Engine\TargetResult;

final class Summary
{
    public const OK = 0;
    public const MISSING = 1;
    public const CONFLICT = 2;
    public const CONFIG_ERROR = 3;

    /** @var PatchResult[] */
    private $results;

    /** @var string[] */
    private $errors;

    /**
     * @param PatchResult[] $results
     * @param string[] $errors
     */
    public function __construct(array $results, array $errors)
    {
        $this->results = $results;
        $this->errors = $errors;
    }

    /**
     * @return PatchResult[]
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function exitCode(): int
    {
        // A configuration error usually means declarations were dropped, and a clean exit would then
        // describe a store that was never fully checked.
        if ($this->errors !== []) {
            return self::CONFIG_ERROR;
        }

        if ($this->countTargets([TargetResult::CONFLICT]) > 0) {
            return self::CONFLICT;
        }

        if ($this->countTargets([TargetResult::MISSING, TargetResult::UNWANTED]) > 0 || $this->countPatches([PatchResult::BLOCKED]) > 0) {
            return self::MISSING;
        }

        return self::OK;
    }

    /**
     * @param string[] $statuses
     */
    public function countPatches(array $statuses): int
    {
        return count(array_filter($this->results, static function (PatchResult $result) use ($statuses): bool {
            return in_array($result->status(), $statuses, true);
        }));
    }

    /**
     * @param string[] $states
     */
    public function countTargets(array $states): int
    {
        $count = 0;

        foreach ($this->results as $result) {
            foreach ($result->targets() as $target) {
                if (in_array($target->state(), $states, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function headline(): string
    {
        $parts = [sprintf('%d for this store', $this->countPatches([PatchResult::APPLIES, PatchResult::BLOCKED]))];

        $counts = [
            'applied now' => $this->countTargets([TargetResult::APPLIED, TargetResult::REVERTED]),
            'in place' => $this->countTargets([TargetResult::PRESENT]),
            'missing' => $this->countTargets([TargetResult::MISSING, TargetResult::UNWANTED]),
            'conflicting' => $this->countTargets([TargetResult::CONFLICT]),
        ];
        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%d file%s %s', $count, $count === 1 ? '' : 's', $label);
            }
        }

        foreach ([PatchResult::BLOCKED => 'blocked', PatchResult::SKIPPED => 'skipped by this project', PatchResult::OTHER_VERSION => 'for other versions'] as $status => $label) {
            $count = $this->countPatches([$status]);
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $label);
            }
        }

        return 'patches: ' . implode(' · ', $parts);
    }
}
