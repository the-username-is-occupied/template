<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Class MercurePublisher
 * Handles publishing messages to the Mercure hub.
 */
final class MercurePublisher
{
    private const JWT_ALGORITHM = 'HS256';

    private const JWT_TYPE = 'JWT';

    /**
     * Publishes a message to the specified topic.
     *
     * @param  string  $topic  The topic to publish to.
     * @param  string  $data  The data to publish.
     *
     * @throws RuntimeException If publishing fails.
     */
    public function publish(string $topic, string $data): void
    {
        $hubUrl = $this->getConfig('hub_url');
        $secret = $this->getConfig('jwt_secret');

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

    /**
     * Creates a JWT for the publisher.
     *
     * @param  string  $secret  The secret used to sign the JWT.
     * @return string The generated JWT.
     */
    private function createPublisherJwt(string $secret): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => self::JWT_ALGORITHM,
            'typ' => self::JWT_TYPE,
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

    /**
     * Encodes a value using base64 URL encoding.
     *
     * @param  string  $value  The value to encode.
     * @return string The base64 URL encoded value.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Retrieves configuration values.
     *
     * @param  string  $key  The configuration key.
     * @return string The configuration value.
     */
    private function getConfig(string $key): string
    {
        return (string) config("services.mercure.{$key}");
    }
}
