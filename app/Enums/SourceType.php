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
    case Docx = 'docx';
    case Csv = 'csv';
    case Pptx = 'pptx';
    case Epub = 'epub';
    case Mp3 = 'mp3';
    case Mp4 = 'mp4';
    case Jpg = 'jpg';
    case Png = 'png';
    case Text = 'text';
    case Audio = 'audio';
    case Video = 'video';
    case Image = 'image';
}
