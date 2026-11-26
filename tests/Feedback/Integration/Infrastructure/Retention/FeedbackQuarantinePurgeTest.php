<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Integration\Infrastructure\Retention;

use App\Feedback\Application\Contract\FeedbackQuarantinePurgeInterface;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retention 30 days (default), now = 2026-12-01 12:00:00, so the cutoff is 2026-11-01 12:00:00.
 */
final class FeedbackQuarantinePurgeTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    public function testDeletesOnlyQuarantinedEntriesOlderThanTheRetention(): void
    {
        self::mockTime('2026-12-01 12:00:00');
        $this->feedback('31 days in quarantine', '2026-10-31 12:00:00');
        $this->feedback('30 days and a second', '2026-11-01 11:59:59');
        $kept30 = $this->feedback('exactly 30 days', '2026-11-01 12:00:00');
        $kept29 = $this->feedback('29 days in quarantine', '2026-11-02 12:00:00');
        $normal = $this->feedback('old normal feedback', null, createdAt: '2026-06-01 12:00:00');
        $released = $this->feedback('released after quarantine', '2026-10-01 12:00:00', release: true);

        $purge = self::getContainer()->get(FeedbackQuarantinePurgeInterface::class);
        self::assertSame(2, $purge->countExpired());
        self::assertSame(2, $purge->purge());
        self::assertSame(0, $purge->countExpired());

        $left = array_map(intval(...), self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT id FROM feedback ORDER BY id'));
        self::assertSame([$kept30, $kept29, $normal, $released], $left);
    }

    public function testCommandIsADryRunUnlessApplied(): void
    {
        self::mockTime('2026-12-01 12:00:00');
        $this->feedback('old spam', '2026-10-01 12:00:00');
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:feedback:purge-quarantine'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('past their retention: 1 (dry run, nothing deleted)', $tester->getDisplay());
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM feedback'));

        $tester->execute(['--apply' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 quarantined entry.', $tester->getDisplay());
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM feedback'));
    }

    private function feedback(string $message, ?string $quarantinedAt, ?string $createdAt = null, bool $release = false): int
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setPageUrl('https://example.test/');
        if (null !== $quarantinedAt) {
            $feedback->quarantine(['spam_pattern'], 6, null, new \DateTimeImmutable($quarantinedAt));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();
        if ($release) {
            $reviewer = UserFactory::new()->asAdmin()->create();
            $feedback->release($reviewer, new \DateTimeImmutable('2026-10-02 12:00:00'));
            $entityManager->flush();
        }

        if (null !== $createdAt) {
            self::getContainer()->get(Connection::class)->executeStatement('UPDATE feedback SET created_at = ? WHERE id = ?', [$createdAt, $feedback->getId()]);
        }

        return (int) $feedback->getId();
    }
}
