<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\EventSubscriber;

use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Application\Event\UserBecameParticipant;
use App\User\Application\Mail\WelcomeEmailNextStepsBuilder;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\EventSubscriber\UserBecameParticipantNotificationSubscriber;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UserBecameParticipantNotificationSubscriberTest extends KernelTestCase
{
    use Factories;

    public function testOnUserBecameParticipantSendsWelcomeEmail(): void
    {
        $user = UserFactory::createOne([
            'username' => 'welcome-me',
            'email' => 'welcome-me@example.test',
        ]);

        $mailer = $this->createMock(TransactionalMailer::class);
        $mailer->expects(self::once())
            ->method('sendParticipationWelcomeEmail')
            ->with(
                'welcome-me@example.test',
                'welcome-me',
                self::isString(),
                self::callback(static function (array $nextSteps): bool {
                    self::assertNotEmpty($nextSteps);
                    self::assertArrayHasKey('titleKey', $nextSteps[0]);
                    self::assertArrayHasKey('descriptionKey', $nextSteps[0]);
                    self::assertArrayHasKey('url', $nextSteps[0]);

                    return true;
                }),
                self::isString(),
            );

        $this->subscriber($mailer)->onUserBecameParticipant(new UserBecameParticipant((int) $user->getId()));
    }

    public function testOnUserBecameParticipantSkipsDisabledUsers(): void
    {
        $user = UserFactory::createOne([
            'username' => 'welcome-disabled',
            'email' => 'welcome-disabled@example.test',
            'isEnabled' => false,
        ]);

        $mailer = $this->createMock(TransactionalMailer::class);
        $mailer->expects(self::never())->method('sendParticipationWelcomeEmail');

        $this->subscriber($mailer)->onUserBecameParticipant(new UserBecameParticipant((int) $user->getId()));
    }

    public function testOnUserBecameParticipantSkipsUnknownUsers(): void
    {
        $mailer = $this->createMock(TransactionalMailer::class);
        $mailer->expects(self::never())->method('sendParticipationWelcomeEmail');

        $this->subscriber($mailer)->onUserBecameParticipant(new UserBecameParticipant(999_999_999));
    }

    public function testOnUserBecameParticipantSkipsUsersWithoutEmail(): void
    {
        $user = UserFactory::createOne([
            'username' => 'welcome-blank-mail',
            'email' => 'welcome-blank-mail@example.test',
        ]);
        $user->setEmail('');
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();

        $mailer = $this->createMock(TransactionalMailer::class);
        $mailer->expects(self::never())->method('sendParticipationWelcomeEmail');

        $this->subscriber($mailer)->onUserBecameParticipant(new UserBecameParticipant((int) $user->getId()));
    }

    private function subscriber(TransactionalMailer $mailer): UserBecameParticipantNotificationSubscriber
    {
        return new UserBecameParticipantNotificationSubscriber(
            self::getContainer()->get(UserRepository::class),
            $mailer,
            self::getContainer()->get(\App\Shared\Application\Locale\LocaleResolver::class),
            self::getContainer()->get(WelcomeEmailNextStepsBuilder::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
        );
    }
}
