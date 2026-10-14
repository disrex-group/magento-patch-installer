<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Diff;

final class DiffParser
{
    private const KEPT_HEADERS = ['new file mode ', 'deleted file mode ', 'old mode ', 'new mode ', 'index '];

    private const UNSUPPORTED_HEADERS = ['rename from ', 'rename to ', 'copy from ', 'copy to ', 'similarity index ', 'GIT binary patch', 'Binary files '];

    /**
     * @return FilePatch[]
     *
     * @throws \UnexpectedValueException for a diff this plugin cannot apply safely, such as a rename or a binary patch
     */
    public function parse(string $diff): array
    {
        // Only "\n" splits: a patch for a CRLF file carries "\r" on every line, and that is content.
        $lines = explode("\n", $diff);
        $count = count($lines);
        $patches = [];
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (strpos($line, 'diff --git ') === 0) {
                $patches[] = $this->readFile($lines, $i, true);
                continue;
            }

            if (strpos($line, '--- ') === 0 && isset($lines[$i + 1]) && strpos($lines[$i + 1], '+++ ') === 0) {
                $patches[] = $this->readFile($lines, $i, false);
                continue;
            }

            $i++;
        }

        return array_values(array_filter($patches));
    }

    /**
     * @param string[] $lines
     */
    private function readFile(array $lines, int &$i, bool $git): ?FilePatch
    {
        $fallback = $git ? $this->pathFromGitLine($lines[$i]) : null;
        $headers = [];
        $hunks = [];
        $oldPath = null;
        $newPath = null;
        $count = count($lines);

        if ($git) {
            $i++;
        }

        while ($i < $count) {
            $line = $lines[$i];
            $bare = rtrim($line, "\r");

            if ($hunks !== [] || strpos($bare, '@@') === 0) {
                if (strpos($bare, '@@') !== 0) {
                    break;
                }
                $i = $this->readHunk($lines, $i, $hunks);
                continue;
            }

            if (strpos($bare, 'diff --git ') === 0) {
                break;
            }

            if (strpos($bare, '--- ') === 0) {
                $oldPath = $this->pathFromHeader($bare);
            } elseif (strpos($bare, '+++ ') === 0) {
                $newPath = $this->pathFromHeader($bare);
            } elseif ($git) {
                foreach (self::UNSUPPORTED_HEADERS as $prefix) {
                    if (strpos($bare, $prefix) === 0) {
                        throw new \UnexpectedValueException(sprintf('unsupported diff for %s: %s', $fallback ?? '?', $bare));
                    }
                }
                foreach (self::KEPT_HEADERS as $prefix) {
                    if (strpos($bare, $prefix) === 0) {
                        $headers[] = $bare;
                        break;
                    }
                }
            } elseif ($bare !== '' && $oldPath !== null && $newPath !== null) {
                break;
            }

            $i++;
        }

        $creates = $oldPath === '/dev/null' || $this->hasHeader($headers, 'new file mode ');
        $deletes = $newPath === '/dev/null' || $this->hasHeader($headers, 'deleted file mode ');
        $path = $deletes ? ($oldPath ?? $fallback) : ($newPath ?? $fallback);

        if ($path === null || $path === '' || $path === '/dev/null') {
            return null;
        }

        return new FilePatch($path, $creates, $deletes, $headers, $hunks);
    }

    /**
     * @param string[] $lines
     * @param string[] $hunks
     */
    private function readHunk(array $lines, int $i, array &$hunks): int
    {
        $header = $lines[$i];

        if (preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', rtrim($header, "\r"), $m) !== 1) {
            throw new \UnexpectedValueException('malformed hunk header: ' . rtrim($header, "\r"));
        }

        // A count left out means one line, and only the counts tell a removed "-- x" apart from a file header.
        $old = ($m[1] ?? '') === '' ? 1 : (int) $m[1];
        $new = ($m[2] ?? '') === '' ? 1 : (int) $m[2];
        $hunks[] = $header;
        $i++;
        $count = count($lines);

        while ($i < $count && ($old > 0 || $new > 0)) {
            $line = $lines[$i];
            $marker = rtrim($line, "\r") === '' ? ' ' : $line[0];

            if ($marker === '\\') {
                $hunks[] = $line;
                $i++;
                continue;
            }

            if (!in_array($marker, [' ', '+', '-'], true)) {
                throw new \UnexpectedValueException('hunk ends before its line count: ' . rtrim($header, "\r"));
            }

            if ($marker !== '+') {
                $old--;
            }
            if ($marker !== '-') {
                $new--;
            }

            $hunks[] = $line;
            $i++;
        }

        if ($old > 0 || $new > 0) {
            throw new \UnexpectedValueException('hunk ends before its line count: ' . rtrim($header, "\r"));
        }

        if ($i < $count && strpos($lines[$i], '\\') === 0) {
            $hunks[] = $lines[$i];
            $i++;
        }

        return $i;
    }

    /**
     * @param string[] $headers
     */
    private function hasHeader(array $headers, string $prefix): bool
    {
        foreach ($headers as $header) {
            if (strpos($header, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function pathFromHeader(string $line): ?string
    {
        $raw = explode("\t", substr($line, 4), 2)[0];

        return $this->cleanPath(trim($raw));
    }

    private function pathFromGitLine(string $line): ?string
    {
        $rest = rtrim(substr($line, strlen('diff --git ')), "\r");

        if (preg_match('/^(?:"(?:[^"\\\\]|\\\\.)*"|\S+) ("(?:[^"\\\\]|\\\\.)*"|\S+)$/', $rest, $m) === 1) {
            return $this->cleanPath($m[1]);
        }

        return null;
    }

    private function cleanPath(string $path): ?string
    {
        if (strlen($path) > 1 && $path[0] === '"' && substr($path, -1) === '"') {
            $path = stripcslashes(substr($path, 1, -1));
        }

        if ($path === '/dev/null') {
            return $path;
        }

        // A NUL survives git's quoting and makes realpath() and hash_file() throw instead of return false.
        if ($path === '' || strpos($path, "\0") !== false) {
            return null;
        }

        return (string) preg_replace('#^[ab]/#', '', $path);
    }
}
