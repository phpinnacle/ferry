<?php

namespace PHPinnacle\Ferry\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;

class Model extends BaseModel
{
    public function getConnectionName(): ?string
    {
        /** @var string|null */
        return config('phpinnacle-ferry.connection', parent::getConnectionName());
    }
}
