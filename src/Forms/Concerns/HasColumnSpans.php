<?php

namespace PHPinnacle\Ferry\Forms\Concerns;

trait HasColumnSpans
{
    /** @var array{source: positive-int, target: positive-int, gap: positive-int} */
    protected array $columnSpans = ['source' => 5, 'target' => 5, 'gap' => 2];

    /**
     * @param positive-int $source
     * @param positive-int $target
     * @param positive-int $gap
     */
    public function columnSpans(int $source = 5, int $target = 5, int $gap = 2): static
    {
        $this->columnSpans = ['source' => $source, 'target' => $target, 'gap' => $gap];

        return $this;
    }

    /** @return array{source: positive-int, target: positive-int, gap: positive-int} */
    public function getColumnSpans(): array
    {
        return $this->columnSpans;
    }
}
