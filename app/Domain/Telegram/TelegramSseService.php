<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Telegram\DTOs\TelegramPostDTO;
use App\Support\MercurePublisher;
use Illuminate\Support\Facades\Config;

class TelegramSseService
{
    public function __construct(
        private readonly MercurePublisher $mercurePublisher
    ) {}

    /**
     * Отправляет посты в SSE канал
     *
     * @param  TelegramPostDTO[]  $posts
     */
    public function sendPosts(array $posts): void
    {
        $topic = Config::string('services.mercure.topic_prefix', 'telegram-posts');

        foreach ($posts as $post) {
            $this->mercurePublisher->publish($topic, json_encode([
                'url' => $post->url,
                'text' => $post->text,
                'links' => $post->links,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
