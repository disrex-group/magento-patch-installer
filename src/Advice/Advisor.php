<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Advice;

use Composer\Semver\Comparator;
use Disrex\MagentoPatchInstaller\Config\Definition;

final class Advisor
{
    /** @var EndOfLife */
    private $endOfLife;

    public function __construct(?EndOfLife $endOfLife = null)
    {
        $this->endOfLife = $endOfLife ?? new EndOfLife();
    }

    public function endOfLife(): EndOfLife
    {
        return $this->endOfLife;
    }

    /**
     * @param array<string, string> $versions
     * @param Definition[] $definitions
     *
     * @return Advisory[]
     */
    public function advise(array $versions, array $definitions, ?\DateTimeImmutable $today = null): array
    {
        $advisories = [];

        $lifecycle = $this->endOfLife->advisory($versions, $today ?? new \DateTimeImmutable('today'));
        if ($lifecycle !== null) {
            $advisories[] = $lifecycle;
        }

        // Isolated patches only exist for the newest release of a line, so a store one -p behind gets none of them.
        $behind = [];
        foreach ($definitions as $definition) {
            if ($definition->line() === null) {
                continue;
            }

            foreach ($definition->base() as $package => $constraint) {
                $installed = $versions[$package] ?? null;

                if ($installed === null || preg_match('/^\d+(\.\d+)*(-p\d+)?$/', $constraint) !== 1
                    || self::release($constraint) !== self::release($installed) || !Comparator::greaterThan($constraint, $installed)
                ) {
                    continue;
                }

                $behind[$package . $constraint] = new Advisory(Advisory::WARNING, sprintf(
                    '%s is on %s, but the patches here are for %s. They only apply to the newest release of a line, so upgrade to receive them.',
                    $package,
                    $installed,
                    $constraint
                ));
            }
        }

        return array_merge($advisories, array_values($behind));
    }

    private static function release(string $version): string
    {
        return (string) preg_replace('/-p\d+$/', '', $version);
    }
}
