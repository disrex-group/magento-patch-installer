<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Config;

final class Definition
{
    /** @var string */
    private $key;

    /** @var string */
    private $id;

    /** @var string|null */
    private $title;

    /** @var string */
    private $owner;

    /** @var string */
    private $source;

    /** @var string */
    private $sourceName;

    /** @var array<string, string> */
    private $base;

    /** @var string|null */
    private $line;

    /** @var bool */
    private $cumulative;

    /** @var string|null */
    private $description;

    /** @var string[] ids as written, before they are resolved to keys */
    private $dependsOn;

    /** @var string[] */
    private $requires = [];

    /** @var string|null */
    private $skipReason;

    /** @var bool */
    private $optional;

    /** @var string|null */
    private $enableReason;

    /**
     * @param array<string, string> $base package => version constraint
     * @param string[] $dependsOn
     */
    public function __construct(
        string $key,
        string $id,
        ?string $title,
        string $owner,
        string $source,
        string $sourceName,
        array $base,
        ?string $line,
        bool $cumulative,
        ?string $description,
        array $dependsOn,
        bool $optional = false
    ) {
        $this->key = $key;
        $this->id = $id;
        $this->title = $title;
        $this->owner = $owner;
        $this->source = $source;
        $this->sourceName = $sourceName;
        $this->base = $base;
        $this->line = $line;
        $this->cumulative = $cumulative;
        $this->description = $description;
        $this->dependsOn = $dependsOn;
        $this->optional = $optional;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }

    /**
     * @return array<string, string>
     */
    public function base(): array
    {
        return $this->base;
    }

    public function line(): ?string
    {
        return $this->line;
    }

    public function isCumulative(): bool
    {
        return $this->cumulative;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return string[]
     */
    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    /**
     * @return string[] keys of the definitions this one is built on, directly
     */
    public function requires(): array
    {
        return $this->requires;
    }

    /**
     * @param string[] $keys
     */
    public function setRequires(array $keys): void
    {
        $this->requires = $keys;
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }

    public function skip(string $reason): void
    {
        $this->skipReason = $reason;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function enableReason(): ?string
    {
        return $this->enableReason;
    }

    public function enable(string $reason): void
    {
        $this->enableReason = $reason;
    }

    public function isDormant(): bool
    {
        return $this->optional && $this->enableReason === null;
    }

    public function label(): string
    {
        return $this->id . ($this->title !== null && $this->title !== '' ? ' ' . $this->title : '')
            . ($this->line !== null ? ' [' . $this->line . ']' : '');
    }
}
