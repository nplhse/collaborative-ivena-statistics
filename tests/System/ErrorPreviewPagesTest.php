<?php

declare(strict_types=1);

namespace App\Tests\System;

use App\Tests\Support\System\SystemWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ErrorPreviewPagesTest extends SystemWebTestCase
{
    #[DataProvider('providePreviewCodes')]
    public function testErrorPreviewRendersCustomPage(int $code, string $expectedTitleFragment, string $expectedHeader): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_error_preview/'.$code);

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('class="empty"', $content);
        self::assertStringContainsString('<div class="empty-header">'.$expectedHeader.'</div>', $content);
        self::assertStringContainsString($expectedTitleFragment, $content);
        self::assertStringContainsString('btn btn-primary', $content);
        self::assertStringContainsString('btn btn-outline-secondary', $content);
        self::assertStringContainsString('javascript:history.back()', $content);
        self::assertStringContainsString('href="/"', $content);
    }

    public static function providePreviewCodes(): \Generator
    {
        yield '404' => [404, 'Page not found', '404'];
        yield '403' => [403, 'Access denied', '403'];
        yield '500' => [500, 'Internal error', '500'];
    }

    public function testErrorPreviewRejectsNonClientOrServerCodes(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_error_preview/200');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testErrorPreviewDoesNotRequireAuthentication(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_error_preview/404');

        self::assertResponseIsSuccessful();
        self::assertLessThan(
            Response::HTTP_MULTIPLE_CHOICES,
            $client->getResponse()->getStatusCode(),
            'Expected HTML response, not a redirect to login.',
        );
    }
}
