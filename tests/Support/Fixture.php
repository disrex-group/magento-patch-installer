<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Support;

use Disrex\MagentoPatchInstaller\Config\Catalog;
use Disrex\MagentoPatchInstaller\Config\DefinitionLoader;
use Disrex\MagentoPatchInstaller\Config\Settings;
use Disrex\MagentoPatchInstaller\Engine\Engine;
use Disrex\MagentoPatchInstaller\Engine\Ledger;
use Disrex\MagentoPatchInstaller\Project\Project;
use Disrex\MagentoPatchInstaller\Report\Summary;

/**
 * A project with one trusted patch package, disrex/patches, at vendor/disrex/patches.
 */
final class Fixture
{
    public const PACKAGE_DIR = 'vendor/disrex/patches';

    /** @var Workspace */
    public $workspace;

    /** @var array<string, string> */
    private $versions = ['x/project' => '1.0.0', 'acme/lib' => '1.0.0', 'disrex/patches' => '1.0.0'];

    /** @var array<string, string> */
    private $replacedBy = [];

    /** @var array<int, array{install: string, map: mixed}> */
    private $rootMaps = [];

    /** @var array<string, mixed> */
    private $config = [];

    /** @var array<string, mixed> */
    private $rootConfig = ['trust' => ['disrex/*']];

    public function __construct(?Workspace $workspace = null)
    {
        $this->workspace = $workspace ?? Workspace::create();
    }

    public function file(string $path, string $content, int $mode = 0644): self
    {
        $this->workspace->write($path, $content, $mode);

        return $this;
    }

    public function read(string $path): ?string
    {
        return $this->workspace->read($path);
    }

    /**
     * @param array<string, string> $base
     */
    public function patch(string $id, string $diff, array $base = ['acme/lib' => '*'], array $extra = []): self
    {
        $this->workspace->write(self::PACKAGE_DIR . '/' . $id . '.diff', $diff);
        $entries = array_filter($this->config['patches'] ?? [], static function (array $entry) use ($id): bool {
            return $entry['id'] !== $id;
        });
        $entries[] = array_merge(['id' => $id, 'source' => $id . '.diff', 'base' => $base], $extra);
        $this->config['patches'] = array_values($entries);

        return $this;
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string> $patches id => diff, in chain order
     */
    public function line(string $name, array $base, array $patches, bool $cumulative = true): self
    {
        $entries = [];
        foreach ($patches as $id => $diff) {
            $this->workspace->write(self::PACKAGE_DIR . '/' . $name . '/' . $id . '.diff', $diff);
            $entries[] = ['id' => $id, 'source' => $name . '/' . $id . '.diff'];
        }
        $this->config['lines'][$name] = ['base' => $base, 'cumulative' => $cumulative, 'patches' => $entries];

        return $this;
    }

    public function skip(string $identifier, string $reason = 'test'): self
    {
        $this->rootConfig['sources']['disrex/patches']['skip'][$identifier] = $reason;

        return $this;
    }

    public function version(string $package, ?string $version): self
    {
        if ($version === null) {
            unset($this->versions[$package]);
        } else {
            $this->versions[$package] = $version;
        }

        return $this;
    }

    public function replaced(string $package, string $by): self
    {
        $this->replacedBy[$package] = $by;

        return $this;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $map
     */
    public function rootMap(string $install, array $map): self
    {
        $this->rootMaps[] = ['install' => $install, 'map' => $map];

        return $this;
    }

    public function catalog(): Catalog
    {
        return (new DefinitionLoader())->load(
            new Settings([Settings::KEY => $this->rootConfig]),
            'x/project',
            $this->workspace->root(),
            [['name' => 'disrex/patches', 'config' => $this->config, 'dir' => $this->workspace->path(self::PACKAGE_DIR)]]
        );
    }

    public function project(): Project
    {
        return new Project($this->workspace->root(), $this->versions, $this->replacedBy, $this->rootMaps);
    }

    public function run(bool $write): Summary
    {
        $catalog = $this->catalog();
        $engine = new Engine($this->project(), new Ledger($this->workspace->root()));
        $results = $engine->run($catalog->definitions(), $write);

        return new Summary($results, array_merge($catalog->errors(), $engine->errors()));
    }

    public function remove(): void
    {
        $this->workspace->remove();
    }
}
