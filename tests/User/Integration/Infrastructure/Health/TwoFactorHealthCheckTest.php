<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Health;

use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Health\TwoFactorHealthCheck;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TwoFactorHealthCheckTest extends KernelTestCase
{
    use Factories;

    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testWithoutKeyAndWithoutUsersTheFeatureIsSimplyOff(): void
    {
        $result = $this->checkWithKey('');

        self::assertSame(HealthCheckStatus::Healthy, $result->status);
        self::assertSame('disabled', $result->value);
        self::assertFalse($result->public);
    }

    public function testMissingKeyWithTwoFactorUsersIsDegraded(): void
    {
        $this->createTwoFactorUser('one');
        $this->createTwoFactorUser('two');

        $result = $this->checkWithKey('');

        self::assertSame(HealthCheckStatus::Degraded, $result->status);
        self::assertSame('missing_key users=2', $result->value);
    }

    public function testKeyThatDoesNotDecryptTheSecretsIsDegraded(): void
    {
        $this->createTwoFactorUser('one');

        $result = $this->checkWithKey(str_repeat('ab', 32));

        self::assertSame(HealthCheckStatus::Degraded, $result->status);
        self::assertSame('key_mismatch', $result->value);
    }

    public function testMatchingKeyIsOk(): void
    {
        $this->createTwoFactorUser('one');

        $result = $this->checkWithKey(self::KEY);

        self::assertSame(HealthCheckStatus::Healthy, $result->status);
        self::assertSame('ok', $result->value);
    }

    private function checkWithKey(string $key): HealthCheckResult
    {
        return new TwoFactorHealthCheck(self::getContainer()->get(Connection::class), new TwoFactorSecretCipher($key))->check();
    }

    private function createTwoFactorUser(string $username): void
    {
        $user = UserFactory::new(['username' => $username, 'email' => $username.'@example.test'])->create();
        $user->enableTotpAuthentication(
            new TwoFactorSecretCipher(self::KEY)->encrypt('JBSWY3DPEHPK3PXP'),
            'JBSWY3DPEHPK3PXP',
            [],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
