<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class UnsupportedSourceTypeException extends Exception
{
    public function __construct(string $type)
    {
        parent::__construct("Unsupported source type: {$type}");
    }
}
