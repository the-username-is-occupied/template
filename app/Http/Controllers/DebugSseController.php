<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DebugSsePublishRequest;
use App\Support\MercurePublisher;
use Illuminate\Http\JsonResponse;

final class DebugSseController extends Controller
{
    public function __construct(private readonly MercurePublisher $mercurePublisher) {}

    public function config(): JsonResponse
    {
        $topic = (string) config('services.mercure.topic_prefix');
        $publicUrl = (string) config('services.mercure.public_url');

        return response()->json([
            'topic' => $topic,
            'hub_url' => $publicUrl,
            'publish_url' => route('api.debug.sse.publish'),
        ]);
    }

    public function publish(DebugSsePublishRequest $request): JsonResponse
    {
        $message = (string) $request->validated('message');
        $topic = (string) config('services.mercure.topic_prefix');

        $this->mercurePublisher->publish($topic, json_encode([
            'message' => $message,
            'sent_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
