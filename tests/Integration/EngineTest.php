<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Integration;

use Disrex\MagentoPatchInstaller\Engine\PatchResult;
use Disrex\MagentoPatchInstaller\Engine\TargetResult;
use Disrex\MagentoPatchInstaller\Report\Summary;
use Disrex\MagentoPatchInstaller\Tests\Support\Diffs;
use Disrex\MagentoPatchInstaller\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private const FILE = 'vendor/acme/lib/src/Escaper.php';
    private const PLAIN = "<?php\n\nreturn 'plain';\n";
    private const FIXED = "<?php\n\nreturn 'escaped';\n";

    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->fixture = new Fixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testAppliesAMissingPatchAndASecondRunWritesNothing(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));

        $first = $this->fixture->run(true);
        $second = $this->fixture->run(true);

        self::assertSame(Summary::OK, $first->exitCode());
        self::assertSame([self::FILE => TargetResult::APPLIED], $this->states($first));
        self::assertSame(self::FIXED, $this->fixture->read(self::FILE));
        self::assertSame([self::FILE => TargetResult::PRESENT], $this->states($second));
    }

    public function testReportingWritesNothingAndExitsOneForAMissingPatch(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));

        $summary = $this->fixture->run(false);

        self::assertSame(Summary::MISSING, $summary->exitCode());
        self::assertSame([self::FILE => TargetResult::MISSING], $this->states($summary));
        self::assertSame(self::PLAIN, $this->fixture->read(self::FILE));
    }

    public function testNoticesAFileThatWasRevertedUnderneathItAndHealsIt(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));
        $this->fixture->run(true);
        $this->fixture->file(self::FILE, self::PLAIN);

        self::assertSame(Summary::MISSING, $this->fixture->run(false)->exitCode());
        self::assertSame(Summary::OK, $this->fixture->run(true)->exitCode());
        self::assertSame(self::FIXED, $this->fixture->read(self::FILE));
    }

    public function testNeverOverwritesAFileThatMatchesNeitherSideOfThePatch(): void
    {
        $local = "<?php\n\nreturn 'edited by hand';\n";
        $this->fixture->file(self::FILE, $local)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));

        $summary = $this->fixture->run(true);

        self::assertSame(Summary::CONFLICT, $summary->exitCode());
        self::assertSame([self::FILE => TargetResult::CONFLICT], $this->states($summary));
        self::assertSame($local, $this->fixture->read(self::FILE));
    }

    public function testAppliesAPatchWholeOrNotAtAll(): void
    {
        $other = 'vendor/acme/lib/src/Other.php';
        $diff = Diffs::make(self::FILE, self::PLAIN, self::FIXED) . Diffs::make($other, "a\n", "b\n");
        $this->fixture->file(self::FILE, self::PLAIN)->file($other, "changed locally\n")->patch('FIX-1', $diff);

        $summary = $this->fixture->run(true);

        self::assertSame(Summary::CONFLICT, $summary->exitCode());
        self::assertSame(self::PLAIN, $this->fixture->read(self::FILE));
    }

    public function testSkipsFilesOfAPackageThatIsNotInstalledOrIsReplaced(): void
    {
        $diff = Diffs::make(self::FILE, self::PLAIN, self::FIXED)
            . Diffs::make('vendor/acme/graphql/Resolver.php', "a\n", "b\n")
            . Diffs::make('vendor/acme/gone/Model.php', "a\n", "b\n");
        $this->fixture->file(self::FILE, self::PLAIN)->replaced('acme/graphql', 'acme/no-graphql')->patch('FIX-1', $diff);

        $summary = $this->fixture->run(true);
        $reasons = $this->reasons($summary);

        self::assertSame(Summary::OK, $summary->exitCode());
        self::assertSame(TargetResult::APPLIED, $this->states($summary)[self::FILE]);
        self::assertSame('acme/graphql is replaced by acme/no-graphql', $reasons['vendor/acme/graphql/Resolver.php']);
        self::assertSame('acme/gone is not installed', $reasons['vendor/acme/gone/Model.php']);
    }

    public function testLeavesPatchesForOtherVersionsAlone(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED), ['acme/lib' => '2.0.0']);

        $summary = $this->fixture->run(true);

        self::assertSame(PatchResult::OTHER_VERSION, $summary->results()[0]->status());
        self::assertSame(self::PLAIN, $this->fixture->read(self::FILE));
    }

    public function testPatchesBothCopiesOfARootMappedFile(): void
    {
        $base = 'vendor/magento/magento2-base';
        $this->fixture
            ->file($base . '/nginx.conf.sample', "listen 80;\n")
            ->file('nginx.conf.sample', "listen 80;\n")
            ->rootMap($base, [['nginx.conf.sample', 'nginx.conf.sample']])
            ->patch('FIX-1', Diffs::make('nginx.conf.sample', "listen 80;\n", "listen 80;\nserver_tokens off;\n"));

        self::assertSame(Summary::OK, $this->fixture->run(true)->exitCode());
        self::assertSame("listen 80;\nserver_tokens off;\n", $this->fixture->read('nginx.conf.sample'));
        self::assertSame("listen 80;\nserver_tokens off;\n", $this->fixture->read($base . '/nginx.conf.sample'));
    }

    public function testReportsARootCopyThatDriftedWithoutHealingIt(): void
    {
        $base = 'vendor/magento/magento2-base';
        $this->fixture
            ->file($base . '/nginx.conf.sample', "listen 80;\n")
            ->file('nginx.conf.sample', "listen 80;\n")
            ->rootMap($base, [['nginx.conf.sample', 'nginx.conf.sample']])
            ->patch('FIX-1', Diffs::make('nginx.conf.sample', "listen 80;\n", "listen 80;\nserver_tokens off;\n"));
        $this->fixture->run(true);
        $this->fixture->file('nginx.conf.sample', "listen 80;\n");

        self::assertSame(Summary::MISSING, $this->fixture->run(false)->exitCode());
        self::assertSame("listen 80;\n", $this->fixture->read('nginx.conf.sample'));
    }

    public function testWritesThroughASymlinkedRootFileOnce(): void
    {
        $base = 'vendor/magento/magento2-base';
        $this->fixture->file($base . '/nginx.conf.sample', "listen 80;\n")->rootMap($base, [['nginx.conf.sample', 'nginx.conf.sample']]);
        symlink($this->fixture->workspace->path($base . '/nginx.conf.sample'), $this->fixture->workspace->path('nginx.conf.sample'));
        $this->fixture->patch('FIX-1', Diffs::make('nginx.conf.sample', "listen 80;\n", "listen 80;\nserver_tokens off;\n"));

        $summary = $this->fixture->run(true);

        self::assertSame(Summary::OK, $summary->exitCode());
        self::assertTrue(is_link($this->fixture->workspace->path('nginx.conf.sample')));
        self::assertSame("listen 80;\nserver_tokens off;\n", $this->fixture->read($base . '/nginx.conf.sample'));
    }

    public function testRecognisesAnEarlierLinkWhoseLinesALaterLinkRewrote(): void
    {
        $july = "<?php\n\nreturn 'july';\n";
        $august = "<?php\n\nreturn 'august';\n";
        $this->fixture->file(self::FILE, self::PLAIN)->line('1.0.0', ['acme/lib' => '1.0.0'], [
            '2026-07-001' => Diffs::make(self::FILE, self::PLAIN, $july),
            '2026-08-001' => Diffs::make(self::FILE, $july, $august),
        ]);

        $report = $this->fixture->run(false);
        $applied = $this->fixture->run(true);
        $verified = $this->fixture->run(false);

        self::assertSame(Summary::MISSING, $report->exitCode(), 'August reads as missing, not as a conflict, before July is on');
        self::assertSame(Summary::OK, $applied->exitCode());
        self::assertSame($august, $this->fixture->read(self::FILE));
        self::assertSame(Summary::OK, $verified->exitCode(), 'July still counts as in place under August');
    }

    public function testBlocksTheRestOfAChainWhenALinkCannotGoOn(): void
    {
        $other = 'vendor/acme/lib/src/Other.php';
        $this->fixture->file(self::FILE, "changed locally\n")->file($other, "a\n")->line('1.0.0', ['acme/lib' => '1.0.0'], [
            '2026-07-001' => Diffs::make(self::FILE, self::PLAIN, self::FIXED),
            '2026-08-001' => Diffs::make($other, "a\n", "b\n"),
        ]);

        $summary = $this->fixture->run(true);

        self::assertSame(PatchResult::BLOCKED, $summary->results()[1]->status());
        self::assertSame("a\n", $this->fixture->read($other));
    }

    public function testTakesASkippedPatchOffAndEverythingBuiltOnIt(): void
    {
        $other = 'vendor/acme/lib/src/Other.php';
        $this->fixture->file(self::FILE, self::PLAIN)->file($other, "a\n")->line('1.0.0', ['acme/lib' => '1.0.0'], [
            '2026-07-001' => Diffs::make(self::FILE, self::PLAIN, self::FIXED),
            '2026-08-001' => Diffs::make($other, "a\n", "b\n"),
        ]);
        $this->fixture->run(true);
        $this->fixture->skip('2026-07-001@1.0.0', 'breaks the checkout');

        $report = $this->fixture->run(false);
        $applied = $this->fixture->run(true);

        self::assertSame(Summary::MISSING, $report->exitCode(), 'a skipped patch still on disk is outstanding');
        self::assertSame(Summary::OK, $applied->exitCode());
        self::assertSame(self::PLAIN, $this->fixture->read(self::FILE));
        self::assertSame("a\n", $this->fixture->read($other));
        self::assertSame(PatchResult::SKIPPED, $applied->results()[1]->status());
    }

    public function testReplacesAPatchThatWasReissuedWithOtherContent(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));
        $this->fixture->run(true);

        $reissued = "<?php\n\nreturn 'escaped twice';\n";
        $this->fixture->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, $reissued));
        $report = $this->fixture->run(false);
        $afterReport = $this->fixture->read(self::FILE);
        $applied = $this->fixture->run(true);

        self::assertSame(Summary::MISSING, $report->exitCode());
        self::assertSame(self::FIXED, $afterReport, 'reporting rebuilds nothing');
        self::assertSame(Summary::OK, $applied->exitCode());
        self::assertSame($reissued, $this->fixture->read(self::FILE));
        self::assertSame(Summary::OK, $this->fixture->run(false)->exitCode());
    }

    public function testDoesNotReplaceAReissuedPatchOnAFileSomeoneChangedSince(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));
        $this->fixture->run(true);
        $edited = self::FIXED . "// local note\n";
        $this->fixture->file(self::FILE, $edited);

        $this->fixture->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, "<?php\n\nreturn 'escaped twice';\n"));
        $summary = $this->fixture->run(true);

        self::assertSame(Summary::CONFLICT, $summary->exitCode());
        self::assertSame($edited, $this->fixture->read(self::FILE));
    }

    public function testCreatesAnExecutableFileThatALaterLinkChanges(): void
    {
        $tool = 'vendor/bin/patch-status';
        $this->fixture->line('1.0.0', ['acme/lib' => '1.0.0'], [
            '2026-07-001' => Diffs::make($tool, null, "#!/usr/bin/env php\n<?php echo 'july';\n", true),
            '2026-08-001' => Diffs::make($tool, "#!/usr/bin/env php\n<?php echo 'july';\n", "#!/usr/bin/env php\n<?php echo 'august';\n", true),
        ]);

        self::assertSame(Summary::OK, $this->fixture->run(true)->exitCode());
        self::assertSame("#!/usr/bin/env php\n<?php echo 'august';\n", $this->fixture->read($tool));
        self::assertTrue(is_executable($this->fixture->workspace->path($tool)));
        self::assertSame(Summary::OK, $this->fixture->run(false)->exitCode());
    }

    public function testWorksInsideAGitRepositoryThatStartsAboveTheProject(): void
    {
        shell_exec('git init -q ' . escapeshellarg($this->fixture->workspace->root()));
        $shop = new Fixture($this->fixture->workspace->inside('shop'));
        $shop->file(self::FILE, self::PLAIN)->patch('FIX-1', Diffs::make(self::FILE, self::PLAIN, self::FIXED));

        self::assertSame(Summary::OK, $shop->run(true)->exitCode());
        self::assertSame(self::FIXED, $shop->read(self::FILE));
    }

    public function testCallsAPatchItCannotReadAConfigurationError(): void
    {
        $this->fixture->file(self::FILE, self::PLAIN)->patch('FIX-1', "this is not a diff\n");

        $summary = $this->fixture->run(true);

        self::assertSame(Summary::CONFIG_ERROR, $summary->exitCode());
        self::assertSame(PatchResult::UNREADABLE, $summary->results()[0]->status());
    }

    /**
     * @return array<string, string> path => state
     */
    private function states(Summary $summary): array
    {
        $states = [];
        foreach ($summary->results() as $result) {
            foreach ($result->targets() as $target) {
                $states[$target->path()] = $target->state();
            }
        }

        return $states;
    }

    /**
     * @return array<string, string|null>
     */
    private function reasons(Summary $summary): array
    {
        $reasons = [];
        foreach ($summary->results() as $result) {
            foreach ($result->targets() as $target) {
                $reasons[$target->path()] = $target->reason();
            }
        }

        return $reasons;
    }
}
