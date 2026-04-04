<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

final class DistributionPageConfigResolverTest extends TestCase
{
    public function testResolveReturnsPageConfigForValidOptions(): void
    {
        $config = new DistributionPageConfigResolver()->resolve(DistributionPanelFixtures::sampleUrgencyPageOptions());

        self::assertSame('app_stats_distribution_urgency', $config->routeName);
        self::assertCount(1, $config->panels);
        self::assertSame('urgency', $config->panels[0]->key);
    }

    public function testResolveRejectsEmptyRouteName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('route_name must be non-empty.');

        new DistributionPageConfigResolver()->resolve([
            'route_name' => '   ',
            'section_key' => 'urgency',
            'panels' => [DistributionPanelFixtures::urgencyPanelOptions()],
        ]);
    }

    public function testResolveRejectsEmptyPanelsList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('panels must contain at least one panel.');

        new DistributionPageConfigResolver()->resolve([
            'route_name' => 'app_stats_distribution_urgency',
            'section_key' => 'urgency',
            'panels' => [],
        ]);
    }

    public function testResolveRejectsEmptySectionKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('section_key must be non-empty.');

        new DistributionPageConfigResolver()->resolve([
            'route_name' => 'app_stats_distribution_urgency',
            'section_key' => '',
            'panels' => [DistributionPanelFixtures::urgencyPanelOptions()],
        ]);
    }

    public function testCreatePanelDefinitionRejectsUnknownDimensionKind(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $opts = DistributionPanelFixtures::urgencyPanelOptions();
        $opts['dimension_kind'] = 'not_a_kind';

        new DistributionPageConfigResolver()->createPanelDefinition($opts);
    }

    public function testCreatePanelDefinitionRejectsUnknownAverageMetric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown average_metric "weight".');

        $opts = DistributionPanelFixtures::urgencyPanelOptions();
        $opts['average_metric'] = 'weight';

        new DistributionPageConfigResolver()->createPanelDefinition($opts);
    }

    public function testResolvePropagatesOptionsResolverExceptionForMissingPanelKey(): void
    {
        $this->expectException(ExceptionInterface::class);

        $opts = DistributionPanelFixtures::urgencyPanelOptions();
        unset($opts['key']);

        new DistributionPageConfigResolver()->resolve([
            'route_name' => 'app_stats_distribution_urgency',
            'section_key' => 'urgency',
            'panels' => [$opts],
        ]);
    }
}
