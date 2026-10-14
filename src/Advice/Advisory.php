<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Advice;

final class Advisory
{
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    /** @var string */
    private $level;

    /** @var string */
    private $message;

    public function __construct(string $level, string $message)
    {
        $this->level = $level;
        $this->message = $message;
    }

    public function level(): string
    {
        return $this->level;
    }

    public function message(): string
    {
        return $this->message;
    }
}
