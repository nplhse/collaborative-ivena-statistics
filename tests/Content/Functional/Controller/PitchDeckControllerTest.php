<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PitchDeckControllerTest extends WebTestCase
{
    public function testPitchDeckReturnsNotFoundWhenDisabled(): void
    {
        $_SERVER['PITCH_DECK_ENABLED'] = '0';

        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/deck/versorgungsforschung');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        unset($_SERVER['PITCH_DECK_ENABLED']);
    }

    public function testPitchDeckIsReachableWhenEnabled(): void
    {
        $_SERVER['PITCH_DECK_ENABLED'] = '1';

        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/deck/versorgungsforschung');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Versorgungsforschung.io', $client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('Making EMS allocation routine data usable', $client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('Automated processing', $client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('Directing patient flow', $client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('https://versorgungsforschung.io/', $client->getResponse()->getContent() ?: '');

        unset($_SERVER['PITCH_DECK_ENABLED']);
    }
}
