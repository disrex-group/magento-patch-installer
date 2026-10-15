<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Unit;

use Disrex\MagentoPatchInstaller\Config\Catalog;
use Disrex\MagentoPatchInstaller\Config\Definition;
use Disrex\MagentoPatchInstaller\Config\DefinitionLoader;
use Disrex\MagentoPatchInstaller\Config\Settings;
use Disrex\MagentoPatchInstaller\Tests\Support\Workspace;
use PHPUnit\Framework\TestCase;

final class DefinitionLoaderTest extends TestCase
{
    /** @var Workspace */
    private $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::create();
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->workspace->write('pkg/' . $name . '.diff', "--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n");
        }
        $this->workspace->write('outside.diff', 'x');
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testChainsACumulativeLineInListOrderEvenAcrossIncludedFiles(): void
    {
        $this->workspace->write('pkg/more.json', (string) json_encode(['lines' => ['2.4.8-p5' => ['base' => ['magento/magento2-base' => '2.4.8-p5'], 'cumulative' => true, 'patches' => [['id' => '2026-09-001', 'source' => 'c.diff']]]]]));

        $catalog = $this->load([
            'include' => ['more.json'],
            'lines' => ['2.4.8-p5' => ['base' => ['magento/magento2-base' => '2.4.8-p5'], 'cumulative' => true, 'patches' => [
                ['id' => '2026-07-001', 'source' => 'a.diff'],
                ['id' => '2026-08-001', 'source' => 'b.diff'],
            ]]],
        ]);

        self::assertSame([], $catalog->errors());
        self::assertSame(['2026-07-001', '2026-08-001', '2026-09-001'], $this->ids($catalog));
        self::assertSame([[], ['2026-07-001'], ['2026-08-001']], $this->requiredIds($catalog));
    }

    public function testPutsAPatchAfterWhatItDependsOnWhateverOrderItIsWrittenIn(): void
    {
        $catalog = $this->load(['patches' => [
            ['id' => 'LATER', 'source' => 'a.diff', 'depends' => ['EARLIER']],
            ['id' => 'ALONE', 'source' => 'b.diff'],
            ['id' => 'EARLIER', 'source' => 'c.diff'],
        ]]);

        self::assertSame([], $catalog->errors());
        self::assertSame(['ALONE', 'EARLIER', 'LATER'], $this->ids($catalog));
    }

    public function testReportsEveryWayADeclarationCanBeWrong(): void
    {
        $catalog = $this->load([
            'patches' => [
                ['id' => 'TWICE', 'source' => 'a.diff'],
                ['id' => 'TWICE', 'source' => 'b.diff'],
                ['id' => 'GHOST', 'source' => 'a.diff', 'depends' => ['NOBODY']],
                ['id' => 'ME', 'source' => 'a.diff', 'depends' => ['ME']],
                ['id' => 'P', 'source' => 'a.diff', 'depends' => ['Q']],
                ['id' => 'Q', 'source' => 'b.diff', 'depends' => ['P']],
                ['id' => 'URL', 'source' => 'https://example.com/x.diff'],
                ['id' => 'OUT', 'source' => '../outside.diff'],
                ['id' => 'GONE', 'source' => 'missing.diff'],
                ['id' => 'BAD', 'source' => 'a.diff', 'base' => ['acme/lib' => 'not a version']],
            ],
            'lines' => ['x' => ['base' => ['acme/lib' => '1.0.0'], 'cumulative' => true, 'patches' => [['id' => 'D', 'source' => 'd.diff', 'depends' => ['P']]]]],
        ]);

        $errors = implode("\n", $catalog->errors());
        foreach (['declared twice', 'depends on "NOBODY"', 'depends on itself', 'dependency cycle', 'points at a URL', 'outside the package', 'does not exist', 'unreadable base constraint', 'cumulative line'] as $expected) {
            self::assertStringContainsString($expected, $errors);
        }
    }

    public function testRefusesPatchesFromAPackageTheProjectDoesNotTrust(): void
    {
        $catalog = $this->load(['patches' => [['id' => 'A', 'source' => 'a.diff']]], []);

        self::assertSame([], $catalog->definitions());
        self::assertStringContainsString('not trusted', $catalog->errors()[0]);
    }

    public function testSkipsAPatchByLineAndEverythingBuiltOnIt(): void
    {
        $catalog = $this->load(['lines' => ['1.0' => ['base' => ['acme/lib' => '1.0'], 'cumulative' => true, 'patches' => [
            ['id' => 'JUL', 'source' => 'a.diff'],
            ['id' => 'AUG', 'source' => 'b.diff'],
        ]]]], ['disrex/*'], ['disrex/patches' => ['skip' => ['JUL@1.0' => 'breaks the checkout']]]);

        $reasons = array_map(static function (Definition $definition): ?string {
            return $definition->skipReason();
        }, $catalog->definitions());

        self::assertSame(['breaks the checkout', 'depends on JUL [1.0], which this project skips'], $reasons);
    }

    public function testRefusesSkipsThatCannotDoWhatTheySay(): void
    {
        $line = ['lines' => ['1.0' => ['base' => ['acme/lib' => '1.0'], 'cumulative' => true, 'patches' => [
            ['id' => 'JUL', 'source' => 'a.diff'],
            ['id' => 'AUG', 'source' => 'b.diff'],
        ]]]];

        $catalog = $this->load($line, ['disrex/*'], [
            'disrex/patches' => ['skip' => ['JUL' => 'too broad'], 'only' => []],
            'acme/other' => ['skip' => ['X' => 'nothing declared']],
        ]);
        $errors = implode("\n", $catalog->errors());
        self::assertStringContainsString('may only contain "skip" and "enable"', $errors);
        self::assertStringContainsString('declares no patches', $errors);

        $catalog = $this->load($line, ['disrex/*'], ['disrex/patches' => ['skip' => ['JUL' => 'too broad', 'NOPE' => 'no such patch']]]);
        $errors = implode("\n", $catalog->errors());
        self::assertStringContainsString('skip it as "JUL@<line>"', $errors);
        self::assertStringContainsString('no patch "NOPE"', $errors);
    }

    public function testEnablesAnOptionalPatchWithTheReasonTheProjectGives(): void
    {
        $catalog = $this->load(['patches' => [
            ['id' => 'BASE', 'source' => 'a.diff'],
            ['id' => 'OPT', 'source' => 'b.diff', 'optional' => true, 'depends' => ['BASE']],
            ['id' => 'IDLE', 'source' => 'c.diff', 'optional' => true],
        ]], ['disrex/*'], ['disrex/patches' => ['enable' => ['OPT' => 'DISC-456']]]);

        self::assertSame([], $catalog->errors());
        $byId = [];
        foreach ($catalog->definitions() as $definition) {
            $byId[$definition->id()] = $definition;
        }
        self::assertFalse($byId['BASE']->isDormant());
        self::assertSame('DISC-456', $byId['OPT']->enableReason());
        self::assertFalse($byId['OPT']->isDormant());
        self::assertTrue($byId['IDLE']->isDormant());
    }

    public function testRefusesOptionalPatchesThatCannotWorkAsDeclared(): void
    {
        $catalog = $this->load([
            'patches' => [
                ['id' => 'OPT', 'source' => 'a.diff', 'optional' => true],
                ['id' => 'MUST', 'source' => 'b.diff', 'depends' => ['OPT']],
                ['id' => 'YES', 'source' => 'c.diff', 'optional' => 'yes'],
            ],
            'lines' => ['x' => ['base' => ['acme/lib' => '1.0.0'], 'cumulative' => true, 'patches' => [['id' => 'LINK', 'source' => 'd.diff', 'optional' => true]]]],
        ]);
        $errors = implode("\n", $catalog->errors());

        self::assertStringContainsString('"MUST" depends on "OPT", which is optional', $errors);
        self::assertStringContainsString('"YES" has an "optional" that is not true or false', $errors);
        self::assertStringContainsString('"LINK" is marked "optional" in cumulative line "x"', $errors);
    }

    public function testRefusesEnablesThatCannotDoWhatTheySay(): void
    {
        $config = ['patches' => [
            ['id' => 'MUST', 'source' => 'a.diff'],
            ['id' => 'OPT', 'source' => 'b.diff', 'optional' => true],
            ['id' => 'TOP', 'source' => 'c.diff', 'optional' => true, 'depends' => ['OPT']],
            ['id' => 'IDLE', 'source' => 'd.diff', 'optional' => true],
        ]];

        $errors = implode("\n", $this->load($config, ['disrex/*'], ['disrex/patches' => [
            'enable' => ['MUST' => 'r', 'NOPE' => 'r', 'OPT' => '', 'TOP' => 'r'],
            'skip' => ['IDLE' => 'r'],
        ]])->errors());

        self::assertStringContainsString('"MUST" is not optional', $errors);
        self::assertStringContainsString('no patch "NOPE" to enable', $errors);
        self::assertStringContainsString('enabling "OPT" needs a reason', $errors);
        self::assertStringContainsString('"TOP" is enabled, but it builds on OPT, which is optional and not enabled', $errors);
        self::assertStringContainsString('"IDLE" is optional and not enabled, so there is nothing to skip', $errors);

        $errors = implode("\n", $this->load($config, ['disrex/*'], ['disrex/patches' => [
            'enable' => ['OPT' => 'r'],
            'skip' => ['OPT' => 'r'],
        ]])->errors());
        self::assertStringContainsString('"OPT" is both enabled and skipped', $errors);
    }

    public function testSkippingARequiredPatchAlsoSkipsAnEnabledPatchBuiltOnIt(): void
    {
        $catalog = $this->load(['patches' => [
            ['id' => 'BASE', 'source' => 'a.diff'],
            ['id' => 'OPT', 'source' => 'b.diff', 'optional' => true, 'depends' => ['BASE']],
        ]], ['disrex/*'], ['disrex/patches' => ['enable' => ['OPT' => 'r'], 'skip' => ['BASE' => 'breaks it']]]);

        self::assertSame([], $catalog->errors());
        self::assertSame('depends on BASE, which this project skips', $catalog->definitions()[1]->skipReason());
    }

    public function testSkipsADependedOnStandalonePatchByItsBareIdWhenALineAlsoHasThatId(): void
    {
        $catalog = $this->load([
            'patches' => [
                ['id' => 'X', 'source' => 'a.diff'],
                ['id' => 'Y', 'source' => 'b.diff', 'depends' => ['X']],
            ],
            'lines' => ['L' => ['base' => ['acme/lib' => '1.0'], 'patches' => [['id' => 'X', 'source' => 'c.diff']]]],
        ], ['disrex/*'], ['disrex/patches' => ['skip' => ['X' => 'r']]]);

        self::assertSame([], $catalog->errors());
        foreach ($catalog->definitions() as $definition) {
            self::assertNotNull($definition->skipReason(), $definition->label());
        }
    }

    public function testJudgesEachEnabledPatchOnItsOwnTerms(): void
    {
        $declarations = ['patches' => [
            ['id' => 'OPT', 'source' => 'a.diff', 'optional' => true],
            ['id' => 'MUST', 'source' => 'b.diff', 'depends' => ['OPT']],
            ['id' => 'B', 'source' => 'c.diff'],
            ['id' => 'E', 'source' => 'd.diff', 'optional' => true, 'depends' => ['B', 'OPT']],
        ]];

        $errors = implode("\n", $this->load($declarations, ['disrex/*'], ['disrex/patches' => ['enable' => ['E' => 'r'], 'skip' => ['B' => 'r']]])->errors());
        self::assertStringNotContainsString('"MUST" is enabled', $errors);
        self::assertStringContainsString('"E" is enabled, but it builds on OPT', $errors, 'a skipped prerequisite listed first does not hide it');

        $errors = implode("\n", $this->load($declarations, ['disrex/*'], ['disrex/patches' => ['enable' => ['OPT' => true]]])->errors());
        self::assertStringContainsString('enabling "OPT" needs a reason', $errors);
    }

    public function testAcceptsOptionalFalseInACumulativeLine(): void
    {
        $catalog = $this->load(['lines' => ['x' => ['base' => ['acme/lib' => '1.0.0'], 'cumulative' => true, 'patches' => [
            ['id' => 'LINK', 'source' => 'a.diff', 'optional' => false],
        ]]]]);

        self::assertSame([], $catalog->errors());
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $trust
     * @param array<string, mixed> $sources
     */
    private function load(array $config, array $trust = ['disrex/*'], array $sources = []): Catalog
    {
        $settings = new Settings(['magento-patches' => ['trust' => $trust, 'sources' => $sources]]);

        return (new DefinitionLoader())->load($settings, 'x/project', $this->workspace->root(), [
            ['name' => 'disrex/patches', 'config' => $config, 'dir' => $this->workspace->path('pkg')],
        ]);
    }

    /**
     * @return string[]
     */
    private function ids(Catalog $catalog): array
    {
        return array_map(static function (Definition $definition): string {
            return $definition->id();
        }, $catalog->definitions());
    }

    /**
     * @return array<int, string[]>
     */
    private function requiredIds(Catalog $catalog): array
    {
        $ids = [];
        foreach ($catalog->definitions() as $definition) {
            $ids[$definition->key()] = $definition->id();
        }

        return array_map(static function (Definition $definition) use ($ids): array {
            return array_map(static function (string $key) use ($ids): string {
                return $ids[$key];
            }, $definition->requires());
        }, $catalog->definitions());
    }
}
