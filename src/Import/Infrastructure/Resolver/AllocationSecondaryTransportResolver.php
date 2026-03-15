<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Infrastructure\Repository\SecondaryTransportRepository;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationSecondaryTransportResolver implements AllocationEntityResolverInterface
{
    /** @var array<string,int> */
    private array $secondaryTransportIdByKey = [];

    public function __construct(
        private readonly SecondaryTransportRepository $secondaryTransportRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        foreach ($this->secondaryTransportRepository->findBy([], ['name' => 'ASC']) as $secondaryTransport) {
            $id = $secondaryTransport->getId();

            if (null === $id) {
                throw new \DomainException(sprintf('SecondaryTransport "%s" is invalid: id is null.', (string) $secondaryTransport->getName()));
            }

            $key = self::key((string) $secondaryTransport->getName());

            $this->secondaryTransportIdByKey[$key] = $id;
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
        $raw = $dto->secondaryTransport;
        if (null === $raw || '' === trim($raw)) {
            return;
        }

        $key = self::key($raw);

        $secondaryTransportId = $this->secondaryTransportIdByKey[$key] ?? null;
        if (null === $secondaryTransportId) {
            return;
        }

        /** @var SecondaryTransport $ref */
        $ref = $this->em->getReference(SecondaryTransport::class, $secondaryTransportId);
        $entity->setSecondaryTransport($ref);
    }

    private static function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
