<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DefaultControllerTest extends WebTestCase
{
    public function testHelloWorldIsDisplayed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hello, world!');
    }
}
