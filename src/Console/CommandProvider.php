<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Console;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

final class CommandProvider implements ComposerCommandProvider
{
    public function getCommands(): array
    {
        return [new ListCommand(), new StatusCommand(), new ApplyCommand(), new VerifyCommand(), new LockCommand()];
    }
}
