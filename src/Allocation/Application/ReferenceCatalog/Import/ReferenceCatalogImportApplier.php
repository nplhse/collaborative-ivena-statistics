<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\Package\DispatchAreaEntry;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalEntry;
use App\Allocation\Application\ReferenceCatalog\Package\IndicationGroupEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NamedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NormalizedIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\StateEntry;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\Address;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\SpecialityAlias;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Domain\IndicationKey;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the rows of an import plan in one transaction and keeps the package ids (ADR 020).
 *
 * The importing user becomes createdBy and, for reviewed raw indications, reviewedBy.
 * Imported hospitals do not participate and have no owner.
 */
final readonly class ReferenceCatalogImportApplier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.allocation.reference_data')]
        private CacheItemPoolInterface $referenceDataCache,
    ) {
    }

    public function apply(ReferenceCatalogImportPlan $plan, User $user): void
    {
        if ($plan->hasConflicts()) {
            throw new \LogicException('An import plan with conflicts must not be applied.');
        }

        if (0 === $plan->createCount()) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($plan, $user): void {
            $this->write($plan, $user);
        });

        $this->referenceDataCache->clear();
    }

    /**
     * Writes the planned rows without opening a transaction; the caller owns it (see ReferenceCatalogRestorer).
     */
    public function write(ReferenceCatalogImportPlan $plan, User $user): void
    {
        if ($plan->hasConflicts()) {
            throw new \LogicException('An import plan with conflicts must not be applied.');
        }

        $entities = new ImportEntityRegistry($this->entityManager);

        foreach ($plan->rows as $row) {
            $entity = $this->create($row->type, $row->entry, $user, $entities);
            $entity->setPublicId(Uuid::fromString($row->entry->id));
            $this->entityManager->persist($entity);
            $entities->register($row->type, $row->entry->id, $entity);
        }

        foreach ($plan->aliases as $planned) {
            $owner = $entities->get($planned->ownerType, $planned->ownerId);
            $alias = $planned->alias;
            $this->entityManager->persist(match (true) {
                $owner instanceof Department => new DepartmentAlias($owner, $alias->name, $alias->classification, $alias->source, $alias->note, $alias->validFrom, $alias->validTo),
                $owner instanceof Speciality => new SpecialityAlias($owner, $alias->name, $alias->classification, $alias->source, $alias->note, $alias->validFrom, $alias->validTo),
                default => throw new \LogicException(sprintf('Aliases belong to departments or specialities, not to %s.', $planned->ownerType->value)),
            });
        }

        $this->entityManager->flush();
    }

    private function create(ReferenceCatalogType $type, CatalogEntry $entry, User $user, ImportEntityRegistry $entities): State|DispatchArea|Department|Speciality|Assignment|Occasion|Infection|SecondaryTransport|IndicationNormalized|IndicationRaw|IndicationGroup|Hospital
    {
        return match (true) {
            $entry instanceof StateEntry => new State()->setName($entry->name)->setCreatedBy($user),
            $entry instanceof DispatchAreaEntry => new DispatchArea()
                ->setName($entry->name)
                ->setState($entities->state($entry->state))
                ->setCreatedBy($user),
            $entry instanceof AliasedEntry => (ReferenceCatalogType::Department === $type ? new Department() : new Speciality())
                ->setName($entry->name)
                ->setCreatedBy($user),
            $entry instanceof NamedEntry => $this->namedEntity($type)->setName($entry->name)->setCreatedBy($user),
            $entry instanceof NormalizedIndicationEntry => new IndicationNormalized()
                ->setCode((int) $entry->code)
                ->setName($entry->name)
                ->setNote($entry->note)
                ->setCreatedBy($user),
            $entry instanceof RawIndicationEntry => $this->rawIndication($entry, $user, $entities),
            $entry instanceof IndicationGroupEntry => $this->indicationGroup($entry, $user, $entities),
            $entry instanceof HospitalEntry => $this->hospital($entry, $user, $entities),
            default => throw new \LogicException(sprintf('No import for %s.', $entry::class)),
        };
    }

    private function namedEntity(ReferenceCatalogType $type): Assignment|Occasion|Infection|SecondaryTransport
    {
        return match ($type) {
            ReferenceCatalogType::Assignment => new Assignment(),
            ReferenceCatalogType::Occasion => new Occasion(),
            ReferenceCatalogType::Infection => new Infection(),
            ReferenceCatalogType::SecondaryTransport => new SecondaryTransport(),
            default => throw new \LogicException(sprintf('%s is not a plain named type.', $type->value)),
        };
    }

    private function rawIndication(RawIndicationEntry $entry, User $user, ImportEntityRegistry $entities): IndicationRaw
    {
        $status = IndicationRawReviewStatus::from($entry->review->status);
        $target = null === $entry->target ? null : $entities->normalizedIndication($entry->target);

        return new IndicationRaw()
            ->setCode((int) $entry->code)
            ->setName($entry->name)
            ->setHash(IndicationKey::hashFrom($entry->code, $entry->name))
            ->setCreatedBy($user)
            // normalized is a copy of the curated target (ADR 020).
            ->setTarget($target)
            ->setNormalized($target)
            ->setReviewStatus($status)
            ->setReviewComment($entry->review->comment)
            ->setReviewedAt($this->dateTime($entry->review->reviewedAt))
            ->setReviewedBy(IndicationRawReviewStatus::Unreviewed === $status ? null : $user)
            ->setFirstMatchedAt($this->dateTime($entry->firstMatchedAt));
    }

    private function indicationGroup(IndicationGroupEntry $entry, User $user, ImportEntityRegistry $entities): IndicationGroup
    {
        $group = new IndicationGroup()
            ->setName($entry->name)
            ->setCategory($entry->category)
            ->setDescription($entry->description)
            ->setSortOrder($entry->sortOrder)
            ->setCreatedBy($user);

        foreach ($entry->members as $member) {
            $group->addIndication($entities->normalizedIndication($member));
        }

        return $group;
    }

    private function hospital(HospitalEntry $entry, User $user, ImportEntityRegistry $entities): Hospital
    {
        return new Hospital()
            ->setName($entry->name)
            ->setState($entities->state($entry->state))
            ->setDispatchArea($entities->dispatchArea($entry->area))
            ->setTier(null === $entry->tier ? null : HospitalTier::from($entry->tier))
            ->setLocation(HospitalLocation::from($entry->location))
            ->setSize(HospitalSize::from($entry->size))
            ->setBeds($entry->beds)
            ->setAddress(
                new Address()
                    ->setStreet($entry->address->street)
                    ->setPostalCode($entry->address->postalCode)
                    ->setCity($entry->address->city)
                    ->setState($entry->address->state)
                    ->setCountry($entry->address->country),
            )
            ->setLatitude($entry->coordinates?->lat)
            ->setLongitude($entry->coordinates?->lng)
            // Participation and owner belong to an installation, not to the catalog (ADR 020).
            ->setIsParticipating(false)
            ->setOwner(null)
            ->setCreatedBy($user);
    }

    private function dateTime(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }
}
