<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DistributionPagesControllerTest extends WebTestCase
{
    public function testUrgencyDistributionPageIsReachable(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/statistics/distribution/urgency');

        self::assertResponseIsSuccessful();
    }

    public function testGenderDistributionPageIsReachable(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/statistics/distribution/gender');

        self::assertResponseIsSuccessful();
    }

    public function testAgeCohortDistributionPageIsReachable(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/statistics/distribution/age');

        self::assertResponseIsSuccessful();
    }
}
