<?php

declare(strict_types=1);

namespace App\Enums;

enum SourceDraftStatus: string
{
    case FetchingMeta = 'fetching_meta';
    case AwaitingConfirm = 'awaiting_confirm';
    case Processing = 'processing';
    case AwaitingIndex = 'awaiting_index';
    case Indexing = 'indexing';
    case Done = 'done';
    case Abandoned = 'abandoned';
}
