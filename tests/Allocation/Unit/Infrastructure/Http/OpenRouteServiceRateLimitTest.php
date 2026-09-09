<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Http;

use App\Allocation\Infrastructure\Http\OpenRouteServiceRateLimit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouteServiceRateLimitTest extends TestCase
{
    public function testDetectsTooManyRequests(): void
    {
        self::assertTrue(OpenRouteServiceRateLimit::detected(429, '{}'));
    }

    public function testDetectsForbiddenQuotaExceeded(): void
    {
        self::assertTrue(OpenRouteServiceRateLimit::detected(403, '{"error":"Quota exceeded"}'));
        self::assertFalse(OpenRouteServiceRateLimit::detected(403, '{"error":"forbidden"}'));
        self::assertFalse(OpenRouteServiceRateLimit::detected(401, '{"error":"Rate limit exceeded"}'));
    }

    public function testReadsNumericRetryAfterHeader(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '15'],
            ]),
        ]);
        $response = $httpClient->request('GET', 'https://example.test');

        self::assertSame(15, OpenRouteServiceRateLimit::retryAfterSeconds($response));
    }

    public function testDetectsAdditionalQuotaPhrases(): void
    {
        self::assertTrue(OpenRouteServiceRateLimit::detected(403, 'Rate limit exceeded'));
        self::assertTrue(OpenRouteServiceRateLimit::detected(403, 'Limit exceeded for key'));
        self::assertTrue(OpenRouteServiceRateLimit::detected(403, 'Too many requests'));
    }

    public function testReturnsNullWhenRetryAfterHeaderIsMissingOrInvalid(): void
    {
        $missing = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 429]),
        ])->request('GET', 'https://example.test');
        self::assertNull(OpenRouteServiceRateLimit::retryAfterSeconds($missing));

        $invalid = new MockHttpClient([
            new MockResponse('{}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => 'not-a-date'],
            ]),
        ])->request('GET', 'https://example.test');
        self::assertNull(OpenRouteServiceRateLimit::retryAfterSeconds($invalid));
    }

    public function testParsesHttpDateRetryAfterHeader(): void
    {
        $httpDate = gmdate('D, d M Y H:i:s', time() + 20).' GMT';
        $httpClient = new MockHttpClient([
            new MockResponse('{}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => $httpDate],
            ]),
        ]);
        $response = $httpClient->request('GET', 'https://example.test');

        $seconds = OpenRouteServiceRateLimit::retryAfterSeconds($response);
        self::assertNotNull($seconds);
        self::assertGreaterThanOrEqual(18, $seconds);
        self::assertLessThanOrEqual(20, $seconds);
    }
}
