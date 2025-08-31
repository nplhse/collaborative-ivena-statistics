<?php

namespace App\Tests\Functional\Controller\Data;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DataControllerTest extends WebTestCase
{
    public function testOverviewIsDisplayed(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $crawler = $client->request('GET', '/data');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Data Overview');
        self::assertSelectorTextContains('h2', 'Data Overview');
    }
}
