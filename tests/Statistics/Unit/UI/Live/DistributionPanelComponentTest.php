<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Live;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Mapping\AgeCohortValueMapper;
use App\Statistics\Application\Mapping\GenderValueMapper;
use App\Statistics\Application\Mapping\HospitalLocationValueMapper;
use App\Statistics\Application\Mapping\HospitalTypeValueMapper;
use App\Statistics\Application\Mapping\TriageValueMapper;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Statistics\Application\Panel\Distribution\DistributionSectionNavProvider;
use App\Statistics\Application\Panel\Distribution\DistributionTransformer;
use App\Statistics\Application\Panel\Distribution\Renderer;
use App\Statistics\Application\State\QueryStateResolver;
use App\Statistics\Infrastructure\Query\DistributionPanelQuery;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use App\Statistics\UI\Live\DistributionPanelComponent;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DistributionPanelComponentTest extends TestCase
{
    public function testAllowedViewModesDependOnGrouping(): void
    {
        $component = $this->component();

        $component->groupedBy = 'none';
        self::assertSame(['absolute', 'percent_of_total'], $component->getAllowedViewModes());

        $component->groupedBy = 'tier';
        self::assertSame(['grouped', 'stacked', 'percent'], $component->getAllowedViewModes());
    }

    public function testPageConfigRequiresDistributionPageOptions(): void
    {
        $component = $this->bareComponent();
        $component->distributionPageOptions = [];

        $this->expectException(\LogicException::class);
        $component->pageConfig();
    }

    public function testPageConfigResolvesFromDistributionPageOptions(): void
    {
        $component = $this->bareComponent();
        $component->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();

        self::assertSame('app_stats_distribution_urgency', $component->pageConfig()->routeName);
    }

    public function testUrlStateUsesNormalizedViewMode(): void
    {
        $component = $this->component();
        $component->groupedBy = 'none';
        $component->viewMode = 'stacked';
        $component->panelKey = 'urgency';
        $component->filterValues = [
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ];

        $state = $component->getUrlState();

        self::assertSame('absolute', $state['view']);
        self::assertSame('urgency', $state['panel']);
        self::assertSame('none', $state['grouped_by']);
        self::assertSame('all_cases', $state['f']['date_range']);
    }

    private function component(): DistributionPanelComponent
    {
        $c = $this->bareComponent();
        $c->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();

        return $c;
    }

    private function bareComponent(): DistributionPanelComponent
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $filterRegistry = new FilterRegistry();
        $resolver = new QueryStateResolver($filterRegistry);
        $query = new DistributionPanelQuery(
            $this->createMock(Connection::class),
            new SqlFilterBuilder($filterRegistry),
        );

        $stack = new RequestStack();
        $stack->push(Request::create('/statistics/distribution/urgency'));

        return new DistributionPanelComponent(
            new DistributionSectionNavProvider(),
            new DistributionPageConfigResolver(),
            $resolver,
            $query,
            new DistributionTransformer(),
            new Renderer(),
            new TriageValueMapper($translator),
            new GenderValueMapper($translator),
            new HospitalTypeValueMapper($translator),
            new HospitalLocationValueMapper($translator),
            new AgeCohortValueMapper($translator),
            $filterRegistry,
            $stack,
        );
    }
}
