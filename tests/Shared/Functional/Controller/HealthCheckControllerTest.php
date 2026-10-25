<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class HealthCheckControllerTest extends WebTestCase
{
    public function testHealthEndpointIsPublicAndReturnsSlimJson(): void
    {
        $client = self::createClient();
        $this->store(StorageMeasurementBuilder::create(new \DateTimeImmutable('-1 hour')));

        $payload = $this->requestHealth($client);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('checks', $payload);
        self::assertArrayNotHasKey('version', $payload);
        self::assertSame(['database' => 'ok', 'storage' => 'ok'], $payload['checks']);
        self::assertArrayNotHasKey('messenger_failed', $payload['checks']);
        self::assertContains($payload['status'], ['healthy', 'degraded']);
    }

    public function testCriticalStorageMakesTheEndpointUnhealthyWithoutShowingSizes(): void
    {
        $client = self::createClient();
        $this->store(StorageMeasurementBuilder::create(new \DateTimeImmutable('-1 hour'), CapacityLevel::Critical, usedBytes: 10 * 1024 ** 3 - 1));

        $payload = $this->requestHealth($client);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['status' => 'unhealthy', 'checks' => ['database' => 'ok', 'storage' => 'critical']], $payload);
        self::assertStringNotContainsString('GB', (string) $client->getResponse()->getContent());
    }

    public function testStaleStorageMeasurementDegradesTheEndpoint(): void
    {
        $client = self::createClient();
        $this->store(StorageMeasurementBuilder::create(new \DateTimeImmutable('-3 days')));

        $payload = $this->requestHealth($client);

        self::assertResponseIsSuccessful();
        self::assertSame('degraded', $payload['status']);
        self::assertSame('stale', $payload['checks']['storage']);
    }

    public function testMissingStorageMeasurementDegradesTheEndpoint(): void
    {
        $client = self::createClient();

        $payload = $this->requestHealth($client);

        self::assertResponseIsSuccessful();
        self::assertSame('degraded', $payload['status']);
        self::assertSame('unavailable', $payload['checks']['storage']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestHealth(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, '/health');

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }

    private function store(StorageMeasurement $measurement): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($measurement);
        $entityManager->flush();
    }
}
