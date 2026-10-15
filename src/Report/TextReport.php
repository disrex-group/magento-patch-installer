<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Report;

use Disrex\MagentoPatchInstaller\Advice\Advisory;
use Disrex\MagentoPatchInstaller\Config\Catalog;
use Disrex\MagentoPatchInstaller\Engine\Engine;
use Disrex\MagentoPatchInstaller\Engine\PatchResult;
use Disrex\MagentoPatchInstaller\Engine\TargetResult;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class TextReport
{
    /**
     * @return string[]
     */
    public static function results(Summary $summary, bool $verbose, bool $problemsOnly = false): array
    {
        $rows = [];
        $otherVersions = [];
        $dormant = [];

        foreach ($summary->results() as $result) {
            if ($result->status() === PatchResult::OTHER_VERSION || $result->status() === PatchResult::UNREADABLE) {
                $otherVersions[] = $result;
                continue;
            }

            if ($result->status() === PatchResult::NOT_ENABLED && self::untouched($result)) {
                $dormant[] = $result;
                continue;
            }

            $badge = self::badge($result);
            if ($problemsOnly && !$verbose && in_array($badge, ['ok', 'applied', 'reverted', 'skipped', 'left', 'n/a'], true)) {
                continue;
            }

            $rows[] = [$badge, $result];
        }

        $idWidth = 0;
        $titleWidth = 0;
        foreach ($rows as [, $result]) {
            $idWidth = max($idWidth, strlen($result->definition()->id()));
            $titleWidth = max($titleWidth, strlen(self::title($result)));
        }

        $lines = [];
        foreach ($rows as [$badge, $result]) {
            $definition = $result->definition();
            $lines[] = rtrim(sprintf(
                '  %s  %s  %s%s',
                self::colour($badge, str_pad($badge, 8)),
                str_pad($definition->id(), $idWidth),
                self::escape(str_pad(self::title($result), $titleWidth)),
                self::count($result)
            ));

            if ($result->reason() !== null && $result->status() !== PatchResult::APPLIES) {
                $lines[] = '      ' . self::escape($result->reason());
            }

            if ($verbose && $definition->enableReason() !== null) {
                $lines[] = '      enabled: ' . self::escape($definition->enableReason());
            }

            foreach ($result->targets() as $target) {
                if ($verbose || $target->isProblem() || $target->isOutstanding()) {
                    $lines[] = sprintf(
                        '      %-11s %s%s',
                        $target->state(),
                        self::escape($target->path()),
                        $target->reason() === null ? '' : "\n" . self::indent(self::escape($target->reason()), 18)
                    );
                }
            }
        }

        if ($dormant !== []) {
            $lines[] = sprintf('  %d optional patch%s not enabled%s', count($dormant), count($dormant) === 1 ? '' : 'es', $verbose ? ':' : ' (-v lists them)');

            if ($verbose) {
                foreach ($dormant as $result) {
                    $lines[] = '      ' . self::escape($result->definition()->label());
                }
            }
        }

        if ($otherVersions !== []) {
            $lines[] = sprintf('  %d patch%s for other versions%s', count($otherVersions), count($otherVersions) === 1 ? '' : 'es', $verbose ? ':' : ' (-v lists them)');

            if ($verbose) {
                foreach ($otherVersions as $result) {
                    $lines[] = sprintf('      %s: %s', self::escape($result->definition()->label()), self::escape((string) $result->reason()));
                }
            }
        }

        return $lines;
    }

    /**
     * @param Advisory[] $advisories
     */
    public static function closing(Summary $summary, array $advisories): string
    {
        $code = $summary->exitCode();
        $inPlace = $summary->countTargets([TargetResult::PRESENT, TargetResult::APPLIED]);
        $total = $inPlace + $summary->countTargets([TargetResult::MISSING, TargetResult::CONFLICT]);

        if ($code === Summary::CONFIG_ERROR) {
            return '<error> FAIL </error> the patch configuration has errors (above); nothing here can be called patched';
        }

        if ($code === Summary::CONFLICT) {
            return sprintf('<error> FAIL </error> %d file%s match neither side of a patch; nothing was overwritten', $summary->countTargets([TargetResult::CONFLICT]), $summary->countTargets([TargetResult::CONFLICT]) === 1 ? '' : 's');
        }

        if ($code === Summary::MISSING) {
            return sprintf('<error> FAIL </error> %d of %d files in place; run composer magento-patches:apply', $inPlace, $total);
        }

        $line = sprintf('%d of %d files in place', $inPlace, $total);

        return $advisories === [] ? '<info>  OK  </info> ' . $line : '<comment> WARN </comment> ' . $line . ', see the advice above';
    }

    /**
     * @param Advisory[] $advisories
     *
     * @return string[]
     */
    public static function advisories(array $advisories): array
    {
        return array_map(static function (Advisory $advisory): string {
            return sprintf('  %s %s', $advisory->level() === Advisory::CRITICAL ? '<error>!</error>' : '<comment>!</comment>', self::escape($advisory->message()));
        }, $advisories);
    }

    /**
     * @param array{product: array{package: string, version: string}|null, endOfLife: string|null, sources: array<string, int>, lastWrite: string|null} $store
     *
     * @return string[]
     */
    public static function store(array $store): array
    {
        $sources = [];
        foreach ($store['sources'] as $owner => $count) {
            $sources[] = sprintf('%s (%d)', $owner, $count);
        }

        return [
            '  Magento        ' . ($store['product'] === null ? 'no Magento product package found' : $store['product']['package'] . ' ' . $store['product']['version']),
            '  End of life    ' . ($store['endOfLife'] ?? 'unknown to this release of the plugin'),
            '  Patch sources  ' . ($sources === [] ? 'none' : self::escape(implode(', ', $sources))),
            '  Last write     ' . ($store['lastWrite'] ?? 'never'),
        ];
    }

    /**
     * @return string[]
     */
    public static function catalog(Catalog $catalog, Engine $engine, bool $verbose): array
    {
        $lines = [];
        $owner = null;

        foreach ($catalog->definitions() as $definition) {
            if ($definition->owner() !== $owner) {
                $owner = $definition->owner();
                $lines[] = '';
                $lines[] = '  <info>' . self::escape($owner) . '</info>';
            }

            $files = $engine->filePatches($definition);
            $why = $definition->skipReason()
                ?? ($definition->isDormant() ? 'optional, not enabled' : null)
                ?? $engine->baseMismatch($definition)
                ?? (is_string($files) ? $files : null);
            $lines[] = sprintf(
                '    %-14s %-24s %-32s %3s file%s  %s',
                self::escape($definition->line() ?? 'any'),
                self::escape($definition->id()),
                self::escape(trim($definition->title() . ($definition->isOptional() ? ' [optional]' : ''))),
                is_array($files) ? (string) count($files) : '?',
                is_array($files) && count($files) === 1 ? ' ' : 's',
                $why === null ? '<info>applies here</info>' : ($verbose ? self::escape($why) : '')
            );

            if ($verbose && $definition->enableReason() !== null) {
                $lines[] = '        enabled: ' . self::escape($definition->enableReason());
            }

            if ($verbose && $definition->description() !== null) {
                $lines[] = '        ' . self::escape($definition->description());
            }
        }

        return $lines;
    }

    private static function title(PatchResult $result): string
    {
        $definition = $result->definition();

        return trim(($definition->title() ?? '') . ($definition->line() !== null ? ' [' . $definition->line() . ']' : '') . ($definition->isOptional() ? ' [optional]' : ''));
    }

    private static function untouched(PatchResult $result): bool
    {
        foreach ($result->targets() as $target) {
            if (!in_array($target->state(), [TargetResult::ABSENT, TargetResult::NOT_COVERED], true)) {
                return false;
            }
        }

        return true;
    }

    private static function badge(PatchResult $result): string
    {
        if ($result->status() === PatchResult::BLOCKED) {
            return 'BLOCKED';
        }

        $states = array_map(static function (TargetResult $target): string {
            return $target->state();
        }, $result->targets());

        foreach ([TargetResult::CONFLICT => 'CONFLICT', TargetResult::MISSING => 'MISSING', TargetResult::UNWANTED => 'UNWANTED'] as $state => $badge) {
            if (in_array($state, $states, true)) {
                return $badge;
            }
        }

        if ($result->status() === PatchResult::SKIPPED) {
            return in_array(TargetResult::REVERTED, $states, true) ? 'reverted' : 'skipped';
        }

        if ($result->status() === PatchResult::NOT_ENABLED) {
            return in_array(TargetResult::REVERTED, $states, true) ? 'reverted' : (in_array(TargetResult::LEFT, $states, true) ? 'left' : 'off');
        }

        if (in_array(TargetResult::APPLIED, $states, true)) {
            return 'applied';
        }

        return in_array(TargetResult::PRESENT, $states, true) ? 'ok' : 'n/a';
    }

    private static function count(PatchResult $result): string
    {
        if ($result->status() !== PatchResult::APPLIES) {
            return '';
        }

        $relevant = 0;
        $inPlace = 0;
        foreach ($result->targets() as $target) {
            if ($target->state() !== TargetResult::NOT_COVERED) {
                $relevant++;
                $inPlace += $target->isInPlace() ? 1 : 0;
            }
        }

        return sprintf('  %d/%d', $inPlace, $relevant);
    }

    private static function colour(string $badge, string $text): string
    {
        if ($badge === strtoupper($badge)) {
            return '<error>' . $text . '</error>';
        }

        return in_array($badge, ['ok', 'applied'], true) ? '<info>' . $text . '</info>' : '<comment>' . $text . '</comment>';
    }

    private static function indent(string $text, int $spaces): string
    {
        return str_repeat(' ', $spaces) . str_replace("\n", "\n" . str_repeat(' ', $spaces), $text);
    }

    public static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
