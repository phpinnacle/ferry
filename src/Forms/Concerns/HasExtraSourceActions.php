<?php

namespace PHPinnacle\Ferry\Forms\Concerns;

use Filament\Actions\Action;

trait HasExtraSourceActions
{
    /** @var list<string> */
    protected array $extraSourceActions = [];

    /** @param list<Action> $actions */
    public function extraSourceActions(array $actions): static
    {
        foreach ($actions as $action) {
            $name = $action->getName();

            if ($name !== null) {
                $this->extraSourceActions[] = $name;
            }
        }

        $this->registerActions($actions);

        return $this;
    }

    /** @return list<Action> */
    public function getExtraSourceActions(): array
    {
        return array_values(array_intersect_key($this->getActions(), array_flip($this->extraSourceActions)));
    }
}
