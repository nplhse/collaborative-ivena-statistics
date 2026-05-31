<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AssessmentAirway;
use App\Allocation\Domain\Enum\AssessmentBreathing;
use App\Allocation\Domain\Enum\AssessmentCirculation;
use App\Allocation\Domain\Enum\AssessmentDisability;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Resolver\AllocationAssessmentResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AllocationAssessmentResolverTest extends TestCase
{
    private AllocationAssessmentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AllocationAssessmentResolver(new NullLogger());
    }

    public function testSupportsReturnsFalseForEmptyStringFields(): void
    {
        $dto = new AllocationRowDTO();
        $dto->assessmentAirway = '';

        self::assertFalse($this->resolver->supports(new Allocation(), $dto));
    }

    public function testApplyDoesNotAttachAssessmentWhenFieldsAreEmptyStrings(): void
    {
        $dto = new AllocationRowDTO();
        $dto->assessmentAirway = '';
        $dto->assessmentBreathing = '';
        $dto->assessmentCirculation = '';
        $dto->assessmentDisability = '';

        $allocation = new Allocation();
        $this->resolver->apply($allocation, $dto);

        self::assertNull($allocation->getAssessment());
    }

    public function testApplyDoesNotAttachAssessmentForAbcdPrefixPlaceholders(): void
    {
        $dto = new AllocationRowDTO();
        $dto->assessmentAirway = 'A-';
        $dto->assessmentBreathing = 'B-';
        $dto->assessmentCirculation = 'C-';
        $dto->assessmentDisability = 'D-';

        $allocation = new Allocation();
        self::assertTrue($this->resolver->supports($allocation, $dto));

        $this->resolver->apply($allocation, $dto);

        self::assertNull($allocation->getAssessment());
    }

    public function testApplyAttachesAssessmentWhenAllFieldsAreValid(): void
    {
        $dto = new AllocationRowDTO();
        $dto->assessmentAirway = 'free';
        $dto->assessmentBreathing = 'spontaneous';
        $dto->assessmentCirculation = 'stable';
        $dto->assessmentDisability = 'awake';

        $allocation = new Allocation();
        $this->resolver->apply($allocation, $dto);

        $assessment = $allocation->getAssessment();
        self::assertNotNull($assessment);
        self::assertSame(AssessmentAirway::FREE, $assessment->getAirway());
        self::assertSame(AssessmentBreathing::SPONTANEOUS, $assessment->getBreathing());
        self::assertSame(AssessmentCirculation::STABLE, $assessment->getCirculation());
        self::assertSame(AssessmentDisability::AWAKE, $assessment->getDisability());
    }
}
