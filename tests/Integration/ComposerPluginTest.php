<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Integration;

use Disrex\MagentoPatchInstaller\Lock\LockFile;
use Disrex\MagentoPatchInstaller\Tests\Support\ComposerProject;
use Disrex\MagentoPatchInstaller\Tests\Support\Diffs;
use PHPUnit\Framework\TestCase;

final class ComposerPluginTest extends TestCase
{
    /** @var ComposerProject|null */
    private $project;

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            $this->project->remove();
        }
    }

    public function testUpdateAppliesThePatchAndWritesTheLockFile(): void
    {
        $project = $this->project = new ComposerProject();

        [$code, $output] = $project->composer('update');

        self::assertSame(0, $code, $output);
        self::assertSame(ComposerProject::FIXED, $project->read(ComposerProject::FILE));
        $lock = json_decode((string) $project->read(LockFile::NAME), true);
        self::assertSame('FIX-1', $lock['patches'][0]['id']);
        self::assertSame([ComposerProject::FILE], $lock['patches'][0]['files']);
    }

    public function testInstallNeverWritesTheLockFileButWarnsWhenItIsStale(): void
    {
        $project = $this->project = new ComposerProject();
        $project->composer('update');
        $project->write(LockFile::NAME, '{"content-hash": "outdated", "patches": []}');

        [$code, $output] = $project->composer('install');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('does not match', $output);
        self::assertSame('{"content-hash": "outdated", "patches": []}', $project->read(LockFile::NAME));
        self::assertSame(1, $project->composer('magento-patches:lock', '--check')[0]);
        self::assertSame(0, $project->composer('magento-patches:lock')[0]);
        self::assertSame(0, $project->composer('magento-patches:lock', '--check')[0]);
    }

    public function testAPatchThatCannotGoOnFailsComposerUnlessTheProjectAllowsIt(): void
    {
        $project = $this->project = new ComposerProject(Diffs::make(ComposerProject::FILE, "<?php\n\nreturn 'other';\n", ComposerProject::FIXED));

        [$failed, $output] = $project->composer('update');
        $project->settings(['allow-unpatched' => true]);
        [$allowed] = $project->composer('install');

        self::assertNotSame(0, $failed);
        self::assertStringContainsString('Security patches are not in place', $output);
        self::assertSame(0, $allowed);
    }

    public function testVerifyPrintsParseableJsonAndExitsOneAfterARevert(): void
    {
        $project = $this->project = new ComposerProject();
        $project->composer('update');
        $project->write(ComposerProject::FILE, ComposerProject::PLAIN);

        [$code, $output] = $project->composer('magento-patches:verify', '--json');
        $report = json_decode($output, true);

        self::assertSame(1, $code);
        self::assertIsArray($report, $output);
        self::assertSame(1, $report['exit_code']);
        self::assertSame('missing', $report['patches'][0]['targets'][0]['state']);
        self::assertSame(ComposerProject::PLAIN, $project->read(ComposerProject::FILE), 'verify writes nothing');
    }

    public function testDumpAutoloadPutsBackAPatchThatAReinstallTookAway(): void
    {
        $project = $this->project = new ComposerProject();
        $project->composer('update');
        $project->composer('reinstall', '--no-plugins', 'acme/lib');
        self::assertSame(ComposerProject::PLAIN, $project->read(ComposerProject::FILE));

        [$code, $output] = $project->composer('dump-autoload');

        self::assertSame(0, $code, $output);
        self::assertSame(ComposerProject::FIXED, $project->read(ComposerProject::FILE));
    }

    public function testAnUntrustedPackageWithPatchesIsAConfigurationError(): void
    {
        $project = $this->project = new ComposerProject();
        $project->settings(['trust' => []]);

        [$code, $output] = $project->composer('update');

        self::assertNotSame(0, $code);
        self::assertStringContainsString('disrex/test-patches declares patches but is not trusted', $output);
        self::assertSame(ComposerProject::PLAIN, $project->read(ComposerProject::FILE));
    }

    public function testApplyDryRunWritesNothing(): void
    {
        $project = $this->project = new ComposerProject();
        $project->settings(['dry-run' => true]);
        $project->composer('update');
        $project->settings(['dry-run' => false]);

        [$code, $output] = $project->composer('magento-patches:apply', '--dry-run');

        self::assertSame(1, $code, $output);
        self::assertSame(ComposerProject::PLAIN, $project->read(ComposerProject::FILE));
    }
}
