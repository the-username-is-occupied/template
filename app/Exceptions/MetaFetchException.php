<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class MetaFetchException extends Exception
{
    public function __construct(string $message = 'Failed to fetch metadata', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
