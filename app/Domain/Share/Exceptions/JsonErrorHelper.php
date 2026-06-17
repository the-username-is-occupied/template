<?php

declare(strict_types=1);

namespace App\Domain\Share\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class JsonErrorHelper
{
    public static function handle(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $throwable): ?JsonResponse {

            Log::error('Exception occurred:', [
                'message' => $throwable->getMessage(),
                'exception' => get_class($throwable),
                'stack_trace' => $throwable->getTraceAsString(),
            ]);

            if (! static::wantsJson()) {
                return null;
            }

            return match (true) {

                $throwable instanceof ValidationException => static::makeResponse('The given data was invalid.', $throwable->status, $throwable->errors()),

                $throwable instanceof ModelNotFoundException => static::makeResponse('Resource not found.', 404),

                $throwable instanceof NotFoundHttpException => static::makeResponse($throwable->getMessage(), 404),

                $throwable instanceof MethodNotAllowedHttpException => static::makeResponse('Method not allowed.', 405),

                $throwable instanceof AuthenticationException => static::makeResponse('Unauthenticated.', 401),

                $throwable instanceof AccessDeniedHttpException => static::makeResponse('Forbidden.', 403),

                $throwable instanceof ThrottleRequestsException => static::makeResponse('Too Many Requests.', 429),

                $throwable instanceof HttpExceptionInterface => static::makeResponse($throwable->getMessage() !== '' ? $throwable->getMessage() : 'HTTP Error.', $throwable->getStatusCode()),

                default => static::makeResponse(config('app.debug') ? ($throwable->getMessage() ?: 'Server Error.') : 'Server Error.', 500)
            };
        });
    }

    public static function wantsJson(): bool
    {
        $request = request();

        if (! $request) {
            return false;
        }

        if ($request->expectsJson()) {
            return true;
        }

        return (bool) preg_match('#^/api(/|$)#', '/'.ltrim($request->path(), '/'));
    }

    public static function makeResponse(string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $status,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if (config('app.debug') === true) {
            $payload['timestamp'] = now()->toISOString();
        }

        Log::channel('requests')->error('Error:', $payload);

        return response()->json($payload, $status);
    }
}
