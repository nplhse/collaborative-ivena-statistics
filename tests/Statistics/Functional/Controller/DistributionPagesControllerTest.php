<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DistributionPagesControllerTest extends WebTestCase
{
    /**
     * @return \Generator<string, array{0: string}>
     */
    public static function distributionPathsProvider(): \Generator
    {
        yield 'urgency' => ['/statistics/distribution/urgency'];
        yield 'gender' => ['/statistics/distribution/gender'];
        yield 'age' => ['/statistics/distribution/age'];
        yield 'assignment' => ['/statistics/distribution/assignment'];
        yield 'occasion' => ['/statistics/distribution/occasion'];
        yield 'time' => ['/statistics/distribution/time'];
        yield 'transport_time' => ['/statistics/distribution/transport-time'];
        yield 'resources' => ['/statistics/distribution/resources'];
        yield 'traits' => ['/statistics/distribution/traits'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('distributionPathsProvider')]
    public function testDistributionPageIsReachable(string $path): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
    }
}
