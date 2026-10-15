<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Disrex\MagentoPatchInstaller\Report\JsonReport;
use Disrex\MagentoPatchInstaller\Report\TextReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class VerifyCommand extends PatchesCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('magento-patches:verify');
        $this->setDescription('Exit 0 only when every patch meant for this install is in place; writes nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Never writes, not even to a root copy that drifted: healing it here would return 0 for a store that serves an unpatched file.
        $runtime = $this->runtime();
        $summary = $runtime->run(false);
        $this->writeErrors($output, $summary->errors());

        if ($input->getOption('json')) {
            $output->writeln(JsonReport::results($summary));

            return $summary->exitCode();
        }

        $advisories = $runtime->advisories();
        $output->writeln(TextReport::escape($summary->headline()));
        foreach (array_merge(TextReport::advisories($advisories), TextReport::results($summary, $output->isVerbose(), true)) as $line) {
            $output->writeln($line);
        }
        $output->writeln(TextReport::closing($summary, $advisories));

        return $summary->exitCode();
    }
}
