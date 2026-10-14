<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Disrex\MagentoPatchInstaller\Lock\LockFile;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class LockCommand extends PatchesCommand
{
    protected function configure(): void
    {
        $this->setName('magento-patches:lock');
        $this->setDescription('Write ' . LockFile::NAME . ', the record of the patches this project takes');
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Exit 1 when the file is missing or out of date, and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->runtime();
        $this->writeErrors($output, $runtime->catalog()->errors());
        $lock = $runtime->lock();
        $file = $runtime->lockFile();

        if ($input->getOption('check')) {
            $current = $file->matches($lock);
            $output->writeln($current ? LockFile::NAME . ' is up to date' : '<error>' . LockFile::NAME . ' is missing or out of date; run composer magento-patches:lock</error>');

            return $current ? 0 : 1;
        }

        $output->writeln($file->write($lock) ? 'Wrote ' . LockFile::NAME : LockFile::NAME . ' was already up to date');

        return 0;
    }
}
