<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Integration;

use Disrex\MagentoPatchInstaller\Engine\Engine;
use Disrex\MagentoPatchInstaller\Engine\Ledger;
use Disrex\MagentoPatchInstaller\Lock\LockFile;
use Disrex\MagentoPatchInstaller\Tests\Support\Diffs;
use Disrex\MagentoPatchInstaller\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class LockFileTest extends TestCase
{
    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->fixture = new Fixture();
        $this->fixture->file('vendor/acme/lib/a.php', "a\n")->file('vendor/acme/lib/b.php', "b\n")->file('vendor/acme/lib/c.php', "c\n")->file('vendor/acme/lib/d.php', "d\n")
            ->patch('FIX-1', Diffs::make('vendor/acme/lib/a.php', "a\n", "A\n"))
            ->patch('OPT-ON', Diffs::make('vendor/acme/lib/b.php', "b\n", "B\n"), ['acme/lib' => '*'], ['optional' => true])
            ->patch('OPT-OFF', Diffs::make('vendor/acme/lib/c.php', "c\n", "C\n"), ['acme/lib' => '*'], ['optional' => true])
            ->rootPatch('LOCAL-1', Diffs::make('vendor/acme/lib/d.php', "d\n", "D\n"))
            ->enable('OPT-ON', 'DISC-456');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testNamesEachPackageVersionOnceAndLeavesOutWhatIsNotEnabled(): void
    {
        $lock = $this->build();

        self::assertSame(['disrex/patches' => '1.0.0'], $lock['packages'], 'the root version is its branch and stays out');
        self::assertSame(['LOCAL-1', 'FIX-1', 'OPT-ON'], array_column($lock['patches'], 'id'));
        self::assertSame(['x/project', 'disrex/patches', 'disrex/patches'], array_column($lock['patches'], 'package'));
        self::assertArrayNotHasKey('optional', $lock['patches'][1]);
        self::assertTrue($lock['patches'][2]['optional']);
        self::assertSame('DISC-456', $lock['patches'][2]['enabled']);
        foreach ($lock['patches'] as $patch) {
            self::assertArrayNotHasKey('version', $patch);
        }
    }

    public function testANewPackageVersionChangesTheHashEvenWhenThePatchesStayTheSame(): void
    {
        $before = $this->build();
        $this->fixture->version('disrex/patches', '1.0.1');
        $after = $this->build();

        self::assertSame($before['patches'], $after['patches']);
        self::assertNotSame($before['content-hash'], $after['content-hash']);
    }

    public function testWritesPackagesAsAnObjectWhenOnlyTheRootDeclaresPatches(): void
    {
        $fixture = new Fixture();
        $fixture->file('vendor/acme/lib/d.php', "d\n")->rootPatch('LOCAL-1', Diffs::make('vendor/acme/lib/d.php', "d\n", "D\n"));
        $file = new LockFile($fixture->workspace->root());
        $file->write(LockFile::build($fixture->catalog(), new Engine($fixture->project(), new Ledger($fixture->workspace->root())), $fixture->project(), 'x/project'));

        self::assertStringContainsString('"packages": {}', (string) $fixture->read(LockFile::NAME));
        $fixture->remove();
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $catalog = $this->fixture->catalog();
        self::assertSame([], $catalog->errors());

        return LockFile::build($catalog, new Engine($this->fixture->project(), new Ledger($this->fixture->workspace->root())), $this->fixture->project(), 'x/project');
    }
}
