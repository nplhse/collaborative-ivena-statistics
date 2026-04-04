<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\AgeCohortValueMapper;
use App\Statistics\Application\Mapping\AssignmentDistributionNameMapper;
use App\Statistics\Application\Mapping\DistributionDimensionValueMapperResolver;
use App\Statistics\Application\Mapping\GenderValueMapper;
use App\Statistics\Application\Mapping\HourOfDayValueMapper;
use App\Statistics\Application\Mapping\OccasionDistributionNameMapper;
use App\Statistics\Application\Mapping\TransportTimeBucketValueMapper;
use App\Statistics\Application\Mapping\TriageValueMapper;
use App\Statistics\Application\Mapping\TriStateBoolValueMapper;
use App\Statistics\Application\Mapping\WeekdayValueMapper;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DistributionDimensionValueMapperResolverTest extends TestCase
{
    private DistributionDimensionValueMapperResolver $resolver;

    private TriageValueMapper $triageMapper;

    private GenderValueMapper $genderMapper;

    private AgeCohortValueMapper $ageCohortMapper;

    private WeekdayValueMapper $weekdayMapper;

    private HourOfDayValueMapper $hourMapper;

    private TransportTimeBucketValueMapper $transportBucketMapper;

    private AssignmentDistributionNameMapper $assignmentMapper;

    private OccasionDistributionNameMapper $occasionMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $translator = $this->createMock(TranslatorInterface::class);
        $connection = $this->createMock(Connection::class);

        $this->triageMapper = new TriageValueMapper($translator);
        $this->genderMapper = new GenderValueMapper($translator);
        $this->ageCohortMapper = new AgeCohortValueMapper($translator);
        $this->weekdayMapper = new WeekdayValueMapper($translator);
        $this->hourMapper = new HourOfDayValueMapper($translator);
        $this->transportBucketMapper = new TransportTimeBucketValueMapper($translator);
        $this->assignmentMapper = new AssignmentDistributionNameMapper($connection, $translator);
        $this->occasionMapper = new OccasionDistributionNameMapper($connection, $translator);

        $this->resolver = new DistributionDimensionValueMapperResolver(
            $translator,
            $this->triageMapper,
            $this->genderMapper,
            $this->ageCohortMapper,
            $this->weekdayMapper,
            $this->hourMapper,
            $this->transportBucketMapper,
            $this->assignmentMapper,
            $this->occasionMapper,
        );
    }

    public function testForPanelReturnsInjectedMappersByIdentity(): void
    {
        $r = new DistributionPageConfigResolver();

        self::assertSame($this->triageMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::urgencyPanelOptions())));
        self::assertSame($this->genderMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::genderPanelOptions())));
        self::assertSame($this->ageCohortMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::ageCohortPanelOptions())));
        self::assertSame($this->weekdayMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::weekdayPanelOptions())));
        self::assertSame($this->hourMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::createdHourPanelOptions())));
        self::assertSame($this->transportBucketMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::transportTimeBucketPanelOptions())));
        self::assertSame($this->assignmentMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::assignmentPanelOptions())));
        self::assertSame($this->occasionMapper, $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::occasionPanelOptions())));
    }

    public function testTriStatePanelsYieldDistinctMapperInstancesPerPrefix(): void
    {
        $r = new DistributionPageConfigResolver();

        $resus = $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::requiresResusPanelOptions()));
        $cathlab = $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::requiresCathlabPanelOptions()));

        self::assertInstanceOf(TriStateBoolValueMapper::class, $resus);
        self::assertInstanceOf(TriStateBoolValueMapper::class, $cathlab);
        self::assertNotSame($resus, $cathlab);
    }

    public function testTriStateMapperReusesInstanceForSamePanelKey(): void
    {
        $r = new DistributionPageConfigResolver();
        $opts = DistributionPanelFixtures::requiresResusPanelOptions();

        $a = $this->resolver->forPanel($r->createPanelDefinition($opts));
        $b = $this->resolver->forPanel($r->createPanelDefinition($opts));

        self::assertSame($a, $b);
    }

    public function testIsCprAndIsVentilatedAreDistinctTriStateMappers(): void
    {
        $r = new DistributionPageConfigResolver();

        $cpr = $this->resolver->forPanel($r->createPanelDefinition(DistributionPanelFixtures::isCprPanelOptions()));
        $vent = $this->resolver->forPanel($r->createPanelDefinition(array_replace(DistributionPanelFixtures::urgencyPanelOptions(), [
            'key' => 'is_ventilated',
            'dimension_field' => '(CASE WHEN is_ventilated IS NULL THEN 0 WHEN is_ventilated = false THEN 1 ELSE 2 END)',
            'dimension_label' => 'statistics.distribution.dim.is_ventilated',
        ])));

        self::assertNotSame($cpr, $vent);
    }
}
