<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OpenRouteServiceRateLimit
{
    public static function detected(int $statusCode, string $responseBody): bool
    {
        if (429 === $statusCode) {
            return true;
        }

        if (403 !== $statusCode) {
            return false;
        }

        $normalized = strtolower($responseBody);

        return str_contains($normalized, 'quota')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'limit exceeded')
            || str_contains($normalized, 'too many requests');
    }

    public static function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $headers = $response->getHeaders(false);
        $raw = $headers['retry-after'][0] ?? null;
        if (!\is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $value = trim($raw);
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);
        if (false === $timestamp) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}
