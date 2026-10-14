<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Advice;

/**
 * Dates come from a file shipped in the package, never from the network while patching: a store behind a
 * firewall would lose the warning without anyone noticing. bin/refresh-eol rewrites the file.
 */
final class EndOfLife
{
    private const WARN_DAYS = 182;

    // Commerce installs both product packages, so the Enterprise one has to be found first.
    private const PRODUCTS = [
        'magento/product-enterprise-edition',
        'magento/product-community-edition',
        'mage-os/product-community-edition',
    ];

    /** @var array<string, array<string, string>> */
    private $dates = [];

    /** @var string|null */
    private $generated;

    public function __construct(?string $file = null)
    {
        $file = $file ?? dirname(__DIR__, 2) . '/resources/magento-eol.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (is_array($data) && is_array($data['distributions'] ?? null)) {
            $this->dates = $data['distributions'];
            $this->generated = isset($data['generated']) ? (string) $data['generated'] : null;
        }
    }

    public function generated(): ?string
    {
        return $this->generated;
    }

    /**
     * @param array<string, string> $versions
     *
     * @return array{package: string, version: string}|null
     */
    public static function product(array $versions): ?array
    {
        foreach (self::PRODUCTS as $package) {
            if (isset($versions[$package])) {
                return ['package' => $package, 'version' => $versions[$package]];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $versions
     */
    public function date(array $versions): ?string
    {
        $product = self::product($versions);

        return $product === null ? null : ($this->dates[$product['package']][$product['version']] ?? null);
    }

    /**
     * @param array<string, string> $versions
     */
    public function advisory(array $versions, \DateTimeImmutable $today): ?Advisory
    {
        $product = self::product($versions);
        $date = $this->date($versions);
        $end = $date === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($product === null || $date === null || $end === false) {
            return null;
        }

        $days = (int) $today->setTime(0, 0)->diff($end)->format('%r%a');
        $name = $product['package'] . ' ' . $product['version'];

        if ($days < 0) {
            return new Advisory(Advisory::CRITICAL, sprintf(
                '%s reached end of life on %s, %d days ago. Adobe publishes no more security fixes for it; only an upgrade closes what comes next.',
                $name,
                $date,
                -$days
            ));
        }

        if ($days <= self::WARN_DAYS) {
            return new Advisory(Advisory::WARNING, sprintf('%s reaches end of life on %s, in %d days. Plan the upgrade.', $name, $date, $days));
        }

        return null;
    }
}
