<?php

namespace PHPinnacle\Ferry\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPinnacle\Ferry\Models\Connector;

trait HasConnector
{
    public function recordConnector(Connector $connector): void
    {
        $this->connector()->associate($connector);

        if ($this->isDirty('connector_id')) {
            $this->saveQuietly();
        }
    }

    /** @return BelongsTo<Connector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class, 'connector_id');
    }
}
