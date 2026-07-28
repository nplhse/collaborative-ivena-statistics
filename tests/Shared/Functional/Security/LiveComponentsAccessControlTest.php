<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class LiveComponentsAccessControlTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testAnalysisExplorerShellRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_components/AnalysisExplorerShell');

        self::assertResponseRedirects('/login');
    }

    public function testBenchmarkSelectionFormRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/_components/BenchmarkSelectionForm');

        self::assertResponseRedirects('/login');
    }

    public function testAnalysisExplorerShellDoesNotRedirectAuthenticatedUserToLogin(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/_components/AnalysisExplorerShell');

        $response = $client->getResponse();
        self::assertFalse(
            $response->isRedirect('/login'),
            'Authenticated ROLE_USER must pass /_components access_control (not redirected to login).',
        );
    }
}
