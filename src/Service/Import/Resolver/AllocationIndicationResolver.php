<?php

namespace App\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Entity\IndicationRaw;
use App\Repository\IndicationRawRepository;
use App\Service\Import\Contracts\AllocationEntityResolverInterface;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Indication\IndicationCache;
use App\Service\Import\Indication\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationIndicationResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly IndicationRawRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly IndicationCache $cache,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        foreach ($this->repo->preloadAllLight() as $row) {
            /* @var array{hash:string, id:int, normalized_id:int|null} $row */
            $this->cache->addExisting(
                $row['hash'],
                $row['id'],
                $row['normalized_id']
            );
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
        if (null === $dto->indicationCode || null === $dto->indication) {
            return;
        }

        $hash = IndicationKey::hashFrom((string) $dto->indicationCode, $dto->indication);

        if (!$this->cache->has($hash)) {
            $raw = new IndicationRaw()
                ->setCode($dto->indicationCode)
                ->setName($dto->indication)
                ->setHash($hash)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($raw);
            $this->cache->putNew($hash, $raw);
        }

        $rawRef = $this->cache->getRawRef($this->em, $hash);
        $entity->setIndicationRaw($rawRef);

        if (null === $entity->getIndicationNormalized()) {
            $normRef = $this->cache->getNormalizedRefOrNull($this->em, $hash);

            if (null !== $normRef) {
                $entity->setIndicationNormalized($normRef);
            }
        }
    }
}
