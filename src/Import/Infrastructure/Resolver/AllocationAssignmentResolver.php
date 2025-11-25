<?php

namespace App\Import\Infrastructure\Resolver;

use App\Entity\Allocation;
use App\Entity\Assignment;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Repository\AssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationAssignmentResolver implements AllocationEntityResolverInterface
{
    /** @var array<string,int> */
    private array $assignmentIdByKey = [];

    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        foreach ($this->assignmentRepository->findBy([], ['name' => 'ASC']) as $assignment) {
            $assignmentId = $assignment->getId();

            if (null === $assignmentId) {
                throw new \DomainException(sprintf('Assignment "%s" is invalid: id is null.', (string) $assignment->getName()));
            }

            $key = self::key((string) $assignment->getName());

            $this->assignmentIdByKey[$key] = $assignmentId;
        }
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $key = self::key((string) $dto->assignment);

        $assignmentId = $this->assignmentIdByKey[$key] ?? null;
        if (null === $assignmentId) {
            throw ReferenceNotFoundException::forField('assignment', $dto->assignment);
        }

        /** @var Assignment $assignmentRef */
        $assignmentRef = $this->em->getReference(Assignment::class, $assignmentId);
        $entity->setAssignment($assignmentRef);
    }

    private static function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
