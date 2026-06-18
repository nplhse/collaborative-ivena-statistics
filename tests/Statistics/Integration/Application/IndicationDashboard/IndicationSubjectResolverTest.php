<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\IndicationDashboard;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationSubjectResolverTest extends KernelTestCase
{
    use Factories;

    public function testResolveSingleReturnsIndicationSubject(): void
    {
        self::bootKernel();

        $indication = IndicationNormalizedFactory::createOne(['name' => 'STEMI', 'code' => 1001]);
        $subject = self::getContainer()->get(IndicationSubjectResolver::class)->resolveSingle($indication->getId());

        self::assertNotNull($subject);
        self::assertSame(IndicationSubjectType::Single, $subject->type);
        self::assertSame($indication->getId(), $subject->id);
        self::assertSame('STEMI (1001)', $subject->label);
        self::assertSame([$indication->getId()], $subject->indicationIds);
    }

    public function testResolveSingleReturnsNullForUnknownIndication(): void
    {
        self::bootKernel();

        self::assertNull(self::getContainer()->get(IndicationSubjectResolver::class)->resolveSingle(999_999));
    }

    public function testResolveGroupReturnsGroupSubject(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'subject-group-'.bin2hex(random_bytes(4))]);
        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Member A', 'code' => 5001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Member B', 'code' => 5002]);
        $group = IndicationGroupFactory::createOne(['name' => 'Cardiology Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indicationA);
        $groupEntity->addIndication($indicationB);
        $entityManager->flush();

        $subject = self::getContainer()->get(IndicationSubjectResolver::class)->resolveGroup($group->getId());

        self::assertNotNull($subject);
        self::assertSame(IndicationSubjectType::Group, $subject->type);
        self::assertSame($group->getId(), $subject->id);
        self::assertSame('Cardiology Group', $subject->label);
        self::assertEqualsCanonicalizing(
            [$indicationA->getId(), $indicationB->getId()],
            $subject->indicationIds,
        );
    }

    public function testResolveGroupReturnsNullForUnknownGroup(): void
    {
        self::bootKernel();

        self::assertNull(self::getContainer()->get(IndicationSubjectResolver::class)->resolveGroup(999_999));
    }

    public function testResolveDispatchesByType(): void
    {
        self::bootKernel();

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Dispatch Test', 'code' => 6001]);
        $resolver = self::getContainer()->get(IndicationSubjectResolver::class);

        $single = $resolver->resolve(IndicationSubjectType::Single, $indication->getId());
        self::assertNotNull($single);
        self::assertSame(IndicationSubjectType::Single, $single->type);

        self::assertNull($resolver->resolve(IndicationSubjectType::Group, 999_999));
    }
}
