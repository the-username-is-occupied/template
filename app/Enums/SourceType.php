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
    case Pdf = 'pdf';
    case Text = 'text';
    case Audio = 'audio';
    case Video = 'video';
}
