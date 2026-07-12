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

    private ?string $cachedSignature = null;

    public function __construct(
        private readonly TelegramMarkdownEscaper $escaper,
    ) {}

    public function build(): string
    {
        if ($this->cachedSignature === null) {
            $raw = "\n\n_".self::DISCLAIMER_TEXT."_\n[".self::LINK_LABEL.']('.self::BOT_URL.')';
            $this->cachedSignature = $this->escaper->escape($raw);
        }

        return $this->cachedSignature;
    }
}
