<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
