<?php

namespace App\Tests\Integration\Controller\Data;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AreaControllerTest extends WebTestCase
{
    public function testSomething(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/data/area');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'List Areas');
    }
}
