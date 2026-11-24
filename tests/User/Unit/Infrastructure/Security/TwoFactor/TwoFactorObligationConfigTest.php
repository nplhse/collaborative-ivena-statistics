<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The obligation for administrators is on by default, so production is safe without extra configuration, and off in
 * dev, demo and test (#1567, ADR 040). The test environment cannot boot prod, so it reads the configuration file.
 */
final class TwoFactorObligationConfigTest extends TestCase
{
    public function testTheObligationIsOnByDefaultAndOffInDevDemoAndTest(): void
    {
        /** @var array<string, mixed> $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 6).'/config/packages/app.yaml');

        self::assertTrue($this->requiredForAdmins($config['app'] ?? null), 'base value, used in prod');
        foreach (['dev', 'demo', 'test'] as $environment) {
            $block = $config['when@'.$environment] ?? null;
            self::assertIsArray($block, 'when@'.$environment);
            self::assertFalse($this->requiredForAdmins($block['app'] ?? null), $environment);
        }
        self::assertArrayNotHasKey('when@prod', $config, 'prod must use the base value');
    }

    private function requiredForAdmins(mixed $app): mixed
    {
        self::assertIsArray($app);
        self::assertIsArray($app['security'] ?? null);
        self::assertIsArray($app['security']['two_factor'] ?? null);

        return $app['security']['two_factor']['required_for_admins'] ?? null;
    }
}
