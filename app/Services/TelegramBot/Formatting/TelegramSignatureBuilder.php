<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Собирает дисклеймер об AI-ответах со ссылкой на бота и кэширует
 * уже экранированный результат (текст статичен для всего запроса).
 */
final class TelegramSignatureBuilder
{
    private const DISCLAIMER_TEXT = 'Ответы AI могут быть неточны. Обязательно проверяйте их.';

    private const LINK_LABEL = 'Сгенерировано в Eolithic';

    private const BOT_URL = 'https://t.me/eolithic_bot';

    public function build(): string
    {
        return "\n\n<i>".self::DISCLAIMER_TEXT."</i>\n<strong><a href=\"".self::BOT_URL.'">'.self::LINK_LABEL.'</a></strong>';
    }
}
