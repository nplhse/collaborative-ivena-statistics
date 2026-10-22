<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Notification;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NotificationDemoCommandTest extends KernelTestCase
{
    use Factories;

    public function testCreatesNormalRedactedAndAdminOnlyEntries(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'demo-user', 'roles' => ['ROLE_PARTICIPANT']]);
        HospitalFactory::createOne(['owner' => UserFactory::new(), 'name' => 'Klinikum Demo']);

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'demo-user', '--count' => 4]));

        self::assertSame(
            ['demo.admin_only' => 1, 'demo.hospital' => 1, 'demo.import_finished' => 4],
            $this->countsByType((int) $user->getId()),
        );
        self::assertSame(6, (int) $this->connection()->fetchOne('SELECT unread_count FROM notification_recipient_state WHERE user_id = ?', [$user->getId()]));
        self::assertStringContainsString('shown redacted', $tester->getDisplay());
        self::assertStringContainsString('hidden because the user is not an admin', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'demo-user', '--count' => 1]));
        self::assertSame(9, array_sum($this->countsByType((int) $user->getId())), 'a second run adds new entries');
    }

    public function testClearRemovesExistingEntriesAndAdminsGetNoRedactedEntry(): void
    {
        self::bootKernel();
        $admin = UserFactory::createOne(['username' => 'demo-admin', 'email' => 'demo-admin@example.org', 'roles' => ['ROLE_ADMIN']]);
        HospitalFactory::createOne(['owner' => UserFactory::new()]);

        $tester = $this->tester();
        $tester->execute(['user' => 'demo-admin', '--count' => 5]);
        self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'DEMO-ADMIN@example.org', '--count' => 0, '--clear' => true]));

        self::assertSame(['demo.admin_only' => 1], $this->countsByType((int) $admin->getId()));
        self::assertStringContainsString('Removed existing notifications.', $tester->getDisplay());
        self::assertStringContainsString('the user can access every hospital', $tester->getDisplay());
        self::assertStringContainsString('visible because the user is an admin', $tester->getDisplay());
    }

    public function testRejectsUnknownUserAndNegativeCount(): void
    {
        self::bootKernel();
        UserFactory::createOne(['username' => 'demo-user']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['user' => 'nobody']));
        self::assertStringContainsString('No user found for "nobody"', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['user' => 'demo-user', '--count' => -1]));
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:notification:demo'));
    }

    /**
     * @return array<string, int>
     */
    private function countsByType(int $userId): array
    {
        $counts = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT type, COUNT(*) AS n FROM notification WHERE recipient_id = ? GROUP BY type ORDER BY type', [$userId]) as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
