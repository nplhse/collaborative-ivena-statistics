<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\CaseId;

use App\Import\Infrastructure\CaseId\CaseIdHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Case-ID hashes use APP_HASH_KEY, not APP_SECRET, so APP_SECRET can be rotated without new hashes (#1706, #1572).
 * .env.test sets the two keys to different values.
 */
#[CoversClass(CaseIdHasher::class)]
final class CaseIdHasherKeyTest extends KernelTestCase
{
    /** @var array<string, array{server: mixed, env: mixed}> */
    private array $originalVariables = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->originalVariables as $name => $original) {
            $this->restore($name, $original);
        }

        parent::tearDown();
    }

    public function testTheHashIsTheHmacWithAppHashKey(): void
    {
        $hash = $this->hashOf('123456');

        self::assertSame(hash_hmac('sha256', '123456', 'test-hash-key-not-for-production-0001', true), $hash);
    }

    public function testRotatingAppSecretKeepsTheHash(): void
    {
        $before = $this->hashOf('123456');
        $this->setVariable('APP_SECRET', 'rotated-app-secret-0000000000000000');

        $after = $this->hashOf('123456');

        self::assertSame($before, $after);
    }

    public function testWithoutAppHashKeyTheHashFallsBackToAppSecret(): void
    {
        $this->setVariable('APP_HASH_KEY', '');

        $hash = $this->hashOf('123456');

        self::assertSame(hash_hmac('sha256', '123456', '$ecretf0rt3st', true), $hash);
    }

    private function hashOf(string $caseId): ?string
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return self::getContainer()->get(CaseIdHasher::class)->hashFrom($caseId);
    }

    private function setVariable(string $name, string $value): void
    {
        $this->originalVariables[$name] ??= ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null];
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }

    /**
     * @param array{server: mixed, env: mixed} $original
     */
    private function restore(string $name, array $original): void
    {
        if (null === $original['server']) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $original['server'];
        }

        if (null === $original['env']) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $original['env'];
        }
    }
}
