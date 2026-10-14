<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Config;

final class Settings
{
    public const KEY = 'magento-patches';

    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed> $rootExtra the root package's whole "extra" block
     */
    public function __construct(array $rootExtra)
    {
        $this->config = isset($rootExtra[self::KEY]) && is_array($rootExtra[self::KEY]) ? $rootExtra[self::KEY] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @return string[]
     */
    public function trust(): array
    {
        $trust = $this->config['trust'] ?? [];

        return is_array($trust) ? array_values(array_map('strval', array_filter($trust, 'is_scalar'))) : [];
    }

    public function trusts(string $package): bool
    {
        foreach ($this->trust() as $pattern) {
            if (fnmatch($pattern, $package, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    public function allowsUnpatched(): bool
    {
        return !empty($this->config['allow-unpatched']);
    }

    public function isDryRun(): bool
    {
        return !empty($this->config['dry-run']);
    }

    /**
     * @return mixed
     */
    public function sources()
    {
        return $this->config['sources'] ?? [];
    }
}
