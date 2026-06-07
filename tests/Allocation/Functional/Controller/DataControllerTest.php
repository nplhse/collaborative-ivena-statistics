<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DataControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testOverviewIsDisplayed(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Explore Data');
        self::assertSelectorTextContains('h2', 'Explore Data');
    }
}
