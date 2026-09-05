<?php

namespace PHPinnacle\Ferry\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Support\Facades\Config;

class Model extends BaseModel
{
    public function getConnectionName(): ?string
    {
        $default = parent::getConnectionName();

        return config('phpinnacle-ferry.connection', $default) === null
            ? null
            : Config::string('phpinnacle-ferry.connection', $default);
    }
}
