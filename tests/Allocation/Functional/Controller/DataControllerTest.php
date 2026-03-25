<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class DataControllerTest extends WebTestCase
{
    public function testOverviewIsDisplayed(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(Request::METHOD_GET, '/explore');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Explore Data');
        self::assertSelectorTextContains('h2', 'Explore Data');
    }
}
