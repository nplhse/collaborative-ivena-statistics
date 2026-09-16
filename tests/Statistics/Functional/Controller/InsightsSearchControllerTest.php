<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class InsightsSearchControllerTest extends WebTestCase
{
    use Factories;

    public function testSearchReturnsJsonHitsWithoutCounts(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-search-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $indication = IndicationNormalizedFactory::createOne(['name' => 'SearchableSTEMI', 'code' => 8801]);

        $client->request(Request::METHOD_GET, '/statistics/insights/search', [
            'q' => 'Searchable',
            'scope' => 'public',
            'period' => 'all',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('Searchable', $payload['query']);
        self::assertNotEmpty($payload['results']);
        $hit = $payload['results'][0];
        self::assertSame($indication->getId(), $hit['id']);
        self::assertStringContainsString('SearchableSTEMI', $hit['label']);
        self::assertSame('indications', $hit['dimension']);
        self::assertStringContainsString('/statistics/insights/indications/'.$indication->getId(), $hit['url']);
        self::assertArrayNotHasKey('count', $hit);
    }

    public function testSearchCanRestrictToOneDimension(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-search-dim-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        IndicationNormalizedFactory::createOne(['name' => 'SearchableSTEMI', 'code' => 8802]);

        $client->request(Request::METHOD_GET, '/statistics/insights/search', [
            'q' => 'Searchable',
            'dimension' => 'assignments',
            'scope' => 'public',
            'period' => 'all',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['results']);
    }

    public function testShortQueryReturnsEmptyResults(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-search-short-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/statistics/insights/search', [
            'q' => 'S',
            'scope' => 'public',
            'period' => 'all',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['results']);
    }
}
