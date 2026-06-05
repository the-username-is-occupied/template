<?php

declare(strict_types=1);

namespace App\Enums;

enum TechAccountPoolType: string
{
    case Free = 'free';
    case Plus = 'plus';
    case Pro = 'pro';
    case Ultra = 'ultra';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
