<?php

namespace App\Tests\Allocation\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DataControllerTest extends WebTestCase
{
    public function testOverviewIsDisplayed(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request('GET', '/explore');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Explore Data');
        self::assertSelectorTextContains('h2', 'Explore Data');
    }
}
