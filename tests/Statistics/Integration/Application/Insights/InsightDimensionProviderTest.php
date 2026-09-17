<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\Insights;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionProviderInterface;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightNavPlacement;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class InsightDimensionProviderTest extends KernelTestCase
{
    use Factories;

    public function testEveryRegisteredProviderExposesCatalogMetadataAndResolvesNothingForUnknownIds(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(InsightDimensionRegistry::class);

        foreach (InsightDimensionKey::cases() as $key) {
            $provider = $registry->get($key);

            self::assertSame($key, $provider->key());
            self::assertNotSame('', $provider->entityFqcn());
            self::assertStringStartsWith('stats.insights.dimension.', $provider->labelTranslationKey());
            self::assertStringStartsWith('stats.insights.dimension.', $provider->descriptionTranslationKey());
            self::assertStringStartsWith('stats.insights.dimension.', $provider->allValuesLinkTranslationKey());
            self::assertStringStartsWith('tabler:', $provider->icon());
            self::assertContains($provider->navPlacement(), InsightNavPlacement::cases());
            self::assertGreaterThan(0, $provider->navOrder());
            self::assertNull($provider->resolve(999_999_999));
            self::assertSame([], $provider->searchEntities('   ', 5));
            self::assertSame([], $provider->listEntities('no-such-insight-value-xyz'));
        }

        $indications = $registry->get(InsightDimensionKey::Indications);
        self::assertTrue($indications->featuredOnOverview());
        self::assertTrue($indications->hasCode());
        self::assertTrue($indications->supportsCompare());
        self::assertSame([], $indications->disabledInsightIds());
        self::assertNull($indications->nestedUnder());

        $groups = $registry->get(InsightDimensionKey::IndicationGroups);
        self::assertSame(InsightDimensionKey::Indications, $groups->nestedUnder());
        self::assertFalse($groups->featuredOnOverview());
        self::assertFalse($groups->hasCode());

        $infections = $registry->get(InsightDimensionKey::Infections);
        self::assertSame(['infectious'], $infections->disabledInsightIds());

        self::assertSame(
            [
                InsightDimensionKey::Indications,
                InsightDimensionKey::Specialities,
                InsightDimensionKey::Departments,
                InsightDimensionKey::Assignments,
                InsightDimensionKey::Occasions,
                InsightDimensionKey::Infections,
                InsightDimensionKey::SecondaryTransports,
            ],
            array_map(
                static fn (InsightDimensionProviderInterface $provider): InsightDimensionKey => $provider->key(),
                $registry->primaryNav(),
            ),
        );
        self::assertSame(
            [
                InsightDimensionKey::Specialities,
                InsightDimensionKey::Departments,
                InsightDimensionKey::Assignments,
                InsightDimensionKey::Occasions,
                InsightDimensionKey::Infections,
                InsightDimensionKey::SecondaryTransports,
            ],
            array_map(
                static fn (InsightDimensionProviderInterface $provider): InsightDimensionKey => $provider->key(),
                $registry->overviewTeasers(),
            ),
        );
    }

    public function testIndicationProviderResolvesCodesAndSearchesByNumericCode(): void
    {
        self::bootKernel();

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Provider STEMI', 'code' => 8808]);
        $provider = self::getContainer()->get(InsightDimensionRegistry::class)->get(InsightDimensionKey::Indications);

        $subject = $provider->resolve((int) $indication->getId());
        self::assertNotNull($subject);
        self::assertSame((int) $indication->getId(), $subject->id);
        self::assertSame('Provider STEMI (8808)', $subject->label);
        self::assertSame(8808, $subject->code);
        self::assertNotNull($subject->publicId);

        $byName = $provider->searchEntities('Provider STEMI', 5);
        self::assertNotEmpty($byName);
        self::assertSame((int) $indication->getId(), $byName[0]['id']);

        $byCode = $provider->listEntities('8808');
        self::assertNotEmpty($byCode);
        self::assertSame((int) $indication->getId(), $byCode[0]['id']);

        $byCodeSearch = $provider->searchEntities('8808', 5);
        self::assertNotEmpty($byCodeSearch);
        self::assertSame((int) $indication->getId(), $byCodeSearch[0]['id']);
    }

    public function testIndicationGroupProviderResolvesMembersAndCategoryContext(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'provider-group-'.bin2hex(random_bytes(4))]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Group Member Indication']);
        $group = IndicationGroupFactory::createOne([
            'name' => 'Provider Trauma Group',
            'createdBy' => $user,
            'category' => 'Trauma',
        ]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $provider = self::getContainer()->get(InsightDimensionRegistry::class)->get(InsightDimensionKey::IndicationGroups);
        $subject = $provider->resolve((int) $group->getId());

        self::assertNotNull($subject);
        self::assertSame('Provider Trauma Group', $subject->label);
        self::assertSame('Trauma', $subject->contextLabel);
        self::assertSame([(int) $indication->getId()], $subject->population->ids);

        $listed = $provider->listEntities('Trauma Group');
        self::assertNotEmpty($listed);
        self::assertSame('Trauma', $listed[0]['contextLabel']);
    }

    public function testCatalogProvidersResolvePersistedEntities(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'provider-catalog-'.bin2hex(random_bytes(4))]);
        $assignment = AssignmentFactory::createOne(['name' => 'Provider Assignment']);
        $department = DepartmentFactory::createOne(['name' => 'Provider Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Provider Speciality']);
        $occasion = OccasionFactory::createOne(['name' => 'Provider Occasion', 'createdBy' => $user]);
        $infection = InfectionFactory::createOne(['name' => 'Provider MRSA', 'createdBy' => $user]);
        $secondary = SecondaryTransportFactory::createOne(['name' => 'Provider Secondary', 'createdBy' => $user]);
        $registry = self::getContainer()->get(InsightDimensionRegistry::class);

        $cases = [
            [InsightDimensionKey::Assignments, (int) $assignment->getId(), 'Provider Assignment', 'assignment_id'],
            [InsightDimensionKey::Departments, (int) $department->getId(), 'Provider Department', 'department_id'],
            [InsightDimensionKey::Specialities, (int) $speciality->getId(), 'Provider Speciality', 'speciality_id'],
            [InsightDimensionKey::Occasions, (int) $occasion->getId(), 'Provider Occasion', 'occasion_id'],
            [InsightDimensionKey::Infections, (int) $infection->getId(), 'Provider MRSA', 'infection_id'],
            [InsightDimensionKey::SecondaryTransports, (int) $secondary->getId(), 'Provider Secondary', 'secondary_transport_id'],
        ];

        foreach ($cases as [$key, $id, $label, $column]) {
            $subject = $registry->get($key)->resolve($id);
            self::assertNotNull($subject);
            self::assertSame($label, $subject->label);
            self::assertSame($column, $subject->population->column);
            self::assertSame([$id], $subject->population->ids);
        }
    }
}
