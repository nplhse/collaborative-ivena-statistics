<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HealthCheckControllerTest extends WebTestCase
{
    public function testHealthEndpointIsPublicAndReturnsJson(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/health');

        self::assertResponseIsSuccessful();

        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('checks', $payload);
        self::assertSame(Kernel::APP_VERSION, $payload['version']);
        self::assertSame('ok', $payload['checks']['database']);
        self::assertContains($payload['status'], ['healthy', 'degraded']);
    }
}
