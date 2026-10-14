<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Tests\Support;

/**
 * A real Composer project with acme/lib, a trusted patch package and this plugin, all from path repositories.
 */
final class ComposerProject
{
    public const FILE = 'vendor/acme/lib/src/Escaper.php';
    public const PLAIN = "<?php\n\nreturn 'plain';\n";
    public const FIXED = "<?php\n\nreturn 'escaped';\n";

    /** @var Workspace */
    public $workspace;

    /** @var array<string, mixed> */
    private $root;

    public function __construct(?string $diff = null)
    {
        $this->workspace = Workspace::create();
        $this->workspace->write('lib/composer.json', (string) json_encode(['name' => 'acme/lib', 'version' => '1.0.0']));
        $this->workspace->write('lib/src/Escaper.php', self::PLAIN);
        $this->workspace->write('patches/fix.diff', $diff ?? Diffs::make(self::FILE, self::PLAIN, self::FIXED));
        $this->workspace->write('patches/composer.json', (string) json_encode([
            'name' => 'disrex/test-patches',
            'version' => '1.0.0',
            'extra' => ['magento-patches' => ['patches' => [['id' => 'FIX-1', 'source' => 'fix.diff', 'base' => ['acme/lib' => '*']]]]],
        ]));

        $this->root = [
            'name' => 'x/shop',
            'version' => '1.0.0',
            'minimum-stability' => 'dev',
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'path', 'url' => $this->workspace->path('lib'), 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $this->workspace->path('patches'), 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => dirname(__DIR__, 2), 'options' => ['symlink' => true, 'versions' => ['disrex/magento-patch-installer' => '0.1.0']]],
            ],
            'require' => ['acme/lib' => '1.0.0', 'disrex/test-patches' => '1.0.0', 'disrex/magento-patch-installer' => '0.1.0'],
            'config' => ['allow-plugins' => ['disrex/magento-patch-installer' => true]],
            'extra' => ['magento-patches' => ['trust' => ['disrex/*']]],
        ];
        $this->save();
    }

    /**
     * @param array<string, mixed> $settings merged into extra.magento-patches
     */
    public function settings(array $settings): self
    {
        $this->root['extra']['magento-patches'] = array_merge($this->root['extra']['magento-patches'], $settings);
        $this->save();

        return $this;
    }

    /**
     * @return array{0: int, 1: string} exit code and combined output
     */
    public function composer(string ...$arguments): array
    {
        $command = array_merge(['composer', '--no-ansi', '--no-interaction', '--working-dir=' . $this->workspace->path('shop')], $arguments);
        $environment = getenv();
        $environment['COMPOSER_HOME'] = $this->workspace->path('home');
        $environment['COMPOSER_CACHE_DIR'] = $this->workspace->path('cache');

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not run composer');
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    public function read(string $path): ?string
    {
        return $this->workspace->read('shop/' . $path);
    }

    public function write(string $path, string $content): void
    {
        $this->workspace->write('shop/' . $path, $content);
    }

    public function remove(): void
    {
        $this->workspace->remove();
    }

    private function save(): void
    {
        $this->workspace->write('shop/composer.json', (string) json_encode($this->root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
