<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Layout;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AppVersionFooterTest extends WebTestCase
{
    public function testPublicLayoutShowsAppVersionInFooter(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('footer', Kernel::APP_VERSION);
    }
}
