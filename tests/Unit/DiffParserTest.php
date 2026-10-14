<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Unit;

use Disrex\MagentoPatchInstaller\Diff\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    public function testReadsEveryFileOfAGitDiffAndRendersItBackUnchanged(): void
    {
        $diff = "diff --git a/vendor/acme/lib/A.php b/vendor/acme/lib/A.php\nindex 1111111..2222222 100644\n--- a/vendor/acme/lib/A.php\n+++ b/vendor/acme/lib/A.php\n@@ -1,2 +1,2 @@\n one\n-two\n+TWO\n"
            . "diff --git a/vendor/bin/tool b/vendor/bin/tool\nnew file mode 100755\nindex 0000000..3333333\n--- /dev/null\n+++ b/vendor/bin/tool\n@@ -0,0 +1 @@\n+#!/bin/sh\n";

        $patches = (new DiffParser())->parse($diff);

        self::assertCount(2, $patches);
        self::assertSame('vendor/acme/lib/A.php', $patches[0]->path());
        self::assertSame('acme/lib', $patches[0]->package());
        self::assertTrue($patches[1]->creates());
        self::assertSame($diff, $patches[0]->renderFor('vendor/acme/lib/A.php') . $patches[1]->renderFor('vendor/bin/tool'));
    }

    public function testReadsAPlainUnifiedDiffBehindAPreambleWithTimestamps(): void
    {
        $diff = "Hand-built emergency fix.\n\n--- a/vendor/acme/lib/A.php\t2026-09-07 10:00:00\n+++ b/vendor/acme/lib/A.php\t2026-09-07 10:00:00\n@@ -1 +1 @@\n-old\n+new\n";

        $patches = (new DiffParser())->parse($diff);

        self::assertCount(1, $patches);
        self::assertSame('vendor/acme/lib/A.php', $patches[0]->path());
    }

    public function testTellsARemovedLineThatLooksLikeAFileHeaderFromARealHeader(): void
    {
        $diff = "--- a/notes.txt\n+++ b/notes.txt\n@@ -1,2 +1,1 @@\n--- not a header\n-++ nor this\n";

        $patches = (new DiffParser())->parse($diff);

        self::assertCount(1, $patches);
        self::assertStringContainsString("--- not a header\n-++ nor this", $patches[0]->renderFor('notes.txt'));
    }

    public function testKeepsCarriageReturnsBecauseTheyAreContent(): void
    {
        $diff = "--- a/win.txt\n+++ b/win.txt\n@@ -1 +1 @@\n-old\r\n+new\r\n";

        self::assertStringContainsString("-old\r\n+new\r\n", (new DiffParser())->parse($diff)[0]->renderFor('win.txt'));
    }

    public function testUnquotesAGitQuotedPathAndRefusesANulByte(): void
    {
        $quoted = "diff --git \"a/sp\\303\\251cial.txt\" \"b/sp\\303\\251cial.txt\"\n--- \"a/sp\\303\\251cial.txt\"\n+++ \"b/sp\\303\\251cial.txt\"\n@@ -1 +1 @@\n-a\n+b\n";
        $nul = "--- \"a/x\\000y\"\n+++ \"b/x\\000y\"\n@@ -1 +1 @@\n-a\n+b\n";

        self::assertSame('spécial.txt', (new DiffParser())->parse($quoted)[0]->path());
        self::assertSame([], (new DiffParser())->parse($nul));
    }

    public function testRefusesARename(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new DiffParser())->parse("diff --git a/old.php b/new.php\nsimilarity index 100%\nrename from old.php\nrename to new.php\n");
    }

    public function testRefusesAHunkShorterThanItsHeaderSays(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new DiffParser())->parse("--- a/x\n+++ b/x\n@@ -1,3 +1,3 @@\n a\n-b\n");
    }

    public function testFingerprintIgnoresThePathAndTheIndexLine(): void
    {
        $one = "diff --git a/x b/x\nindex 1111111..2222222 100644\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n";
        $two = "diff --git a/y b/y\nindex 3333333..4444444 100644\n--- a/y\n+++ b/y\n@@ -1 +1 @@\n-a\n+b\n";
        $three = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+c\n";
        $parser = new DiffParser();

        self::assertSame($parser->parse($one)[0]->fingerprint(), $parser->parse($two)[0]->fingerprint());
        self::assertNotSame($parser->parse($one)[0]->fingerprint(), $parser->parse($three)[0]->fingerprint());
    }
}
