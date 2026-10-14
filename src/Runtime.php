<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller;

use Composer\Composer;
use Disrex\MagentoPatchInstaller\Advice\Advisor;
use Disrex\MagentoPatchInstaller\Advice\Advisory;
use Disrex\MagentoPatchInstaller\Advice\EndOfLife;
use Disrex\MagentoPatchInstaller\Config\Catalog;
use Disrex\MagentoPatchInstaller\Config\DefinitionLoader;
use Disrex\MagentoPatchInstaller\Config\Settings;
use Disrex\MagentoPatchInstaller\Engine\Engine;
use Disrex\MagentoPatchInstaller\Engine\Ledger;
use Disrex\MagentoPatchInstaller\Lock\LockFile;
use Disrex\MagentoPatchInstaller\Project\Project;
use Disrex\MagentoPatchInstaller\Report\Summary;

final class Runtime
{
    /** @var Settings */
    private $settings;

    /** @var Catalog */
    private $catalog;

    /** @var Project */
    private $project;

    /** @var Ledger */
    private $ledger;

    /** @var Engine */
    private $engine;

    /** @var Advisor */
    private $advisor;

    private function __construct(Settings $settings, Catalog $catalog, Project $project)
    {
        $this->settings = $settings;
        $this->catalog = $catalog;
        $this->project = $project;
        $this->ledger = new Ledger($project->root());
        $this->engine = new Engine($project, $this->ledger);
        $this->advisor = new Advisor();
    }

    public static function fromComposer(Composer $composer): self
    {
        $root = $composer->getPackage();
        $vendorDir = (string) $composer->getConfig()->get('vendor-dir');
        // vendor-dir is absolute but not canonical, while install paths are; on a symlinked docroot they would never share a prefix.
        $projectDir = realpath(dirname($vendorDir)) ?: dirname($vendorDir);
        $installer = $composer->getInstallationManager();

        $versions = [$root->getName() => $root->getPrettyVersion()];
        $replacedBy = [];
        $packages = [];
        $rootMaps = [];

        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $versions[$package->getName()] = $package->getPrettyVersion();

            foreach ($package->getReplaces() as $link) {
                $replacedBy[$link->getTarget()] = $package->getName();
            }

            $installPath = (string) $installer->getInstallPath($package);
            $installPath = realpath($installPath) ?: $installPath;
            $extra = $package->getExtra();

            $config = isset($extra[Settings::KEY]) && is_array($extra[Settings::KEY]) ? $extra[Settings::KEY] : [];
            $packages[] = ['name' => $package->getName(), 'config' => $config, 'dir' => $installPath];

            if (isset($extra['map']) && strpos($installPath, $projectDir . '/') === 0) {
                $rootMaps[] = ['install' => substr($installPath, strlen($projectDir) + 1), 'map' => $extra['map']];
            }
        }

        $settings = new Settings($root->getExtra());
        $catalog = (new DefinitionLoader())->load($settings, $root->getName(), $projectDir, $packages);

        return new self($settings, $catalog, new Project($projectDir, $versions, $replacedBy, $rootMaps));
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    public function run(bool $write): Summary
    {
        $results = $this->engine->run($this->catalog->definitions(), $write);

        return new Summary($results, array_merge($this->catalog->errors(), $this->engine->errors()));
    }

    public function lockFile(): LockFile
    {
        return new LockFile($this->project->root());
    }

    /**
     * @return array{_readme: string[], content-hash: string, patches: array<int, array<string, mixed>>}
     */
    public function lock(): array
    {
        return LockFile::build($this->catalog, $this->engine, $this->project);
    }

    /**
     * A lock file the project chose to keep but that no longer matches; null when there is nothing to say.
     */
    public function staleLockWarning(): ?string
    {
        $file = $this->lockFile();

        if (!$file->exists() || $file->matches($this->lock())) {
            return null;
        }

        return LockFile::NAME . ' does not match the patches this project takes; run composer magento-patches:lock and commit it.';
    }

    /**
     * @return Advisory[]
     */
    public function advisories(): array
    {
        $advisories = $this->advisor->advise($this->project->versions(), $this->catalog->definitions());
        $stale = $this->staleLockWarning();

        return $stale === null ? $advisories : array_merge($advisories, [new Advisory(Advisory::WARNING, $stale)]);
    }

    /**
     * @return array{product: array{package: string, version: string}|null, endOfLife: string|null, sources: array<string, int>, lastWrite: string|null}
     */
    public function store(): array
    {
        $versions = $this->project->versions();

        return [
            'product' => EndOfLife::product($versions),
            'endOfLife' => $this->advisor->endOfLife()->date($versions),
            'sources' => $this->catalog->owners(),
            'lastWrite' => $this->ledger->lastWrite(),
        ];
    }
}
