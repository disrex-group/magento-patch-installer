<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Git;

final class GitApply
{
    /** @var string */
    private $directory;

    /** @var string */
    private $lastError = '';

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public function canApply(string $diff): bool
    {
        return $this->run($diff, ['--check']);
    }

    public function isApplied(string $diff): bool
    {
        return $this->run($diff, ['--reverse', '--check']);
    }

    public function apply(string $diff): bool
    {
        return $this->run($diff, []);
    }

    public function revert(string $diff): bool
    {
        return $this->run($diff, ['--reverse']);
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * @param string[] $flags
     */
    private function run(string $diff, array $flags): bool
    {
        // The user's git config must not decide the verdict: autocrlf=true rewrites a whole file to CRLF
        // and still reports the patch as applied, and apply.whitespace=error fails a valid patch.
        $command = array_values(array_merge(
            ['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'apply', '-p1', '--whitespace=nowarn'],
            $flags,
            ['-']
        ));

        $environment = getenv();
        // Stops git from adopting a repository above the project, which would make it skip every path outside that repo's subdirectory.
        $environment['GIT_CEILING_DIRECTORIES'] = dirname($this->directory);
        $environment['LC_ALL'] = 'C';

        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            $environment
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start git; disrex/magento-patch-installer needs git on the PATH.');
        }

        fwrite($pipes[0], $diff);
        fclose($pipes[0]);
        $this->lastError = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }
}
