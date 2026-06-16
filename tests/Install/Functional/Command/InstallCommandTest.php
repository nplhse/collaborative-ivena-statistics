<?php

declare(strict_types=1);

namespace App\Tests\Install\Functional\Command;

use App\Install\UI\Console\Command\InstallCommand;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class InstallCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testCreatesBootstrapAdminWithExpectedPassword(): void
    {
        $tester = $this->runInstallCommand();
        $tester->assertCommandIsSuccessful();

        $repo = $this->getUserRepository();
        $admin = $repo->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(User::class, $admin);
        self::assertSame('admin@test.local', $admin->getEmail());
        self::assertContains('ROLE_ADMIN', $admin->getRoles());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($admin, 'Ivena123'));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Created bootstrap admin user', $display);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $first = $this->runInstallCommand();
        $first->assertCommandIsSuccessful();

        $repo = $this->getUserRepository();
        self::assertSame(1, $repo->count(['username' => 'admin']));

        $second = $this->runInstallCommand();
        $second->assertCommandIsSuccessful();

        self::assertSame(1, $repo->count(['username' => 'admin']));
        self::assertStringContainsString('already exists', $second->getDisplay());
    }

    private function runInstallCommand(): CommandTester
    {
        /** @var InstallCommand $command */
        $command = self::getContainer()->get(InstallCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function getUserRepository(): UserRepository
    {
        /** @var UserRepository $repo */
        $repo = self::getContainer()->get(UserRepository::class);

        return $repo;
    }
}
