<?php

declare(strict_types=1);

namespace App\Enums;

enum SourceType: string
{
    case TelegramChannel = 'telegram_channel';
    case TelegramPost = 'telegram_post';
    case YoutubeChannel = 'youtube_channel';
    case YoutubeVideo = 'youtube_video';
    case Website = 'website';
    case File = 'file';
    case Text = 'text';

    public function isFile(): bool
    {
        return $this === self::File;
    }

    public function label()
    {
        return match ($this) {
            self::TelegramChannel, self::TelegramPost => 'Telegram',
            self::YoutubeChannel, self::YoutubeVideo => 'Youtube',
            default => 'Исчтоник'
        };
    }
}
