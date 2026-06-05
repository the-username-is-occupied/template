<?php

declare(strict_types=1);

namespace App\Enums;

enum TechAccountStatus: string
{
    case Initializing = 'initializing';
    case Active = 'active';
    case Inactive = 'inactive';
    case Banned = 'banned';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
