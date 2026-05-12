<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SentryTestControllerTest extends WebTestCase
{
    public function testDebugRouteIsNotAvailableInTestEnvironment(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_debug/sentry/test');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
