<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Inspector\UXInspectorBundle;

/**
 * The Symfony UX Inspector is a development-only tool (issue #701). This guards
 * against registering the bundle outside the dev environment.
 */
final class UxInspectorNotExposedTest extends WebTestCase
{
    public function testBundleIsNotRegistered(): void
    {
        $kernel = self::bootKernel();

        foreach ($kernel->getBundles() as $bundle) {
            self::assertNotInstanceOf(UXInspectorBundle::class, $bundle);
        }
    }

    public function testInspectorScriptIsNotServed(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_ux/inspector.js');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPagesDoNotLoadTheInspector(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertStringNotContainsString('/_ux/inspector.js', $content);
    }
}
