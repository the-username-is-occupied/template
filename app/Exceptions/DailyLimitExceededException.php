<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class DailyLimitExceededException extends \RuntimeException
{
    public function __construct(string $message = 'Достигнут дневной лимит сообщений.', int $code = 0, ?Throwable $previous = null)
    {
        return parent::__construct($message, $code, $previous);
    }
}
