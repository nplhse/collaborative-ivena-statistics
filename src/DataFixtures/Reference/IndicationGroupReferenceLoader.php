<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IndicationGroupReferenceLoader
{
    private const int DEV_SUBSET_SEED = 2_026_061_7;

    public function __construct(
        private ReferenceYamlLoader $yaml,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{name: string, category: ?string, codes: list<string>}>
     */
    public function allDefinitions(): array
    {
        return $this->yaml->indicationGroups();
    }

    /**
     * @return list<array{name: string, category: ?string, codes: list<string>}>
     */
    public function pickDeterministicSubset(int $count): array
    {
        $definitions = $this->allDefinitions();
        if ([] === $definitions || $count <= 0) {
            return [];
        }

        $count = min($count, \count($definitions));
        $names = array_map(static fn (array $row): string => $row['name'], $definitions);
        array_multisort($names, SORT_STRING, $definitions);

        mt_srand(self::DEV_SUBSET_SEED);
        shuffle($definitions);
        mt_srand();

        return \array_slice($definitions, 0, $count);
    }

    /**
     * @param list<array{name: string, category: ?string, codes: list<string>}> $definitions
     */
    public function loadGroups(User $user, array $definitions): void
    {
        $this->syncGroups($user, $definitions, updateExisting: false, dryRun: false);
    }

    /**
     * @param list<array{name: string, category: ?string, codes: list<string>}> $definitions
     */
    public function syncGroups(
        User $user,
        array $definitions,
        bool $updateExisting = false,
        bool $dryRun = false,
    ): IndicationGroupSyncResult {
        $groupRepository = $this->entityManager->getRepository(IndicationGroup::class);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($definitions as $definition) {
            $existing = $groupRepository->findOneBy(['name' => $definition['name']]);

            if ($existing instanceof IndicationGroup && !$updateExisting) {
                ++$skipped;
                continue;
            }

            $group = $existing ?? new IndicationGroup()
                ->setName($definition['name'])
                ->setCreatedBy($user);

            if (!$existing instanceof IndicationGroup) {
                ++$created;
            } else {
                ++$updated;
            }

            $group->setCategory($definition['category'] ?? null);

            $desiredIndications = $this->resolveIndications(
                $definition['name'],
                $definition['codes'],
                $warnings,
            );

            foreach ($group->getIndications()->toArray() as $indication) {
                $indicationId = $indication->getId();
                if (null === $indicationId || !isset($desiredIndications[$indicationId])) {
                    $group->removeIndication($indication);
                }
            }

            foreach ($desiredIndications as $indication) {
                $group->addIndication($indication);
            }

            if (!$dryRun) {
                $this->entityManager->persist($group);
            }
        }

        return new IndicationGroupSyncResult($created, $updated, $skipped, $warnings);
    }

    /**
     * @param list<string> $codes
     * @param list<string> $warnings
     *
     * @return array<int, IndicationNormalized>
     */
    private function resolveIndications(
        string $groupName,
        array $codes,
        array &$warnings,
    ): array {
        $indicationRepository = $this->entityManager->getRepository(IndicationNormalized::class);

        /** @var array<int, IndicationNormalized> $desiredIndications */
        $desiredIndications = [];

        foreach ($codes as $code) {
            $indications = $indicationRepository->findBy(['code' => (int) $code]);
            if ([] === $indications) {
                $warnings[] = sprintf('Group "%s": no normalized indication for code %s', $groupName, $code);
                continue;
            }

            foreach ($indications as $indication) {
                $id = $indication->getId();
                if (null !== $id) {
                    $desiredIndications[$id] = $indication;
                }
            }
        }

        return $desiredIndications;
    }
}
