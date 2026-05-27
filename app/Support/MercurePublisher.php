<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MercurePublisher
{
    public function publish(string $topic, string $data): void
    {
        $hubUrl = (string) config('services.mercure.hub_url');
        $secret = (string) config('services.mercure.jwt_secret');

        $response = Http::asForm()
            ->withToken($this->createPublisherJwt($secret))
            ->post($hubUrl, [
                'topic' => $topic,
                'data' => $data,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to publish message to Mercure hub.');
        }
    }

    private function createPublisherJwt(string $secret): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $payload = $this->base64UrlEncode(json_encode([
            'mercure' => [
                'publish' => ['*'],
            ],
            'exp' => now()->addHour()->timestamp,
        ], JSON_THROW_ON_ERROR));

        $signature = hash_hmac('sha256', "{$header}.{$payload}", $secret, true);

        return "{$header}.{$payload}.".$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
