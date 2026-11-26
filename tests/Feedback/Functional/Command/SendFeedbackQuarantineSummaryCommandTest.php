<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Functional\Command;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SendFeedbackQuarantineSummaryCommandTest extends DatabaseKernelTestCase
{
    public function testDryRunCountsAndSendMarks(): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Spam')
            ->setPageUrl('https://example.test/')
            ->quarantine(['spam_pattern'], 6, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:feedback:quarantine-summary'));

        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Entries waiting for the summary: 1', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne('SELECT quarantine_reported_at FROM feedback'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Summary sent for 1 quarantined entry.', $tester->getDisplay());
        self::assertNotNull(self::getContainer()->get(Connection::class)->fetchOne('SELECT quarantine_reported_at FROM feedback'));

        $tester->execute([]);
        self::assertStringContainsString('no summary sent', $tester->getDisplay());
    }
}
