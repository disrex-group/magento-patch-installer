<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Support;

final class Diffs
{
    /**
     * A real git diff that turns $old into $new at $path; null on either side creates or deletes the file.
     */
    public static function make(string $path, ?string $old, ?string $new, bool $executable = false): string
    {
        $dir = sys_get_temp_dir() . '/mpi-diff-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $a = $old === null ? '/dev/null' : $dir . '/a';
        $b = $new === null ? '/dev/null' : $dir . '/b';

        foreach ([[$old, $a], [$new, $b]] as [$content, $file]) {
            if ($content !== null) {
                file_put_contents($file, $content);
                chmod($file, $executable ? 0755 : 0644);
            }
        }

        $command = sprintf('cd %s && git -c core.autocrlf=false diff --no-index --no-color %s %s', escapeshellarg($dir), escapeshellarg($old === null ? '/dev/null' : 'a'), escapeshellarg($new === null ? '/dev/null' : 'b'));
        $diff = (string) shell_exec($command);
        @unlink($dir . '/a');
        @unlink($dir . '/b');
        rmdir($dir);

        return (string) preg_replace(
            ['#^diff --git \S+ \S+#m', '#^--- a/a$#m', '#^\+\+\+ b/b$#m', '#^--- a/b$#m', '#^\+\+\+ b/a$#m'],
            ['diff --git a/' . $path . ' b/' . $path, '--- a/' . $path, '+++ b/' . $path, '--- a/' . $path, '+++ b/' . $path],
            $diff
        );
    }
}
