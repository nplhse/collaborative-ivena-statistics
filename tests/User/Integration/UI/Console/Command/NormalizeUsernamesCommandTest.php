<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\UI\Console\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\UI\Console\Command\NormalizeUsernamesCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:user:normalize-usernames` against the database: the command reads and writes users through the repository and
 * the entity manager itself, so it has no unit test. The proposed replacement comes from
 * `UserUsernameConstraints::proposeNormalized()` (`UserUsernameConstraintsTest`).
 */
#[ResetDatabase]
final class NormalizeUsernamesCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testDryRunListsTheReplacementAndRenamesNothing(): void
    {
        UserFactory::createOne(['username' => 'already-valid']);
        $john = UserFactory::createOne(['username' => 'John Doe']);
        $tester = $this->createCommandTester();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run: no rows will be written.', $display);
        self::assertMatchesRegularExpression('/John Doe\s+John-Doe\s+'.NormalizeUsernamesCommand::STATUS_WOULD_RENAME.'/', $display);
        self::assertStringNotContainsString('already-valid', $display, 'valid usernames are not listed');
        \Zenstruck\Foundry\Persistence\refresh($john);
        self::assertSame('John Doe', $john->getUsername());
    }

    public function testApplyRenamesToTheUniqueReplacement(): void
    {
        $valid = UserFactory::createOne(['username' => 'already-valid']);
        $john = UserFactory::createOne(['username' => 'John Doe']);
        $tester = $this->createCommandTester();

        $tester->execute(['--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(NormalizeUsernamesCommand::STATUS_RENAMED, $tester->getDisplay());
        \Zenstruck\Foundry\Persistence\refresh($john);
        \Zenstruck\Foundry\Persistence\refresh($valid);
        self::assertSame('John-Doe', $john->getUsername());
        self::assertSame('already-valid', $valid->getUsername());
    }

    /** @return iterable<string, array{list<array{username: string}>, string, string}> */
    public static function unchangedUsernames(): iterable
    {
        // other users, the username to normalise, the reported status
        yield 'replacement is taken' => [[['username' => 'John-Doe']], 'John Doe', NormalizeUsernamesCommand::STATUS_COLLISION];
        // the unique index is on LOWER(username) (#1353)
        yield 'replacement differs only in case from another username' => [[['username' => 'jane-doe']], 'Jane Doe', NormalizeUsernamesCommand::STATUS_COLLISION];
        yield 'replacement is still invalid' => [[], 'foo@bar', NormalizeUsernamesCommand::STATUS_STILL_INVALID];
        // digits only are reserved for ids (#1354); an administrator renames such an account by hand
        yield 'digits only' => [[], '1234', NormalizeUsernamesCommand::STATUS_STILL_INVALID];
    }

    /**
     * @param list<array{username: string}> $otherUsers
     */
    #[DataProvider('unchangedUsernames')]
    public function testUsernameStaysUnchangedOnApply(array $otherUsers, string $username, string $status): void
    {
        UserFactory::createSequence($otherUsers);
        $user = UserFactory::createOne(['username' => $username]);
        $tester = $this->createCommandTester();

        $tester->execute(['--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/'.preg_quote($username, '/').'\s+\S+\s+'.$status.'/', $tester->getDisplay());
        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertSame($username, $user->getUsername());
    }

    private function createCommandTester(): CommandTester
    {
        /** @var NormalizeUsernamesCommand $command */
        $command = self::getContainer()->get(NormalizeUsernamesCommand::class);

        return new CommandTester($command);
    }
}
