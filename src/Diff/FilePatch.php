<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Diff;

final class FilePatch
{
    /** @var string */
    private $path;

    /** @var bool */
    private $creates;

    /** @var bool */
    private $deletes;

    /** @var string[] */
    private $headers;

    /** @var string[] */
    private $hunks;

    /**
     * @param string[] $headers extended git header lines, such as "new file mode 100755"
     * @param string[] $hunks every line from the first "@@" on, line endings preserved
     */
    public function __construct(string $path, bool $creates, bool $deletes, array $headers, array $hunks)
    {
        $this->path = $path;
        $this->creates = $creates;
        $this->deletes = $deletes;
        $this->headers = $headers;
        $this->hunks = $hunks;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function creates(): bool
    {
        return $this->creates;
    }

    public function deletes(): bool
    {
        return $this->deletes;
    }

    public function package(): ?string
    {
        return preg_match('#^vendor/([^/]+/[^/]+)/#', $this->path, $match) === 1 ? $match[1] : null;
    }

    public function fingerprint(): string
    {
        $modes = array_values(array_filter($this->headers, static function (string $line): bool {
            return strpos($line, 'index ') !== 0;
        }));

        return hash('sha256', ($this->creates ? 'C' : '') . ($this->deletes ? 'D' : '') . "\n"
            . implode("\n", $modes) . "\n" . implode("\n", $this->hunks));
    }

    public function renderFor(string $path): string
    {
        $out = ['diff --git a/' . $path . ' b/' . $path];

        foreach ($this->headers as $header) {
            $out[] = $header;
        }

        if ($this->hunks !== []) {
            $out[] = '--- ' . ($this->creates ? '/dev/null' : 'a/' . $path);
            $out[] = '+++ ' . ($this->deletes ? '/dev/null' : 'b/' . $path);

            foreach ($this->hunks as $line) {
                $out[] = $line;
            }
        }

        return implode("\n", $out) . "\n";
    }
}
