<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Engine;

use Composer\Semver\Semver;
use Disrex\MagentoPatchInstaller\Config\Definition;
use Disrex\MagentoPatchInstaller\Diff\DiffParser;
use Disrex\MagentoPatchInstaller\Diff\FilePatch;
use Disrex\MagentoPatchInstaller\Git\GitApply;
use Disrex\MagentoPatchInstaller\Git\Scratch;
use Disrex\MagentoPatchInstaller\Project\Project;

final class Engine
{
    private const RANK = [
        TargetResult::CONFLICT => 6,
        TargetResult::MISSING => 5,
        TargetResult::UNWANTED => 4,
        TargetResult::APPLIED => 3,
        TargetResult::REVERTED => 3,
        TargetResult::PRESENT => 2,
        TargetResult::ABSENT => 1,
        TargetResult::NOT_COVERED => 0,
    ];

    /** @var Project */
    private $project;

    /** @var Ledger */
    private $ledger;

    /** @var GitApply */
    private $git;

    /** @var DiffParser */
    private $parser;

    /** @var callable(): string */
    private $clock;

    /** @var array<string, FilePatch[]|string> source => file patches, or why they could not be read */
    private $parsed = [];

    /** @var string[] */
    private $errors = [];

    /** @var array<string, FilePatch[]> definition key => its file patches */
    private $files = [];

    /** @var array<string, array<int, array{key: string, file: FilePatch}>> file key => every file patch touching it, in apply order */
    private $slots = [];

    /** @var array<string, array<string, true>> definition key => keys of everything it is built on */
    private $ancestors = [];

    /** @var array<string, Definition> */
    private $wanted = [];

    /** @var array<string, true> */
    private $pendingRebuild = [];

    /** @var array<string, true> */
    private $rebuilt = [];

    /** @var array<string, string> path => label of the skipped patch whose reversal deleted it */
    private $vacated = [];

    /** @var array<string, true> */
    private $written = [];

    /** @var array<string, string> placement path => file key */
    private $touched = [];

    /** @var array<string, string> "definition key|file index|path" => state */
    private $placementStates = [];

