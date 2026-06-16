<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HospitalCohortLabelResolverTest extends KernelTestCase
{
    private HospitalCohortLabelResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = self::getContainer()->get(HospitalCohortLabelResolver::class);
    }

    public function testUrbanFullUsesTranslatedLocationAndTier(): void
    {
        $label = $this->resolver->label(
            new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::FULL),
        );

        self::assertSame('Urban Location Full Tier', $label);
        self::assertStringNotContainsString('stats.filter.cohort.', $label);
        self::assertStringNotContainsString('urban_full', $label);
    }

    public function testMixedExtendedUsesTranslatedLocationAndTier(): void
    {
        $label = $this->resolver->label(
            new HospitalCohortKey(HospitalLocation::MIXED, HospitalTier::EXTENDED),
        );

        self::assertSame('Mixed Location Extended Tier', $label);
    }
}
