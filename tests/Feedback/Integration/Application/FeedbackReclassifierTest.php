<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Integration\Application;

use App\Feedback\Application\FeedbackMessageFingerprint;
use App\Feedback\Application\FeedbackReclassification;
use App\Feedback\Application\FeedbackReclassifier;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Existing rows modelled on the spam seen until October 2026. Weights (guests): no page context 4,
 * link 4, blocked link domain 6, spam pattern 6, token-only message 3, repeated guest email 3,
 * duplicate message 3; quarantine at 6. Timing counts as unknown.
 */
final class FeedbackReclassifierTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    /** @var array<string, int> */
    private array $ids = [];

    public function testDryRunListsMatchesAndChangesNothing(): void
    {
        $this->legacyRows();

        $results = $this->reclassifier()->reclassify(false);

        self::assertSame([
            [$this->ids['price again'], 7, ['missing_page_context', 'repeated_guest_email', 'reclassified']],
            [$this->ids['token'], 7, ['missing_page_context', 'token_only_message', 'reclassified']],
            [$this->ids['telegra'], 20, ['missing_page_context', 'contains_url', 'blocked_link_domain', 'spam_pattern', 'reclassified']],
            [$this->ids['price duplicate'], 7, ['missing_page_context', 'duplicate_message', 'reclassified']],
        ], array_map(static fn (FeedbackReclassification $r): array => [$r->feedbackId, $r->score, $r->reasons], $results));

        self::assertSame(1, $this->rowCount("classification = 'quarantined'"), 'only the row quarantined before');
        self::assertSame(9, $this->rowCount('message_fingerprint IS NULL'), 'no fingerprints written');
    }

    public function testApplyQuarantinesMatchesWithoutReportingThem(): void
    {
        $this->legacyRows();
        self::mockTime('2026-11-13 09:00:00');

        self::assertCount(4, $this->reclassifier()->reclassify(true));

        $row = $this->connection()->fetchAssociative('SELECT classification, classification_reasons, spam_score, classified_at, classified_by_id, quarantine_reported_at FROM feedback WHERE id = ?', [$this->ids['token']]);
        self::assertIsArray($row);
        self::assertSame('quarantined', $row['classification']);
        self::assertSame(['missing_page_context', 'token_only_message', 'reclassified'], json_decode((string) $row['classification_reasons'], true));
        self::assertSame(7, (int) $row['spam_score']);
        self::assertSame('2026-11-13 09:00:00', $row['classified_at'], 'retention counts from the reclassification');
        self::assertNull($row['classified_by_id']);
        self::assertSame('2026-11-13 09:00:00', $row['quarantine_reported_at'], 'reviewed by whoever applied it');

        self::assertSame(5, $this->rowCount("classification = 'quarantined'"));
        self::assertSame(0, $this->rowCount('message_fingerprint IS NULL'));
        self::assertSame(
            FeedbackMessageFingerprint::fromMessage('Hola, quería saber tu precio.'),
            $this->connection()->fetchOne('SELECT message_fingerprint FROM feedback WHERE id = ?', [$this->ids['price duplicate']]),
        );
        foreach (['bug report', 'password', 'price first', 'price other address', 'screenshot'] as $legit) {
            self::assertSame('normal', $this->connection()->fetchOne('SELECT classification FROM feedback WHERE id = ?', [$this->ids[$legit]]), $legit);
        }

        self::assertSame([], $this->reclassifier()->reclassify(true), 'a second run finds nothing');
    }

    public function testCommandIsADryRunUnlessApplied(): void
    {
        $this->legacyRows();
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:feedback:reclassify'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run: 4 entries would move into quarantine.', $display);
        self::assertStringContainsString('token_only_message', $display);
        self::assertStringNotContainsString('MEYJTJ679293MARTHHDF', $display, 'no message content');
        self::assertStringNotContainsString('telegra.ph', $display);
        self::assertSame(1, $this->rowCount("classification = 'quarantined'"));

        $tester->execute(['--apply' => true]);
        self::assertStringContainsString('Moved 4 entries into quarantine.', $tester->getDisplay());

        $tester->execute([]);
        self::assertStringContainsString('No normal feedback matches the spam rules.', $tester->getDisplay());
    }

    private function legacyRows(): void
    {
        $user = UserFactory::new()->create();
        $this->row('bug report', '2026-09-01 10:00:00', 'Table shows no data although there should be results.', null, 'app_explore_allocation_list', $user);
        $this->row('password', '2026-09-02 10:00:00', 'I cannot reset my password, what shall I do?', 'nurse@example.test', 'app_page_show');
        $this->row('price first', '2026-10-01 09:00:00', 'Hi, I wanted to know your price.', 'bot-a@example.test', null);
        $this->row('price again', '2026-10-04 19:00:00', 'Ola, quería saber o seu prezo.', 'bot-a@example.test', null);
        $this->row('price other address', '2026-10-08 14:00:00', 'Hola, quería saber tu precio.', 'bot-b@example.test', null);
        $this->row('token', '2026-10-09 22:00:00', 'MEYJTJ679293MARTHHDF', 'bot-d@example.test', null);
        $this->row('telegra', '2026-10-12 05:00:00', 'Read the news and earn $50,000,000 per month https://telegra.ph/Read-the-news-1', 'bot-e@example.test', null);
        $this->row('screenshot', '2026-10-13 08:00:00', 'Screenshot of the error: https://example.test/shot.png', 'doctor@example.test', 'app_default');
        $this->row('already quarantined', '2026-10-20 08:00:00', 'Kaixo, zure prezioa jakin nahi nuen.', 'bot-f@example.test', null, quarantined: true);
        $this->row('price duplicate', '2026-10-26 04:00:00', 'Hola, quería saber tu precio.', 'bot-c@example.test', null);
    }

    private function row(string $key, string $createdAt, string $message, ?string $guestEmail, ?string $routeName, ?User $user = null, bool $quarantined = false): void
    {
        self::mockTime($createdAt);
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setGuestEmail($guestEmail)
            ->setSubmittedBy($user)
            ->setRouteName($routeName)
            ->setPageUrl('https://example.test/');
        if ($quarantined) {
            $feedback
                ->setMessageFingerprint(FeedbackMessageFingerprint::fromMessage($message))
                ->quarantine(['missing_page_context', 'duplicate_message'], 7, null, new \DateTimeImmutable($createdAt));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();
        $this->ids[$key] = (int) $feedback->getId();
    }

    private function reclassifier(): FeedbackReclassifier
    {
        return self::getContainer()->get(FeedbackReclassifier::class);
    }

    private function rowCount(string $where): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM feedback WHERE '.$where);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