    public function __construct(Project $project, Ledger $ledger, ?callable $clock = null)
    {
        $this->project = $project;
        $this->ledger = $ledger;
        $this->git = new GitApply($project->root());
        $this->parser = new DiffParser();
        $this->clock = $clock ?? static function (): string {
            return date('c');
        };
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function baseMismatch(Definition $definition): ?string
    {
        foreach ($definition->base() as $package => $constraint) {
            $version = $this->project->version($package);

            if ($version === null) {
                return $package . ' is not installed';
            }

            try {
                if (!Semver::satisfies($version, $constraint)) {
                    return sprintf('built for %s %s, this install has %s', $package, $constraint, $version);
                }
            } catch (\UnexpectedValueException $e) {
                return sprintf('cannot compare %s %s with %s', $package, $constraint, $version);
            }
        }

        return null;
    }

    /**
     * @return FilePatch[]|string the file patches, or why the source could not be read
     */
    public function filePatches(Definition $definition)
    {
        $source = $definition->source();

        if (!isset($this->parsed[$source])) {
            try {
                $contents = file_get_contents($source);
                $patches = $contents === false ? [] : $this->parser->parse($contents);
                $this->parsed[$source] = $patches === [] ? 'it contains no file changes' : $patches;
            } catch (\UnexpectedValueException $e) {
                $this->parsed[$source] = $e->getMessage();
            }
        }

        return $this->parsed[$source];
    }

    /**
     * @param Definition[] $definitions in apply order
     *
     * @return PatchResult[] in the same order
     */
    public function run(array $definitions, bool $write): array
    {
        $this->reset();
        $results = [];
        $unwanted = [];

        foreach ($definitions as $definition) {
            $key = $definition->key();
            $mismatch = $this->baseMismatch($definition);

            if ($mismatch !== null) {
                $results[$key] = new PatchResult($definition, PatchResult::OTHER_VERSION, $mismatch);
                continue;
            }

            $patches = $this->filePatches($definition);
            if (is_string($patches)) {
                $this->errors[] = sprintf('%s: patch "%s" cannot be read: %s (%s)', $definition->owner(), $definition->label(), $patches, $definition->sourceName());
                $results[$key] = new PatchResult($definition, PatchResult::UNREADABLE, $patches);
                continue;
            }

            $this->files[$key] = $patches;

            if ($definition->skipReason() !== null) {
                $unwanted[$key] = $definition;
            } else {
                $this->wanted[$key] = $definition;
            }

            foreach ($patches as $patch) {
                $this->slots[$this->project->rootFiles()->key($patch->path())][] = ['key' => $key, 'file' => $patch];
            }
        }

        $this->ancestors = $this->ancestry($definitions);
        $this->rebuildChangedFiles($write);

        // Skipped patches come off first and in reverse order: nothing can be reverted while a later patch sits on it.
        foreach (array_reverse($unwanted, true) as $key => $definition) {
            $results[$key] = $this->reverse($definition, $write);
        }

        $broken = [];
        foreach ($this->wanted as $key => $definition) {
            $missing = $write ? $this->firstBroken($definition, $broken) : null;

            if ($missing !== null) {
                $results[$key] = new PatchResult($definition, PatchResult::BLOCKED, sprintf('requires %s, which is not in place', $missing));
                $broken[$key] = $definition->label();
                continue;
            }

            $results[$key] = $this->forward($definition, $write);

            if ($write && !$results[$key]->isComplete()) {
                $broken[$key] = $definition->label();
            }
        }

        if ($write) {
            $this->updateLedger();
        }

        $ordered = [];
        foreach ($definitions as $definition) {
            $ordered[] = $results[$definition->key()];
        }

        return $ordered;
    }

    private function reset(): void
    {
        $this->errors = [];
        $this->files = [];
        $this->slots = [];
        $this->ancestors = [];
        $this->wanted = [];
        $this->pendingRebuild = [];
        $this->rebuilt = [];
        $this->vacated = [];
        $this->written = [];
        $this->touched = [];
        $this->placementStates = [];
    }

    /**
     * @param Definition[] $definitions
     *
     * @return array<string, array<string, true>>
     */
    private function ancestry(array $definitions): array
    {
        $ancestors = [];

        foreach ($definitions as $definition) {
            $set = [];
            foreach ($definition->requires() as $required) {
                $set[$required] = true;
                $set += $ancestors[$required] ?? [];
            }
            $ancestors[$definition->key()] = $set;
        }

        return $ancestors;
    }

    /**
     * @param array<string, string> $broken
     */
    private function firstBroken(Definition $definition, array $broken): ?string
    {
        foreach ($definition->requires() as $required) {
            if (isset($broken[$required])) {
                return $broken[$required];
            }
        }

        return null;
    }

    /**
     * Replaces what this plugin wrote earlier when the patches meant for a file are no longer the ones on it: a
     * patch re-issued with other content, or a patch the project now skips. Only for a file whose hash still
     * matches the ledger, and only when the old steps come off and the new ones go on cleanly on a scratch copy.
     */
    private function rebuildChangedFiles(bool $write): void
    {
        $paths = [];

        foreach (array_keys($this->files) as $key) {
            foreach ($this->files[$key] as $patch) {
                foreach ($this->placements($patch) as $path) {
                    if ($path !== null) {
                        $paths[$path] = $this->project->rootFiles()->key($patch->path());
                    }
                }
            }
        }

        foreach ($paths as $path => $fileKey) {
            $entry = $this->ledger->entry($path);

            if ($entry === null || $this->project->hash($path) !== $entry['sha256']) {
                continue;
            }

            $wanted = $this->wantedOn($fileKey);
            $recorded = array_column($entry['steps'], 'fingerprint');
            $current = array_map(static function (array $step): string {
                return $step['file']->fingerprint();
            }, $wanted);

            if (array_slice($current, 0, count($recorded)) === $recorded) {
                continue;
            }

            $content = $this->rebuild($path, $entry['steps'], $wanted);
            if ($content === false) {
                continue;
            }

            if (!$write) {
                $this->pendingRebuild[$path] = true;
                continue;
            }

            if ($this->writeFile($path, $content)) {
                $this->rebuilt[$path] = true;
                $this->written[$path] = true;
            }
        }
    }

    /**
     * @param array<int, array{patch: string, fingerprint: string, diff: string}> $recorded
     * @param array<int, array{key: string, file: FilePatch}> $wanted
     *
     * @return string|false|null the new content, null for a file that should not exist, false when it cannot be done
     */
    private function rebuild(string $path, array $recorded, array $wanted)
    {
        $scratch = Scratch::of($this->project->absolute($path));

        foreach (array_reverse($recorded) as $step) {
            try {
                $old = $this->parser->parse($step['diff'])[0] ?? null;
            } catch (\UnexpectedValueException $e) {
                return false;
            }

            if ($old === null || !$scratch->revert($old)) {
                return false;
            }
        }

        foreach ($wanted as $step) {
            if (!$scratch->apply($step['file'])) {
                return false;
            }
        }

        return $scratch->contents();
    }

    private function reverse(Definition $definition, bool $write): PatchResult
    {
        $key = $definition->key();
        $targets = [];

        foreach ($this->files[$key] as $index => $patch) {
            $states = [];

            foreach ($this->placements($patch) as $named => $path) {
                if ($path === null) {
                    $states[$named] = [TargetResult::CONFLICT, $named . ' leads outside the project'];
                    continue;
                }

                $this->touched[$path] = $this->project->rootFiles()->key($patch->path());
                $diff = $patch->renderFor($path);

                if (!$this->git->isApplied($diff) && !$this->isAppliedUnder($key, $patch, $path)) {
                    $exists = $this->project->isFile($path);
                    $states[$path] = $exists && !$patch->creates() && !$this->git->canApply($diff) && !$this->isApplicableOver($key, $patch, $path)
                        ? [TargetResult::CONFLICT, 'cannot tell whether it is in place: ' . $this->git->lastError()]
                        : [TargetResult::ABSENT, null];
                    continue;
                }

                if (!$write) {
                    $states[$path] = [TargetResult::UNWANTED, 'still in place; magento-patches:apply removes it'];
                    continue;
                }

                if (!$this->git->revert($diff)) {
                    $states[$path] = [TargetResult::CONFLICT, $this->git->lastError()];
                    continue;
                }

                $this->written[$path] = true;
                if ($patch->creates()) {
                    $this->vacated[$path] = $definition->label();
                }
                $states[$path] = [TargetResult::REVERTED, null];
            }

            $targets[] = $this->combine($patch, $states, $key, $index);
        }

        return new PatchResult($definition, PatchResult::SKIPPED, $definition->skipReason(), $targets);
    }

    private function forward(Definition $definition, bool $write): PatchResult
    {
        $key = $definition->key();
        $plan = [];
        $conflict = false;

        foreach ($this->files[$key] as $index => $patch) {
            foreach ($this->placements($patch) as $named => $path) {
                if ($path === null) {
                    $plan[$index][$named] = [TargetResult::CONFLICT, $named . ' leads outside the project'];
                    $conflict = true;
                    continue;
                }

                $this->touched[$path] = $this->project->rootFiles()->key($patch->path());
                $plan[$index][$path] = $this->classify($key, $patch, $path, $write);
                $conflict = $conflict || $plan[$index][$path][0] === TargetResult::CONFLICT;
            }
        }

        // A patch goes on whole or not at all: Adobe's fixes move code between files, and half of one can fatal the site.
        if ($write && !$conflict) {
            $plan = $this->applyPlan($definition, $plan);
        }

        $targets = [];
        foreach ($this->files[$key] as $index => $patch) {
            $targets[] = $this->combine($patch, $plan[$index] ?? [], $key, $index);
        }

        return new PatchResult($definition, PatchResult::APPLIES, null, $targets);
    }

    /**
     * @param array<int, array<string, array{0: string, 1: string|null}>> $plan
     *
     * @return array<int, array<string, array{0: string, 1: string|null}>>
     */
    private function applyPlan(Definition $definition, array $plan): array
    {
        $done = [];

        foreach ($plan as $index => $paths) {
            $patch = $this->files[$definition->key()][$index];

            foreach ($paths as $path => $verdict) {
                if ($verdict[0] !== TargetResult::MISSING) {
                    continue;
                }

                $diff = $patch->renderFor($path);

                // A hardlinked root copy can already carry the change written through its twin.
                if ($this->git->isApplied($diff)) {
                    $plan[$index][$path] = [TargetResult::PRESENT, null];
                    continue;
                }

                $before = $this->project->hash($path);
                $applied = $this->git->apply($diff);

                // git apply can exit 0 having written nothing, so only a changed file counts as applied.
                if (!$applied || $this->project->hash($path) === $before) {
                    $plan[$index][$path] = [TargetResult::CONFLICT, $applied ? 'git apply reported success but the file did not change' : $this->git->lastError()];

                    foreach ($done as [$doneIndex, $donePath, $doneDiff]) {
                        $plan[$doneIndex][$donePath] = $this->git->revert($doneDiff)
                            ? [TargetResult::MISSING, sprintf('rolled back because %s would not take the patch', $path)]
                            : [TargetResult::CONFLICT, sprintf('applied, then %s failed and this could not be rolled back', $path)];
                    }

                    return $plan;
                }

                $this->written[$path] = true;
                $plan[$index][$path] = [TargetResult::APPLIED, null];
                $done[] = [$index, $path, $diff];
            }
        }

        return $plan;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function classify(string $key, FilePatch $patch, string $path, bool $write): array
    {
        if (isset($this->pendingRebuild[$path])) {
            return [TargetResult::MISSING, 'the patch changed since it was applied; magento-patches:apply rebuilds this file'];
        }

        if ($patch->creates()) {
            foreach ($this->related($key, $patch, false) as $later) {
                if ($later->creates()) {
                    return [TargetResult::NOT_COVERED, 'replaced by a later patch in this chain that creates the file again'];
                }
            }
        }

        $diff = $patch->renderFor($path);

        // Asked first, and it wins: boilerplate context can match at a second offset, so on a patched file the
        // forward check can pass too, and applying again would duplicate the change.
        if ($this->git->isApplied($diff) || $this->isAppliedUnder($key, $patch, $path)) {
            return [TargetResult::PRESENT, null];
        }

        if ($this->git->canApply($diff)) {
            return [TargetResult::MISSING, null];
        }

        $error = $this->git->lastError();

        // A report has not applied the earlier links yet; an apply has, so it asks the real file.
        if (!$write && $this->isApplicableOver($key, $patch, $path)) {
            return [TargetResult::MISSING, null];
        }

        if (!$this->project->isFile($path) && !$patch->creates()) {
            return isset($this->vacated[$path])
                ? [TargetResult::CONFLICT, sprintf('removed when %s was reverted', $this->vacated[$path])]
                : [TargetResult::NOT_COVERED, $this->project->whyAbsent($patch)];
        }

        return [TargetResult::CONFLICT, $error];
    }

    /**
     * Later links of a chain may rewrite this patch's lines, so it is asked again with those taken off a copy.
     */
    private function isAppliedUnder(string $key, FilePatch $patch, string $path): bool
    {
        $later = $this->related($key, $patch, false);

        if ($later === []) {
            return false;
        }

        $scratch = Scratch::of($this->project->absolute($path));
        foreach (array_reverse($later) as $link) {
            if ($scratch->isApplied($link)) {
                $scratch->revert($link);
            }
        }

        return $scratch->isApplied($patch);
    }

    private function isApplicableOver(string $key, FilePatch $patch, string $path): bool
    {
        $earlier = $this->related($key, $patch, true);

        if ($earlier === []) {
            return false;
        }

        $scratch = Scratch::of($this->project->absolute($path));
        foreach ($earlier as $link) {
            if (!$scratch->isApplied($link) && $scratch->canApply($link)) {
                $scratch->apply($link);
            }
        }

        return $scratch->canApply($patch);
    }

    /**
     * @return FilePatch[] the same file's patches from the definitions this one is built on, or that are built on it
     */
    private function related(string $key, FilePatch $patch, bool $earlier): array
    {
        $links = [];

        foreach ($this->slots[$this->project->rootFiles()->key($patch->path())] ?? [] as $slot) {
            if ($slot['key'] === $key) {
                continue;
            }

            $isLink = $earlier ? isset($this->ancestors[$key][$slot['key']]) : isset($this->ancestors[$slot['key']][$key]);
            if ($isLink) {
                $links[] = $slot['file'];
            }
        }

        return $links;
    }

    /**
     * @return array<string, string|null> path as named => writable path, or null when it leads outside the project
     */
    private function placements(FilePatch $patch): array
    {
        $named = [$patch->path()];
        $counterpart = $this->project->rootFiles()->counterpart($patch->path());
        if ($counterpart !== null) {
            $named[] = $counterpart;
        }

        $placements = [];
        $seen = [];
        foreach ($named as $name) {
            $writable = $this->project->writablePath($name);

            // Under the symlink deploy strategy both names lead to one file.
            if ($writable !== null && isset($seen[$writable])) {
                continue;
            }
            if ($writable !== null) {
                $seen[$writable] = true;
            }
            $placements[$name] = $writable;
        }

        return $placements;
    }

    /**
     * @return array<int, array{key: string, file: FilePatch}>
     */
    private function wantedOn(string $fileKey): array
    {
        return array_values(array_filter($this->slots[$fileKey] ?? [], function (array $slot): bool {
            return isset($this->wanted[$slot['key']]);
        }));
    }

    /**
     * @param array<string, array{0: string, 1: string|null}> $states path => verdict
     */
    private function combine(FilePatch $patch, array $states, string $key, int $index): TargetResult
    {
        $state = TargetResult::NOT_COVERED;
        $reason = null;
        $written = [];

        foreach ($states as $path => [$pathState, $pathReason]) {
            if ($pathState === TargetResult::PRESENT && isset($this->rebuilt[$path])) {
                $pathState = TargetResult::APPLIED;
                $pathReason = 'rebuilt with the current version of the patch';
            }

            $this->placementStates[$key . '|' . $index . '|' . $path] = $pathState;

            if (in_array($pathState, [TargetResult::APPLIED, TargetResult::REVERTED], true)) {
                $written[] = $path;
            }

            if (self::RANK[$pathState] > self::RANK[$state] || $reason === null && $pathState === $state) {
                $state = $pathState;
                $reason = $pathReason === null || $path === $patch->path() ? $pathReason : $path . ': ' . $pathReason;
            }
        }

        return new TargetResult($patch->path(), $state, $reason, $written);
    }

    private function updateLedger(): void
    {
        foreach ($this->touched as $path => $fileKey) {
            if (!isset($this->written[$path]) && $this->ledger->entry($path) === null) {
                continue;
            }

            $steps = [];
            foreach ($this->slots[$fileKey] ?? [] as $slot) {
                if (!isset($this->wanted[$slot['key']])) {
                    continue;
                }

                $index = array_search($slot['file'], $this->files[$slot['key']], true);
                $state = $this->placementStates[$slot['key'] . '|' . $index . '|' . $path] ?? null;

                if ($state === TargetResult::PRESENT || $state === TargetResult::APPLIED) {
                    $steps[] = ['patch' => $this->wanted[$slot['key']]->owner() . ' ' . $this->wanted[$slot['key']]->label(), 'file' => $slot['file']];
                }
            }

            $hash = $this->project->hash($path);
            if ($steps === [] || $hash === null) {
                $this->ledger->forget($path);
                continue;
            }

            $this->ledger->record($path, $hash, $steps, ($this->clock)());
        }

        $this->ledger->save();
    }

    /**
     * @param string|null $content null deletes the file
     */
    private function writeFile(string $path, ?string $content): bool
    {
        $file = $this->project->absolute($path);

        if ($content === null) {
            return !is_file($file) || @unlink($file);
        }

        $mode = is_file($file) ? fileperms($file) & 0777 : null;
        if (@file_put_contents($file, $content) === false) {
            return false;
        }

        if ($mode !== null) {
            @chmod($file, $mode);
        }

        return true;
    }
}
