<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Disrex\MagentoPatchInstaller\Report\JsonReport;
use Disrex\MagentoPatchInstaller\Report\Summary;
use Disrex\MagentoPatchInstaller\Report\TextReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends PatchesCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('magento-patches:list');
        $this->setDescription('List every patch the trusted packages declare and whether it is meant for this install');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->runtime();
        $catalog = $runtime->catalog();
        $engine = $runtime->engine();
        $errors = $catalog->errors();

        // Reads no vendor file, so it answers on a fresh clone; an unreadable patch for this install is still an error.
        foreach ($catalog->definitions() as $definition) {
            $files = $engine->filePatches($definition);
            if (is_string($files) && $engine->baseMismatch($definition) === null) {
                $errors[] = sprintf('%s: patch "%s" cannot be read: %s (%s)', $definition->owner(), $definition->label(), $files, $definition->sourceName());
            }
        }

        $this->writeErrors($output, $errors);

        if ($input->getOption('json')) {
            $output->writeln(JsonReport::catalog($catalog, $engine, $errors));
        } else {
            foreach (TextReport::catalog($catalog, $engine, $output->isVerbose()) as $line) {
                $output->writeln($line);
            }
            $output->writeln('');
        }

        return $errors === [] ? Summary::OK : Summary::CONFIG_ERROR;
    }
}
