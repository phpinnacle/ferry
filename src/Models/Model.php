<?php

namespace PHPinnacle\Ferry\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;

class Model extends BaseModel
{
    public function getConnectionName(): ?string
    {
        return config('phpinnacle-ferry.connection', parent::getConnectionName());
    }
}
