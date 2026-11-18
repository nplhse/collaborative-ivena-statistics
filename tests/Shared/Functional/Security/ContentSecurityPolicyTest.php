<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Tests\Support\Kernel\ProdKernelForTests;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ContentSecurityPolicyTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    /**
     * Both prod tests share one compiled prod container: the report URI is read at runtime. CI compiles it before
     * the tests (bin/warmup-test-kernels, TEST_PREWARMED_KERNELS=1). Otherwise it is built fresh for this class,
     * because a non-debug container does not notice changed files.
     */
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::removeProdCacheUnlessPrewarmed();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::removeProdCacheUnlessPrewarmed();

        parent::tearDownAfterClass();
    }

    public function testProdIncludesCspReportOnlyHeader(): void
    {
        $this->assertProdHealthCspHeader(env: [
            'SENTRY_CSP_REPORT_URI' => '',
        ]);
    }

    public function testProdIncludesReportUriWhenSentryCspReportUriIsSet(): void
    {
        $reportUri = 'https://o1234567890.ingest.sentry.io/api/1/security/?sentry_key=test';

        $this->assertProdHealthCspHeader(
            expectedReportUri: $reportUri,
            env: ['SENTRY_CSP_REPORT_URI' => $reportUri],
        );
    }

    public function testTestEnvHasNoCspHeader(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testExploreAllocationAndStatisticsLoad(): void
    {
        $client = self::createClient();
        $this->loginAsParticipant($client);

        $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertResponseIsSuccessful();

        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $env
     */
    private function assertProdHealthCspHeader(?string $expectedReportUri = null, array $env = []): void
    {
        $databaseUrl = $this->resolveProdDatabaseUrl();
        if (null !== $databaseUrl) {
            $env['DATABASE_URL'] = $databaseUrl;
        }

        $envSnapshot = $this->snapshotEnv(array_keys($env));
        $kernel = new ProdKernelForTests();

        try {
            foreach ($env as $key => $value) {
                $this->setEnv($key, $value);
            }

            self::ensureKernelShutdown();
            $kernel->boot();

            $response = $kernel->handle(Request::create('https://localhost/health', Request::METHOD_GET));

            self::assertSame(200, $response->getStatusCode());

            $header = $response->headers->get('Content-Security-Policy-Report-Only');
            self::assertNotNull($header);
            self::assertStringContainsString("default-src 'self'", $header);
            self::assertStringContainsString("connect-src 'self'", $header);
            self::assertStringContainsString('https://*.ingest.sentry.io', $header);
            self::assertStringContainsString("script-src 'self'", $header);
            self::assertStringContainsString("'unsafe-inline'", $header);

            if (null !== $expectedReportUri) {
                self::assertStringContainsString('report-uri '.$expectedReportUri, $header);
            } else {
                self::assertStringNotContainsString('report-uri', $header);
            }
        } finally {
            $kernel->shutdown();
            $this->restoreEnv($envSnapshot);
        }
    }

    private static function removeProdCacheUnlessPrewarmed(): void
    {
        $prewarmed = $_SERVER['TEST_PREWARMED_KERNELS'] ?? $_ENV['TEST_PREWARMED_KERNELS'] ?? getenv('TEST_PREWARMED_KERNELS');
        if (\in_array($prewarmed, ['1', 'true'], true)) {
            return;
        }

        new Filesystem()->remove(new ProdKernelForTests()->getCacheDir());
    }

    /**
     * Prod kernels do not apply Doctrine's test dbname suffix; point them at the same
     * migrated database ParaTest uses so /health does not fail on missing tables in CI.
     */
    private function resolveProdDatabaseUrl(): ?string
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || '' === $databaseUrl) {
            return null;
        }

        $path = parse_url($databaseUrl, PHP_URL_PATH);
        $databaseName = is_string($path) ? ltrim($path, '/') : '';
        if ('' === $databaseName || preg_match('/_test\d*$/', $databaseName)) {
            return $databaseUrl;
        }

        $token = $_ENV['TEST_TOKEN'] ?? $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $token = is_string($token) ? $token : '';

        $resolved = preg_replace(
            '#^(postgres(?:ql)?://[^/]+/)([^/?]+)(.*)$#i',
            '$1$2_test'.$token.'$3',
            $databaseUrl,
        );

        return is_string($resolved) ? $resolved : $databaseUrl;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, array{env: mixed, server: mixed, getenv: string|false}>
     */
    private function snapshotEnv(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }

        return $snapshot;
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv(sprintf('%s=%s', $key, $value));
    }

    /**
     * @param array<string, array{env: mixed, server: mixed, getenv: string|false}> $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $values) {
            if (null === $values['env']) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }

            if (null === $values['server']) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $values['server'];
            }

            if (false === $values['getenv']) {
                putenv($key);
            } else {
                putenv(sprintf('%s=%s', $key, $values['getenv']));
            }
        }
    }
}
