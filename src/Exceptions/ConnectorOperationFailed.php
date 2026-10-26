<?php

namespace PHPinnacle\Ferry\Exceptions;

use RuntimeException;
use Throwable;

class ConnectorOperationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $connectorId,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
