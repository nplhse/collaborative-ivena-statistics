<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\UI\Console\Command\DeleteUserCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:user:delete` with the real service and database. Every answer and refusal (not interactive, invalid or unknown
 * username, last administrator, hospital owner, abort) is in the unit `DeleteUserCommandTest`; a user other records
 * still reference in `DoctrineAdministrativeUserServiceTest::testDeleteThrowsWhenOtherRecordsReferenceTheUser`.
 */
#[ResetDatabase]
final class DeleteUserCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testDeletesUserAfterConfirmation(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);
        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted user "alice"', $tester->getDisplay());
        self::assertNull($this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    private function getUserRepository(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    private function createCommandTester(): CommandTester
    {
        /** @var DeleteUserCommand $command */
        $command = self::getContainer()->get(DeleteUserCommand::class);

        return new CommandTester($command);
    }
}
