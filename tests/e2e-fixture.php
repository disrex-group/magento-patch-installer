<?php

declare(strict_types=1);

// Builds a small patch package from the files of an installed Magento, so the e2e workflow can run on a
// store from the Mage-OS mirror without depending on the security patch repository.
//
//   php tests/e2e-fixture.php <magento-project-dir> <output-dir>

[$self, $project, $output] = $argv + [null, null, null];
if ($project === null || $output === null || !is_dir($project . '/vendor/magento/framework')) {
    fwrite(STDERR, "usage: php tests/e2e-fixture.php <magento-project-dir> <output-dir>\n");
    exit(2);
}

$lock = json_decode((string) file_get_contents($project . '/composer.lock'), true);
$versions = [];
foreach ($lock['packages'] as $package) {
    $versions[$package['name']] = $package['version'];
}

$base = $versions['magento/magento2-base'];
$escaper = 'vendor/magento/framework/Escaper.php';
$template = 'vendor/magento/module-email/Model/AbstractTemplate.php';
$escaperText = (string) file_get_contents($project . '/' . $escaper);
$july = $escaperText . "// e2e july\n";
$august = $escaperText . "// e2e august\n";

@mkdir($output . '/line', 0777, true);
file_put_contents($output . '/root.diff', diff('nginx.conf.sample', (string) file_get_contents($project . '/nginx.conf.sample'), (string) file_get_contents($project . '/nginx.conf.sample') . "# e2e\n"));
file_put_contents($output . '/line/july.diff', diff($escaper, $escaperText, $july));
file_put_contents($output . '/line/august.diff', diff($escaper, $july, $august) . diff('vendor/bin/e2e-status', null, "#!/usr/bin/env php\n<?php echo 'e2e';\n", true));
file_put_contents($output . '/module.diff', diff($template, (string) file_get_contents($project . '/' . $template), file_get_contents($project . '/' . $template) . "// e2e module\n"));

file_put_contents($output . '/composer.json', json_encode([
    'name' => 'disrex/e2e-fixture-patches',
    'extra' => ['magento-patches' => [
        'patches' => [
            ['id' => 'E2E-ROOT', 'source' => 'root.diff', 'base' => ['magento/magento2-base' => $base]],
            ['id' => 'E2E-MODULE', 'source' => 'module.diff', 'base' => ['magento/framework' => '*']],
        ],
        'lines' => [$base => ['base' => ['magento/magento2-base' => $base], 'cumulative' => true, 'patches' => [
            ['id' => 'E2E-07', 'source' => 'line/july.diff'],
            ['id' => 'E2E-08', 'source' => 'line/august.diff'],
        ]]],
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "fixture for magento/magento2-base $base in $output\n";

function diff(string $path, ?string $old, ?string $new, bool $executable = false): string
{
    $dir = sys_get_temp_dir() . '/e2e-diff-' . bin2hex(random_bytes(6));
    mkdir($dir);
    foreach (['a' => $old, 'b' => $new] as $name => $content) {
        if ($content !== null) {
            file_put_contents($dir . '/' . $name, $content);
            chmod($dir . '/' . $name, $executable ? 0755 : 0644);
        }
    }

    $diff = (string) shell_exec(sprintf(
        'cd %s && git -c core.autocrlf=false diff --no-index --no-color %s %s',
        escapeshellarg($dir),
        $old === null ? '/dev/null' : 'a',
        $new === null ? '/dev/null' : 'b'
    ));
    @unlink($dir . '/a');
    @unlink($dir . '/b');
    rmdir($dir);

    return (string) preg_replace(
        ['#^diff --git \S+ \S+#m', '#^--- a/a$#m', '#^\+\+\+ b/b$#m'],
        ['diff --git a/' . $path . ' b/' . $path, '--- a/' . $path, '+++ b/' . $path],
        $diff
    );
}
