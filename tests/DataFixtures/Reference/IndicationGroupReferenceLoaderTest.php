<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\DataFixtures\Reference\IndicationGroupReferenceLoader;
use App\Tests\DataFixtures\Reference\Support\CreatesReferenceYamlLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndicationGroupReferenceLoaderTest extends TestCase
{
    use CreatesReferenceYamlLoader;

    #[Test]
    public function pickDeterministicSubsetReturnsFiveStableGroups(): void
    {
        $loader = new IndicationGroupReferenceLoader(
            $this->referenceYamlLoader(),
            $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $subset = $loader->pickDeterministicSubset(5);

        self::assertCount(5, $subset);
        self::assertSame(
            [
                'Brustschmerz & akutes Koronarsyndrom',
                'Sepsis & septischer Schock',
                'Akute Dyspnoe & respiratorische Notfälle',
                'Epilepsie & Krampf',
                'Verbrennung & Umweltmedizin',
            ],
            array_map(static fn (array $row): string => $row['name'], $subset),
        );
    }

    #[Test]
    public function syncGroupsSkipsExistingGroupsByDefault(): void
    {
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $groupRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $indicationRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);

        $existing = new \App\Allocation\Domain\Entity\IndicationGroup()->setName('Brustschmerz & akutes Koronarsyndrom');

        $entityManager->method('getRepository')->willReturnMap([
            [\App\Allocation\Domain\Entity\IndicationGroup::class, $groupRepository],
            [\App\Allocation\Domain\Entity\IndicationNormalized::class, $indicationRepository],
        ]);

        $groupRepository->method('findOneBy')->willReturn($existing);
        $indicationRepository->method('findBy')->willReturn([]);

        $loader = new IndicationGroupReferenceLoader($this->referenceYamlLoader(), $entityManager);
        $user = $this->createStub(\App\User\Domain\Entity\User::class);

        $result = $loader->syncGroups($user, [
            ['name' => 'Brustschmerz & akutes Koronarsyndrom', 'category' => 'Kardiologie', 'codes' => ['331']],
        ]);

        self::assertSame(0, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->skipped);
    }

    #[Test]
    public function indicationGroupsYamlDefinesTwentyGroupsWithoutAnaphylaxis(): void
    {
        $groups = $this->referenceYamlLoader()->indicationGroups();

        self::assertCount(20, $groups);
        self::assertFalse(
            array_any($groups, static fn (array $row): bool => str_contains((string) $row['name'], 'Anaphylax')),
        );

        $names = array_map(static fn (array $row): string => $row['name'], $groups);
        self::assertSame($names, array_values(array_unique($names)));
    }
}
