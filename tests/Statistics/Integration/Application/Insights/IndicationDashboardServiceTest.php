<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\Insights;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardCriteria;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardService;
use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationDashboardServiceTest extends KernelTestCase
{
    use Factories;

    public function testBuildReturnsNullWhenIndicationIsMissing(): void
    {
        self::bootKernel();

        $result = $this->service()->build(new IndicationDashboardCriteria(
            999_999,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        ));

        self::assertNull($result);
    }

    public function testBuildAssemblesDashboardForASingleIndication(): void
    {
        self::bootKernel();

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Service Indication', 'code' => 7101]);

        $result = $this->service()->build(new IndicationDashboardCriteria(
            (int) $indication->getId(),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        ));

        self::assertNotNull($result);
        self::assertSame((int) $indication->getId(), $result->header->indicationId);
        self::assertSame('indications', $result->header->dimension);
        self::assertSame(7101, $result->header->indicationCode);
        self::assertSame(0, $result->header->caseCount);
    }

    public function testBuildForSubjectUsesIndicationGroupDimension(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'dash-service-group-'.bin2hex(random_bytes(4))]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Grouped Indication', 'code' => 7102]);
        $group = IndicationGroupFactory::createOne(['name' => 'Service Group', 'createdBy' => $user, 'category' => 'Trauma']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $result = $this->service()->buildForSubject(
            new IndicationSubject(
                IndicationSubjectType::Group,
                (int) $group->getId(),
                'Service Group',
                [(int) $indication->getId()],
            ),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        );

        self::assertNotNull($result);
        self::assertSame('indication-groups', $result->header->dimension);
        self::assertNull($result->header->indicationCode);
        self::assertSame('Service Group', $result->header->indicationName);
    }

    public function testBuildForSubjectKeepsIndicationCodeForASingleSubject(): void
    {
        self::bootKernel();

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Single Subject Indication', 'code' => 7103]);

        $result = $this->service()->buildForSubject(
            new IndicationSubject(
                IndicationSubjectType::Single,
                (int) $indication->getId(),
                'Single Subject Indication',
                [(int) $indication->getId()],
            ),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        );

        self::assertNotNull($result);
        self::assertSame('indications', $result->header->dimension);
        self::assertSame(7103, $result->header->indicationCode);
    }

    public function testBuildForInsightReturnsNullWhenPopulationIsEmpty(): void
    {
        self::bootKernel();

        $result = $this->service()->buildForInsight(
            new InsightSubject(
                InsightDimensionKey::IndicationGroups,
                1,
                'Empty Group',
                InsightPopulationFilter::indications([]),
            ),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        );

        self::assertNull($result);
    }

    private function service(): IndicationDashboardService
    {
        return self::getContainer()->get(IndicationDashboardService::class);
    }
}
