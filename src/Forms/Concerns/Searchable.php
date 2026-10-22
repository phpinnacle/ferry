<?php

namespace PHPinnacle\Ferry\Forms\Concerns;

use Closure;

trait Searchable
{
    /** @var bool|Closure(): bool */
    protected bool|Closure $isSearchable = false;

    /** @param bool|Closure(): bool $condition */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->evaluate($this->isSearchable);
    }
}
