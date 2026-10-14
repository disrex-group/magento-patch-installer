<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Unit;

use Disrex\MagentoPatchInstaller\Project\RootFileMap;
use PHPUnit\Framework\TestCase;

final class RootFileMapTest extends TestCase
{
    private const BASE = 'vendor/magento/magento2-base';

    public function testFindsTheOtherCopyInBothDirectionsForFilesAndDirectories(): void
    {
        $map = $this->map([[self::BASE, [['nginx.conf.sample', 'nginx.conf.sample'], ['lib/web', 'lib/web']]]], [self::BASE . '/lib/web']);

        self::assertSame(self::BASE . '/nginx.conf.sample', $map->counterpart('nginx.conf.sample'));
        self::assertSame('lib/web/underscore.js', $map->counterpart(self::BASE . '/lib/web/underscore.js'));
        self::assertSame(self::BASE . '/lib/web/underscore.js', $map->counterpart('lib/web/underscore.js'));
        self::assertNull($map->counterpart('vendor/acme/lib/A.php'));
        self::assertSame($map->key('lib/web/underscore.js'), $map->key(self::BASE . '/lib/web/underscore.js'));
    }

    public function testAFileEntryWinsOverTheDirectoryItSitsIn(): void
    {
        $map = $this->map([[self::BASE, [['pub', 'pub'], ['pub/errors/processor.php', 'pub/errors/processor.php']]]], [self::BASE . '/pub']);

        self::assertSame(self::BASE . '/pub/errors/processor.php', $map->counterpart('pub/errors/processor.php'));
    }

    public function testIgnoresEntriesThatLeaveTheProjectOrClaimAnotherPackagesFiles(): void
    {
        $map = $this->map([
            [self::BASE, [['nginx.conf.sample', 'nginx.conf.sample']]],
            ['vendor/evil/pkg', [['x', '../outside'], ['y', '/etc/passwd'], ['z', self::BASE . '/nginx.conf.sample']]],
        ], []);

        self::assertNull($map->counterpart('../outside'));
        self::assertNull($map->counterpart('/etc/passwd'));
        self::assertSame('nginx.conf.sample', $map->counterpart(self::BASE . '/nginx.conf.sample'));
    }

    /**
     * @param array<int, array{0: string, 1: array<int, array{0: string, 1: string}>}> $packages
     * @param string[] $directories
     */
    private function map(array $packages, array $directories): RootFileMap
    {
        return new RootFileMap(array_map(static function (array $package): array {
            return ['install' => $package[0], 'map' => $package[1]];
        }, $packages), static function (string $path) use ($directories): bool {
            return in_array($path, $directories, true);
        });
    }
}
