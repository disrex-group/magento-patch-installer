<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Composer\Command\BaseCommand;
use Disrex\MagentoPatchInstaller\Report\TextReport;
use Disrex\MagentoPatchInstaller\Runtime;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class PatchesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON instead of a report');
    }

    protected function runtime(): Runtime
    {
        // getComposer() exists in every Composer from 2.2 on; requireComposer() only from 2.3.
        $composer = $this->getComposer();

        if ($composer === null) {
            throw new \RuntimeException('No composer.json in the current directory.');
        }

        return Runtime::fromComposer($composer);
    }

    /**
     * Errors go to stderr, so --json output on stdout stays parseable.
     *
     * @param string[] $errors
     */
    protected function writeErrors(OutputInterface $output, array $errors): void
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ($errors as $error) {
            $stderr->writeln('<error>' . TextReport::escape($error) . '</error>');
        }
    }
}
