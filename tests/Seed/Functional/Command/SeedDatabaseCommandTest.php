<?php

namespace App\Tests\Seed\Functional\Command;

use App\Seed\Application\Contracts\SeedProviderInterface;
use App\Seed\UI\Console\Command\SeedDatabaseCommand;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDatabaseCommandTest extends TestCase
{
    public function testPurgeAndSeedSuccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $em->method('getConnection')->willReturn($connection);

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(self::callback(function (string $sql): bool {
                return str_starts_with($sql, 'TRUNCATE')
                    && str_contains($sql, 'RESTART IDENTITY')
                    && str_contains($sql, 'CASCADE');
            }));

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(new User());
        $em->method('getRepository')->willReturn($repo);

        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $providerA = $this->makeProvider(
            purgeTables: ['alpha_items', 'alpha_meta'],
            builtEntityCount: 2
        );

        $providerB = $this->makeProvider(
            purgeTables: [],
            builtEntityCount: 1
        );

        $command = new SeedDatabaseCommand($em, [$providerA, $providerB]);
        $tester = new CommandTester($command);

        // Act
        $status = $tester->execute([
            '--user-id' => '1',
            '--purge' => true,
        ]);

        // Assert
        self::assertSame(0, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Purged tables:', $display);
        self::assertStringContainsString('Seeding finished.', $display);
    }

    public function testFailsWhenUserMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);
        $em->method('getRepository')->willReturn($repo);

        $command = new SeedDatabaseCommand($em, []);
        $tester = new CommandTester($command);

        $status = $tester->execute(['--user-id' => '99']);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('User #99 not found.', $tester->getDisplay());
    }

    public function testInvalidUserIdIsRejected(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $command = new SeedDatabaseCommand($em, []);
        $tester = new CommandTester($command);

        $status = $tester->execute(['--user-id' => 'foo']);
        self::assertSame(2, $status);
        self::assertStringContainsString('--user-id is required and must be a positive integer', $tester->getDisplay());
    }

    /**
     * @param list<string> $purgeTables
     *
     * @return SeedProviderInterface<mixed>
     */
    private function makeProvider(array $purgeTables, int $builtEntityCount): SeedProviderInterface
    {
        return new class($purgeTables, $builtEntityCount) implements SeedProviderInterface {
            /**
             * @param list<string> $purgeTables
             */
            public function __construct(
                private array $purgeTables,
                private int $builtEntityCount,
            ) {
            }

            public function getType(): string
            {
                return 'test-provider';
            }

            /**
             * @return iterable<object>
             */
            public function build(User $user): iterable
            {
                for ($i = 0; $i < $this->builtEntityCount; ++$i) {
                    yield (object) ['i' => $i, 'userId' => $user->getId()];
                }
            }

            /**
             * @return iterable<mixed>
             */
            public function provide(): iterable
            {
                if ($this->builtEntityCount > 0) {
                    yield 'preview-value';
                }
            }

            /**
             * @return list<string>
             */
            public function purgeTables(): array
            {
                return $this->purgeTables;
            }
        };
    }
}
