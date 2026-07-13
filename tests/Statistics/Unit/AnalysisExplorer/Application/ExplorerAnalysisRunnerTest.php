<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisRunner;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExplorerAnalysisRunnerTest extends KernelTestCase
{
    use Factories;
    use SeedsExplorerSystemViewsTrait;

    private ExplorerAnalysisRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->runner = self::getContainer()->get(ExplorerAnalysisRunner::class);
    }

    public function testRunWithEmptyConfigReturnsNoConfigOutcome(): void
    {
        $outcome = $this->runner->run([], null);

        self::assertNull($outcome->result);
        self::assertSame('no_config', $outcome->emptyReason);
        self::assertNull($outcome->configWarning);
    }

    public function testRunExecutesSavedSystemViewConfig(): void
    {
        $this->seedExplorerSystemViews();
        $loader = self::getContainer()->get(SavedExplorerViewLoader::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $loaded = $loader->load('gender-over-time', $filter, null);
        UserFactory::createOne();

        $outcome = $this->runner->run($loaded->state, null);

        self::assertNotNull($outcome->result);
        self::assertContains($outcome->emptyReason, [null, 'no_data']);
    }

    public function testRunPreservesExistingConfigWarningWhenNoNewWarning(): void
    {
        $this->seedExplorerSystemViews();
        $loader = self::getContainer()->get(SavedExplorerViewLoader::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $loaded = $loader->load('gender-over-time', $filter, null);
        UserFactory::createOne();

        $outcome = $this->runner->run($loaded->state, null, 'existing-warning');

        self::assertSame('existing-warning', $outcome->configWarning);
    }
}
