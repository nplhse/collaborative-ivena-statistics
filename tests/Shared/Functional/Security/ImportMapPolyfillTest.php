<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ImportMapPolyfillTest extends WebTestCase
{
    public function testHomepageDoesNotLoadEsModuleShimsFromJspmCdn(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertStringNotContainsString('ga.jspm.io', $content);
        self::assertMatchesRegularExpression(
            '/script\.src\s*=\s*[\'"][^\'"]*es-module-shims/',
            $content,
        );
        self::assertDoesNotMatchRegularExpression(
            '/script\.src\s*=\s*[\'"]https?:\/\//',
            $content,
        );
    }
}
