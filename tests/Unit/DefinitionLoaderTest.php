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
        self::assertStringContainsString('may only contain "skip"', $errors);
        self::assertStringContainsString('declares no patches', $errors);

        $catalog = $this->load($line, ['disrex/*'], ['disrex/patches' => ['skip' => ['JUL' => 'too broad', 'NOPE' => 'no such patch']]]);
        $errors = implode("\n", $catalog->errors());
        self::assertStringContainsString('skip it as "JUL@<line>"', $errors);
        self::assertStringContainsString('no patch "NOPE"', $errors);
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
