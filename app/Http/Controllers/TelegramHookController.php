<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Telegram\DTOs\TelegramPostDTO;
use App\Domain\Telegram\TelegramSseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramHookController extends Controller
{
    public function __construct(
        private readonly TelegramSseService $sseService
    ) {}

    /**
     * Обрабатывает webhook с массивом постов
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->all();

        // Маппим посты в DTO
        $posts = TelegramPostDTO::collect($data['posts'] ?? []);

        // Отправляем в SSE
        $this->sseService->sendPosts($posts);

        return response()->json([
            'status' => 'ok',
            'processed' => count($posts),
        ]);
    }
}
