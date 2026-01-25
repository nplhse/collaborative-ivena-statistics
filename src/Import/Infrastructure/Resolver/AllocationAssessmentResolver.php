<?php

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

final class AllocationAssessmentResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return null !== $dto->assessmentAirway
            || null !== $dto->assessmentBreathing
            || null !== $dto->assessmentCirculation
            || null !== $dto->assessmentDisability;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $assessment = new Assessment();
        $assessment->setCreatedAt(new \DateTimeImmutable());

        if (null !== $dto->assessmentAirway) {
            $enum = AssessmentAirway::tryFrom($dto->assessmentAirway);

            if (null === $enum) {
                $this->logEnumFailure('airway', $dto->assessmentAirway, $entity);
            } else {
                $assessment->setAirway($enum);
            }
        }

        if (null !== $dto->assessmentBreathing) {
            $enum = AssessmentBreathing::tryFrom($dto->assessmentBreathing);

            if (null === $enum) {
                $this->logEnumFailure('breathing', $dto->assessmentBreathing, $entity);
            } else {
                $assessment->setBreathing($enum);
            }
        }

        if (null !== $dto->assessmentCirculation) {
            $enum = AssessmentCirculation::tryFrom($dto->assessmentCirculation);

            if (null === $enum) {
                $this->logEnumFailure('circulation', $dto->assessmentCirculation, $entity);
            } else {
                $assessment->setCirculation($enum);
            }
        }

        if (null !== $dto->assessmentDisability) {
            $enum = AssessmentDisability::tryFrom($dto->assessmentDisability);

            if (null === $enum) {
                $this->logEnumFailure('disability', $dto->assessmentDisability, $entity);
            } else {
                $assessment->setDisability($enum);
            }
        }

        if ($assessment->isValid()) {
            $entity->setAssessment($assessment);
        }
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
