<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Disrex\MagentoPatchInstaller\Report\JsonReport;
use Disrex\MagentoPatchInstaller\Report\Summary;
use Disrex\MagentoPatchInstaller\Report\TextReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends PatchesCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('magento-patches:status');
        $this->setDescription('Show this store and whether every patch meant for it is in place');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->runtime();
        $summary = $runtime->run(false);
        $this->writeErrors($output, $summary->errors());

        if ($input->getOption('json')) {
            $output->writeln(JsonReport::results($summary));
        } else {
            $advisories = $runtime->advisories();
            $output->writeln('');
            foreach (TextReport::store($runtime->store()) as $line) {
                $output->writeln($line);
            }
            $output->writeln('');
            $output->writeln(TextReport::escape($summary->headline()));
            foreach (array_merge(TextReport::advisories($advisories), TextReport::results($summary, $output->isVerbose())) as $line) {
                $output->writeln($line);
            }
            $output->writeln('');
            $output->writeln(TextReport::closing($summary, $advisories));
        }

        // Reporting does not judge, except that a broken configuration has nothing trustworthy to report.
        return $summary->errors() === [] ? Summary::OK : Summary::CONFIG_ERROR;
    }
}
