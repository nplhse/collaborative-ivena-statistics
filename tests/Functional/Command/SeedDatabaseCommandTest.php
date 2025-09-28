<?php

namespace App\Tests\Functional\Command;

use App\Command\SeedDatabaseCommand;
use App\Entity\User;
use App\Service\Seed\SeedProviderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDatabaseCommandTest extends TestCase
{
    /**
     * @param non-empty-string $type
     * @param list<string>     $values
     *
     * @return SeedProviderInterface<string>
     */
    private function provider(string $type, array $values): SeedProviderInterface
    {
        return new class($type, $values) implements SeedProviderInterface {
            /**
             * @param non-empty-string $type
             * @param list<string>     $values
             */
            public function __construct(
                private readonly string $type,
                private readonly array $values,
            ) {
            }

            /** @return non-empty-string */
            public function getType(): string
            {
                return $this->type;
            }

            /** @return iterable<string> */
            public function provide(): iterable
            {
                foreach ($this->values as $value) {
                    yield $value;
                }
            }
        };
    }

    public function testMissingUserIdIsInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $cmd = new SeedDatabaseCommand($em, [$this->provider('department', ['A'])]);

        $tester = new CommandTester($cmd);
        $status = $tester->execute([]);

        self::assertSame(2 /* Command::INVALID */, $status);
        self::assertStringContainsString('--user-id is required', $tester->getDisplay());
    }

    public function testDryRunPrintsValuesAndDoesNotWrite(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $cmd = new SeedDatabaseCommand($em, [
            $this->provider('speciality', ['Cardio', 'Oncology']),
            $this->provider('department', ['HR']),
        ]);

        $tester = new CommandTester($cmd);
        $status = $tester->execute([
            '--user-id' => '1',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $status);
        $out = $tester->getDisplay();
        self::assertStringContainsString('[DRY] speciality:', $out);
        self::assertStringContainsString('  - Cardio', $out);
        self::assertStringContainsString('[DRY] department:', $out);
    }

    public function testUserNotFoundReturnsFailure(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with(999)->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $cmd = new SeedDatabaseCommand($em, [$this->provider('department', ['A'])]);

        $tester = new CommandTester($cmd);
        $status = $tester->execute(['--user-id' => '999']);

        self::assertSame(1 /* Command::FAILURE */, $status);
        self::assertStringContainsString('User #999 not found', $tester->getDisplay());
    }

    public function testPurgeCallsTruncateStatement(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('TRUNCATE TABLE'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(new User());
        $em->method('getRepository')->willReturn($repo);

        $cmd = new SeedDatabaseCommand($em, [$this->provider('department', [])]);
        $tester = new CommandTester($cmd);

        $status = $tester->execute(['--user-id' => '1', '--purge' => true]);

        self::assertSame(0, $status);
        self::assertStringContainsString('TRUNCATE:', $tester->getDisplay());
    }
}
