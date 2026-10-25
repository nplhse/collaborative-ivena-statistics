<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Engagement\Infrastructure\EventSubscriber\MonthlyReminderDeliverySubscriber;
use App\Engagement\Infrastructure\Notification\MonthlyReminderEmailCustomizer;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

final class MonthlyReminderDeliverySubscriberTest extends DatabaseKernelTestCase
{
    public function testSuccessOfTheCurrentAttemptMarksTheDispatchAsSent(): void
    {
        $dispatch = $this->createDispatch('attempt-a');

        $this->subscriber()->onSent($this->sent($this->email((string) $dispatch->getId(), 'attempt-a')));

        $this->entityManager()->refresh($dispatch);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch->getStatus());
        self::assertNotNull($dispatch->getDeliveredAt());
    }

    /**
     * Attempt A is delayed by mailer retries, an admin starts attempt B on the same dispatch, then A's email
     * goes out after all: B is still running and must not be marked as sent by A.
     */
    public function testALateSuccessOfAnEarlierAttemptLeavesTheCurrentAttemptAlone(): void
    {
        $dispatch = $this->createDispatch('attempt-a');
        $dispatch->startAttempt('attempt-b');
        $this->entityManager()->flush();

        $this->subscriber()->onSent($this->sent($this->email((string) $dispatch->getId(), 'attempt-a')));
        $this->subscriber()->onSent($this->sent($this->email((string) $dispatch->getId(), null)));

        $this->entityManager()->refresh($dispatch);
        self::assertSame(MonthlyReminderDispatchStatus::Queued, $dispatch->getStatus());
        self::assertNull($dispatch->getDeliveredAt());
    }

    public function testDispatchesFromBeforeAttemptIdsMatchEmailsWithoutOne(): void
    {
        $dispatch = $this->createDispatch(null);

        $this->subscriber()->onSent($this->sent($this->email((string) $dispatch->getId(), null)));

        $this->entityManager()->refresh($dispatch);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch->getStatus());
    }

    public function testIgnoresEmailsWithoutUsableDispatchHeader(): void
    {
        $dispatch = $this->createDispatch('attempt-a');

        $this->subscriber()->onSent($this->sent($this->email(null, 'attempt-a')));
        $this->subscriber()->onSent($this->sent($this->email('not-a-number', 'attempt-a')));
        $this->subscriber()->onSent($this->sent($this->email('999999', 'attempt-a')));
        $this->subscriber()->onSent(new SentMessageEvent(new SentMessage(new RawMessage('raw'), new MailerEnvelope(new Address('no-reply@example.test'), [new Address('owner@example.test')]))));

        $this->entityManager()->refresh($dispatch);
        self::assertSame(MonthlyReminderDispatchStatus::Queued, $dispatch->getStatus());
    }

    private function email(?string $dispatchId, ?string $attemptId): TemplatedEmail
    {
        $email = new TemplatedEmail()
            ->from('no-reply@example.test')
            ->to('owner@example.test')
            ->subject('Reminder')
            ->text('Reminder body');
        if (null !== $dispatchId) {
            $email->getHeaders()->addTextHeader(MonthlyReminderEmailCustomizer::DISPATCH_ID_HEADER, $dispatchId);
        }
        if (null !== $attemptId) {
            $email->getHeaders()->addTextHeader(MonthlyReminderEmailCustomizer::ATTEMPT_ID_HEADER, $attemptId);
        }

        return $email;
    }

    private function sent(TemplatedEmail $email): SentMessageEvent
    {
        return new SentMessageEvent(new SentMessage($email, new MailerEnvelope(new Address('no-reply@example.test'), [new Address('owner@example.test')])));
    }

    private function subscriber(): MonthlyReminderDeliverySubscriber
    {
        return self::getContainer()->get(MonthlyReminderDeliverySubscriber::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createDispatch(?string $attemptId): MonthlyReminderDispatch
    {
        $owner = UserFactory::createOne(['email' => sprintf('delivery-%s@example.test', bin2hex(random_bytes(4))), 'isVerified' => true]);
        $state = StateFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
            'isParticipating' => true,
        ]);

        $dispatch = new MonthlyReminderDispatch(
            $hospital,
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')),
            MonthlyReminderDispatchStatus::Queued,
            (string) $owner->getEmail(),
        );
        if (null !== $attemptId) {
            $dispatch->startAttempt($attemptId);
        }
        self::getContainer()->get(MonthlyReminderDispatchRepository::class)->save($dispatch);

        return $dispatch;
    }
}
