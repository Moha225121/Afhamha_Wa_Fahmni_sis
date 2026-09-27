<?php

namespace App\Exceptions;

use Throwable;

class SmartTutorUnavailableException extends SmartTutorGatewayException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::NOT_CONFIGURED, $previous);
    }
}
