<?php

namespace App\Tests\Functional\Controller\Data\Indications;

use App\Factory\IndicationNormalizedFactory;
use App\Factory\IndicationRawFactory;
use App\Repository\IndicationRawRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AssignIndicationRawControllerTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testAssignLinksRawWithNormalized(): void
    {
        // Arrange
        $client = static::createClient();

        $normalized = IndicationNormalizedFactory::createOne([
            'code' => '123',
            'name' => 'Normalized Indication',
        ]);

        $raw = IndicationRawFactory::createOne([
            'code' => '123',
            'name' => 'Raw Indication',
        ]);

        // Act& Assert
        $crawler = $client->request('GET', sprintf('/data/indication/raw/assign/%d', $raw->getId()));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['indication_raw_assign[target_label]'] = 'Normalized Indication (123)';
        $form['indication_raw_assign[target]'] = (string) $normalized->getId();

        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Normalized indication has been assigned.');

        /** @var IndicationRawRepository $rawRepo */
        $rawRepo = self::getContainer()->get(IndicationRawRepository::class);
        $reloadedRaw = $rawRepo->find($raw->getId());

        self::assertNotNull($reloadedRaw, 'Raw entity should exist.');
        self::assertNotNull($reloadedRaw->getTarget(), 'Raw Indication should be assigned to a normalized Indication.');
        self::assertSame($normalized->getId(), $reloadedRaw->getTarget()->getId(), 'Assignment should match expected ID.');
    }
}
