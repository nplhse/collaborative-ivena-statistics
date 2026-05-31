<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assessment;
use App\Allocation\Domain\Enum\AssessmentAirway;
use App\Allocation\Domain\Enum\AssessmentBreathing;
use App\Allocation\Domain\Enum\AssessmentCirculation;
use App\Allocation\Domain\Enum\AssessmentDisability;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use Psr\Log\LoggerInterface;

final readonly class AllocationAssessmentResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return $this->hasAnyAssessmentData($dto);
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $airway = $this->mapAirway($dto, $entity);
        $breathing = $this->mapBreathing($dto, $entity);
        $circulation = $this->mapCirculation($dto, $entity);
        $disability = $this->mapDisability($dto, $entity);

        if (in_array(null, [$airway, $breathing, $circulation, $disability], true)) {
            return;
        }

        $assessment = new Assessment();
        $assessment->setCreatedAt(new \DateTimeImmutable());
        $assessment->setAirway($airway);
        $assessment->setBreathing($breathing);
        $assessment->setCirculation($circulation);
        $assessment->setDisability($disability);

        $entity->setAssessment($assessment);
    }

    private function hasAnyAssessmentData(AllocationRowDTO $dto): bool
    {
        return $this->isNonEmptyAssessmentField($dto->assessmentAirway)
            || $this->isNonEmptyAssessmentField($dto->assessmentBreathing)
            || $this->isNonEmptyAssessmentField($dto->assessmentCirculation)
            || $this->isNonEmptyAssessmentField($dto->assessmentDisability);
    }

    private function isNonEmptyAssessmentField(?string $value): bool
    {
        return null !== $value && '' !== trim($value);
    }

    private function nonEmptyAssessmentField(?string $value): ?string
    {
        if (!$this->isNonEmptyAssessmentField($value)) {
            return null;
        }

        return $value;
    }

    private function mapAirway(AllocationRowDTO $dto, Allocation $entity): ?AssessmentAirway
    {
        $raw = $this->nonEmptyAssessmentField($dto->assessmentAirway);
        if (null === $raw) {
            return null;
        }

        $enum = AssessmentAirway::tryFrom($raw);
        if (null === $enum) {
            $this->logEnumFailure('airway', $raw, $entity);
        }

        return $enum;
    }

    private function mapBreathing(AllocationRowDTO $dto, Allocation $entity): ?AssessmentBreathing
    {
        $raw = $this->nonEmptyAssessmentField($dto->assessmentBreathing);
        if (null === $raw) {
            return null;
        }

        $enum = AssessmentBreathing::tryFrom($raw);
        if (null === $enum) {
            $this->logEnumFailure('breathing', $raw, $entity);
        }

        return $enum;
    }

    private function mapCirculation(AllocationRowDTO $dto, Allocation $entity): ?AssessmentCirculation
    {
        $raw = $this->nonEmptyAssessmentField($dto->assessmentCirculation);
        if (null === $raw) {
            return null;
        }

        $enum = AssessmentCirculation::tryFrom($raw);
        if (null === $enum) {
            $this->logEnumFailure('circulation', $raw, $entity);
        }

        return $enum;
    }

    private function mapDisability(AllocationRowDTO $dto, Allocation $entity): ?AssessmentDisability
    {
        $raw = $this->nonEmptyAssessmentField($dto->assessmentDisability);
        if (null === $raw) {
            return null;
        }

        $enum = AssessmentDisability::tryFrom($raw);
        if (null === $enum) {
            $this->logEnumFailure('disability', $raw, $entity);
        }

        return $enum;
    }

    private function logEnumFailure(string $field, string $rawValue, Allocation $entity): void
    {
        $import = $entity->getImport();
        $importId = $import?->getId();

        $this->logger->warning('Failed to map assessment value to enum.', [
            'field' => $field,
            'raw_value' => $rawValue,
            'allocation_id' => $entity->getId(),
            'import_id' => $importId,
        ]);
    }
}
