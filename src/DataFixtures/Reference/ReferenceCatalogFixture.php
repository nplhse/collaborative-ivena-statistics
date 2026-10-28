<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportApplier;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlanner;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use App\DataFixtures\UserFixtures;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads the synthetic reference catalog through the same validation and import as a real package (ADR 020).
 *
 * With `FIXTURES_REFERENCE_PACKAGE` the catalog of that package replaces the synthetic one, except
 * states, dispatch areas and hospitals, which stay synthetic (`ComposedReferenceCatalog`).
 */
final class ReferenceCatalogFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly SyntheticReferenceCatalogBuilder $builder,
        private readonly ReferenceCatalogPackageWriter $writer,
        private readonly ReferenceCatalogPackageReader $reader,
        private readonly ReferenceCatalogImportPlanner $planner,
        private readonly ReferenceCatalogImportApplier $applier,
        private readonly ComposedReferenceCatalog $composer,
        #[Autowire(param: 'fixtures.reference_package')]
        private readonly string $referencePackage = '',
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir = '',
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $result = $this->reader->readJson($this->writer->encode($this->catalog()));
        if (!$result->isValid()) {
            throw new \RuntimeException('The reference catalog to load is invalid: '.$this->describe($result->violations));
        }

        $plan = $this->planner->plan($result->package);
        if ($plan->hasConflicts()) {
            throw new \RuntimeException('The reference catalog to load conflicts with the database: '.$this->describe($plan->conflicts));
        }

        $this->applier->apply($plan, $this->getReference('admin', User::class));
    }

    private function catalog(): ReferenceCatalogPackage
    {
        $synthetic = $this->builder->build();
        $path = trim($this->referencePackage);
        if ('' === $path) {
            return $synthetic;
        }
        if (!str_starts_with($path, '/')) {
            $path = rtrim($this->projectDir, '/').'/'.$path;
        }

        $result = $this->reader->read($path);
        if (!$result->isValid()) {
            throw new \RuntimeException(sprintf('The reference package "%s" is invalid: %s', $path, $this->describe($result->violations)));
        }

        return $this->composer->compose($synthetic, $result->package);
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
