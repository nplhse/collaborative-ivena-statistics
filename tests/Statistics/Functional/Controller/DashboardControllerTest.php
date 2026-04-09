<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    public function testSomething(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('title', 'Statistics');
    }
}
