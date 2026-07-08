<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderMailer;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Engagement\Infrastructure\EventSubscriber\MonthlyReminderDeliverySubscriber;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Mime\Address;

final class MonthlyReminderDeliverySubscriberTest extends DatabaseKernelTestCase
{
    public function testOnSentMarksDispatchAsDelivered(): void
    {
        self::bootKernel();

        $dispatch = $this->createDispatch();
        $subscriber = self::getContainer()->get(MonthlyReminderDeliverySubscriber::class);

        $email = new TemplatedEmail()
            ->from('no-reply@example.test')
            ->to('owner@example.test')
            ->subject('Reminder')
            ->text('Reminder body');
        $email->getHeaders()->addTextHeader(
            MonthlyReminderMailer::DISPATCH_ID_HEADER,
            (string) $dispatch->getId(),
        );

        $subscriber->onSent(new SentMessageEvent(new SentMessage(
            $email,
            new MailerEnvelope(
                new Address('no-reply@example.test'),
                [new Address('owner@example.test')],
            ),
        )));

        $updated = self::getContainer()->get(MonthlyReminderDispatchRepository::class)->find($dispatch->getId());
        self::assertNotNull($updated);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $updated->getStatus());
        self::assertNotNull($updated->getDeliveredAt());
    }

    public function testOnMessengerFailedMarksDispatchWhenRetriesAreExhausted(): void
    {
        self::bootKernel();

        $dispatch = $this->createDispatch();
        $subscriber = self::getContainer()->get(MonthlyReminderDeliverySubscriber::class);

        $email = new TemplatedEmail()
            ->from('no-reply@example.test')
            ->to('owner@example.test')
            ->subject('Reminder')
            ->text('Reminder body');
        $email->getHeaders()->addTextHeader(
            MonthlyReminderMailer::DISPATCH_ID_HEADER,
            (string) $dispatch->getId(),
        );
        $message = new SendEmailMessage($email);

        $event = new WorkerMessageFailedEvent(
            new Envelope($message),
            'async_mail',
            new HandlerFailedException(new Envelope($message), [
                new UnrecoverableMessageHandlingException('SMTP rate limit'),
            ]),
        );

        $subscriber->onMessengerFailed($event);

        $updated = self::getContainer()->get(MonthlyReminderDispatchRepository::class)->find($dispatch->getId());
        self::assertNotNull($updated);
        self::assertSame(MonthlyReminderDispatchStatus::Failed, $updated->getStatus());
        self::assertStringContainsString('SMTP rate limit', (string) $updated->getFailureReason());
    }

    private function createDispatch(): MonthlyReminderDispatch
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('delivery-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
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

        $repository = self::getContainer()->get(MonthlyReminderDispatchRepository::class);
        $repository->save($dispatch);

        return $dispatch;
    }
}
