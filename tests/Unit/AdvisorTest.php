<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Unit;

use Disrex\MagentoPatchInstaller\Advice\Advisor;
use Disrex\MagentoPatchInstaller\Advice\Advisory;
use Disrex\MagentoPatchInstaller\Advice\EndOfLife;
use Disrex\MagentoPatchInstaller\Config\Definition;
use Disrex\MagentoPatchInstaller\Tests\Support\Workspace;
use PHPUnit\Framework\TestCase;

final class AdvisorTest extends TestCase
{
    /** @var Workspace */
    private $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::create();
        $this->workspace->write('eol.json', (string) json_encode(['generated' => '2026-10-01', 'distributions' => [
            'magento/product-community-edition' => ['2.4.6-p13' => '2026-08-11', '2.4.7-p10' => '2027-04-09', '2.4.8-p5' => '2028-04-11'],
            'magento/product-enterprise-edition' => ['2.4.8-p5' => '2028-04-11'],
        ]]));
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testWarnsBeforeAndAfterTheEndOfLifeAndStaysQuietWellBefore(): void
    {
        $advisor = new Advisor(new EndOfLife($this->workspace->path('eol.json')));
        $today = new \DateTimeImmutable('2026-10-14');

        $ended = $advisor->advise(['magento/product-community-edition' => '2.4.6-p13'], [], $today);
        $soon = $advisor->advise(['magento/product-community-edition' => '2.4.7-p10'], [], $today);
        $fine = $advisor->advise(['magento/product-community-edition' => '2.4.8-p5'], [], $today);

        self::assertSame(Advisory::CRITICAL, $ended[0]->level());
        self::assertStringContainsString('64 days ago', $ended[0]->message());
        self::assertSame(Advisory::WARNING, $soon[0]->level());
        self::assertSame([], $fine);
    }

    public function testNamesCommerceRatherThanOpenSourceWhenBothAreInstalled(): void
    {
        $product = EndOfLife::product(['magento/product-community-edition' => '2.4.8-p5', 'magento/product-enterprise-edition' => '2.4.8-p5']);

        self::assertSame('magento/product-enterprise-edition', $product['package'] ?? null);
    }

    public function testWarnsAStoreOnePatchLevelBehindTheLineThePatchesNeed(): void
    {
        $definition = new Definition('k', '2026-10-001', null, 'disrex/p', '/x', 'x', ['magento/magento2-base' => '2.4.8-p5'], '2.4.8-p5', true, null, []);
        $advisor = new Advisor(new EndOfLife($this->workspace->path('eol.json')));

        $behind = $advisor->advise(['magento/magento2-base' => '2.4.8-p4'], [$definition], new \DateTimeImmutable('2026-10-14'));
        $otherRelease = $advisor->advise(['magento/magento2-base' => '2.4.7-p10'], [$definition], new \DateTimeImmutable('2026-10-14'));

        self::assertCount(1, $behind);
        self::assertStringContainsString('magento/magento2-base is on 2.4.8-p4', $behind[0]->message());
        self::assertSame([], $otherRelease);
    }
}
