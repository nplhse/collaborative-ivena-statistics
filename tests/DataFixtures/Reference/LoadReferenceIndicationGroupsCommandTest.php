<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\DataFixtures\Reference\Command\LoadReferenceIndicationGroupsCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class LoadReferenceIndicationGroupsCommandTest extends KernelTestCase
{
    use Factories;

    public function testDryRunDoesNotPersistGroups(): void
    {
        self::bootKernel();
        $this->seedPrerequisites();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Dry run finished', $tester->getDisplay());
        self::assertSame(0, $this->groupCount());
    }

    public function testLoadsAllGroupsAndSkipsExistingOnSecondRun(): void
    {
        self::bootKernel();
        $this->seedPrerequisites();

        $tester = $this->createCommandTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Indication groups loaded.', $tester->getDisplay());
        self::assertSame(20, $this->groupCount());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
        self::assertSame(20, $this->groupCount());
    }

    public function testLimitOptionLoadsSubset(): void
    {
        self::bootKernel();
        $this->seedPrerequisites();

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => 5]));

        self::assertSame(5, $this->groupCount());
    }

    public function testUpdateRefreshesMembership(): void
    {
        self::bootKernel();
        $this->seedPrerequisites();

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(IndicationGroup::class)->findOneBy([
            'name' => 'Brustschmerz & akutes Koronarsyndrom',
        ]);
        self::assertNotNull($group);
        self::assertCount(3, $group->getIndications());

        foreach ($group->getIndications()->toArray() as $indication) {
            $group->removeIndication($indication);
        }
        $entityManager->flush();
        self::assertCount(0, $group->getIndications());

        self::assertSame(Command::SUCCESS, $tester->execute(['--update' => true]));

        $entityManager->clear();
        $group = $entityManager->getRepository(IndicationGroup::class)->findOneBy([
            'name' => 'Brustschmerz & akutes Koronarsyndrom',
        ]);
        self::assertNotNull($group);
        self::assertCount(3, $group->getIndications());
    }

    private function createCommandTester(): CommandTester
    {
        $command = self::getContainer()->get(LoadReferenceIndicationGroupsCommand::class);

        return new CommandTester($command);
    }

    private function seedPrerequisites(): void
    {
        UserFactory::new()->asAdmin()->create(['username' => 'admin', 'email' => 'admin@test.local']);

        foreach (['331', '332', '333'] as $code) {
            IndicationNormalizedFactory::createOne([
                'code' => (int) $code,
                'name' => 'Test indication '.$code,
            ]);
        }
    }

    private function groupCount(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM indication_group');
    }
}
