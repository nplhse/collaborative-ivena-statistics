<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportApplier;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlanner;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use App\DataFixtures\UserFixtures;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads the synthetic reference catalog through the same validation and import as a real package (ADR 020).
 */
final class ReferenceCatalogFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly SyntheticReferenceCatalogBuilder $builder,
        private readonly ReferenceCatalogPackageWriter $writer,
        private readonly ReferenceCatalogPackageReader $reader,
        private readonly ReferenceCatalogImportPlanner $planner,
        private readonly ReferenceCatalogImportApplier $applier,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $result = $this->reader->readJson($this->writer->encode($this->builder->build()));
        if (!$result->isValid()) {
            throw new \RuntimeException('The synthetic reference catalog is invalid: '.$this->describe($result->violations));
        }

        $plan = $this->planner->plan($result->package);
        if ($plan->hasConflicts()) {
            throw new \RuntimeException('The synthetic reference catalog conflicts with the database: '.$this->describe($plan->conflicts));
        }

        $this->applier->apply($plan, $this->getReference('admin', User::class));
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['reference', 'dev', 'statistics'];
    }

    /**
     * @param list<ReferenceCatalogViolation> $violations
     */
    private function describe(array $violations): string
    {
        return implode('; ', array_map(
            static fn (ReferenceCatalogViolation $violation): string => $violation->path.': '.$violation->message,
            \array_slice($violations, 0, 5),
        ));
    }
}
