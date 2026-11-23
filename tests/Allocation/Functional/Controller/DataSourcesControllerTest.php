<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller;

use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DataSourcesControllerTest extends WebTestCase
{
    use Factories;

    public function testAGuestSeesTheSourcesWithStoredProvenanceButNoDistrict(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $district = new District('06412', 'Frankfurt am Main');
        $geometry = new DistrictGeometry($district);
        $geometry->storeGeometry('{"type":"Polygon","coordinates":[]}', 'bkg', 'dl-de/by-2-0', '© BKG (2025) dl-de/by-2-0', null);
        $entityManager->persist($district);
        $entityManager->persist($geometry);
        $entityManager->flush();

        $crawler = $client->request('GET', '/data-sources');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.fw-bold', 'Data sources and licences');
        $text = $crawler->filter('[data-testid="data-sources"]')->text();
        self::assertStringContainsString('BKG, Verwaltungsgebiete', $text);
        self::assertStringContainsString('© BKG (2025) dl-de/by-2-0', $text);
        self::assertStringContainsString('openrouteservice', $text);
        self::assertStringNotContainsString('Frankfurt', $text);
        self::assertCount(1, $crawler->filter('a[href="https://www.govdata.de/dl-de/by-2-0"]'));
        self::assertGreaterThan(0, $crawler->filter('footer a[href="/data-sources"]')->count());
    }

    public function testAGeometryWithoutAttributionIsNotListed(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $district = new District('06412', 'Frankfurt am Main');
        $geometry = new DistrictGeometry($district);
        $geometry->storeGeometry('{"type":"Polygon","coordinates":[]}', 'bkg', null, null, null);
        $entityManager->persist($district);
        $entityManager->persist($geometry);
        $entityManager->flush();

        $crawler = $client->request('GET', '/data-sources');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('BKG, Verwaltungsgebiete', $crawler->filter('[data-testid="data-sources"]')->text());
    }
}
