<?php

declare(strict_types=1);

namespace Disrex\MagentoPatchInstaller\Config;

final class Catalog
{
    /** @var Definition[] */
    private $definitions;

    /** @var string[] */
    private $errors;

    /**
     * @param Definition[] $definitions in apply order
     * @param string[] $errors
     */
    public function __construct(array $definitions, array $errors)
    {
        $this->definitions = $definitions;
        $this->errors = $errors;
    }

    /**
     * @return Definition[]
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, int> owner => number of definitions
     */
    public function owners(): array
    {
        $owners = [];

        foreach ($this->definitions as $definition) {
            $owners[$definition->owner()] = ($owners[$definition->owner()] ?? 0) + 1;
        }

        return $owners;
    }
}
