<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Disrex\MagentoPatchInstaller\Console\CommandProvider;
use Disrex\MagentoPatchInstaller\Lock\LockFile;
use Disrex\MagentoPatchInstaller\Report\Summary;
use Disrex\MagentoPatchInstaller\Report\TextReport;

final class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    // magento-composer-installer deploys the root files at priority 1 and project scripts run after 0; patching
    // after all of that is the only way to see every file where it will finally be. cweagans also resets a package
    // before patching it, so running last re-applies whatever that reset took away in the same run.
    private const PRIORITY = -1000;

    /** @var Composer|null */
    private $composer;

    /** @var IOInterface|null */
    private $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [ComposerCommandProvider::class => CommandProvider::class];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['afterInstall', self::PRIORITY],
            ScriptEvents::POST_UPDATE_CMD => ['afterUpdate', self::PRIORITY],
            // A bare "composer dump-autoload" fires neither event above, while vaimo/composer-patches resets and
            // re-patches packages on it; without this the store is left unpatched until the next install.
            ScriptEvents::POST_AUTOLOAD_DUMP => ['afterDumpAutoload', self::PRIORITY],
        ];
    }

    public function afterInstall(): void
    {
        $this->patch(false);
    }

    public function afterUpdate(): void
    {
        $this->patch(true);
    }

    public function afterDumpAutoload(): void
    {
        // The event also fires halfway through install and update, before the root files are deployed.
        if (self::invokedCommand() === 'dump-autoload') {
            $this->patch(false);
        }
    }

    public function patch(bool $writeLock): void
    {
        if ($this->composer === null || $this->io === null) {
            return;
        }

        $runtime = Runtime::fromComposer($this->composer);
        $dryRun = $runtime->settings()->isDryRun();
        $summary = $runtime->run(!$dryRun);
        $lock = $runtime->lock();

        if ($writeLock && !$dryRun && ($lock['patches'] !== [] || $runtime->lockFile()->exists()) && $runtime->lockFile()->write($lock)) {
            $this->io->write('<info>Wrote ' . LockFile::NAME . '</info>');
        }

        if ($summary->results() === [] && $summary->errors() === []) {
            return;
        }

        foreach ($summary->errors() as $error) {
            $this->io->writeError('<error>' . TextReport::escape($error) . '</error>');
        }

        $advisories = $runtime->advisories();
        $this->io->write('<info>Magento security ' . TextReport::escape($summary->headline()) . '</info>' . ($dryRun ? ' (dry run, nothing written)' : ''));
        foreach (array_merge(TextReport::advisories($advisories), TextReport::results($summary, false)) as $line) {
            $this->io->write($line);
        }
        $this->io->write(TextReport::closing($summary, $advisories));

        if ($summary->exitCode() === Summary::OK || $dryRun || $runtime->settings()->allowsUnpatched()) {
            return;
        }

        // A message in hundreds of lines of Composer output is missed; a failed run stops the deploy.
        throw new \RuntimeException(
            'Security patches are not in place. Run "composer magento-patches:status -v" for detail, '
            . 'or set extra.magento-patches.allow-unpatched to true to continue anyway.'
        );
    }

    /**
     * Composer accepts abbreviations, so "du" and "dumpautoload" count too. When nothing can be read, patch:
     * running twice costs time, not running leaves a store unpatched.
     */
    private static function invokedCommand(): string
    {
        $argv = $_SERVER['argv'] ?? null;

        if (!is_array($argv)) {
            return 'dump-autoload';
        }

        foreach (array_slice($argv, 1) as $argument) {
            if (is_string($argument) && $argument !== '' && $argument[0] !== '-') {
                return strpos($argument, 'du') === 0 ? 'dump-autoload' : $argument;
            }
        }

        return 'dump-autoload';
    }
}
