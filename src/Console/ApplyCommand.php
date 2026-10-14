<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Disrex\MagentoPatchInstaller\Report\JsonReport;
use Disrex\MagentoPatchInstaller\Report\TextReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ApplyCommand extends PatchesCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('magento-patches:apply');
        $this->setDescription('Apply every patch that is missing and take off the ones this project skips');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->runtime();
        $dryRun = $runtime->settings()->isDryRun() || (bool) $input->getOption('dry-run');
        $summary = $runtime->run(!$dryRun);
        $this->writeErrors($output, $summary->errors());

        if ($input->getOption('json')) {
            $output->writeln(JsonReport::results($summary));

            return $summary->exitCode();
        }

        $advisories = $runtime->advisories();
        $output->writeln(TextReport::escape($summary->headline()) . ($dryRun ? ' (dry run, nothing written)' : ''));
        foreach (array_merge(TextReport::advisories($advisories), TextReport::results($summary, $output->isVerbose())) as $line) {
            $output->writeln($line);
        }
        $output->writeln('');
        $output->writeln(TextReport::closing($summary, $advisories));

        return $summary->exitCode();
    }
}
