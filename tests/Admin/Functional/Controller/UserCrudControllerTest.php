<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Admin\UI\Http\Controller\User\UserCrudController;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UserCrudControllerTest extends WebTestCase
{
    use Factories;
    use MailerAssertionsTrait;

    public function testAdminCanDisableAndReenableUser(): void
    {
        $client = self::createClient();

        $target = UserFactory::createOne([
            'username' => 'target-user-'.bin2hex(random_bytes(4)),
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'user-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->setCheckboxValue($form, 'User[isEnabled]', false);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertFalse($target->isEnabled());

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->setCheckboxValue($form, 'User[isEnabled]', true);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertTrue($target->isEnabled());
    }

    public function testAdminCannotDisableOwnAccount(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'self-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $adminId = $admin->getId();
        self::assertNotNull($adminId);

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$adminId.'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->setCheckboxValue($form, 'User[isEnabled]', false);
        $client->submit($form);

        self::assertResponseStatusCodeSame(500);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloadedAdmin = self::getContainer()->get(UserRepository::class)->find($adminId);
        self::assertInstanceOf(User::class, $reloadedAdmin);
        self::assertTrue($reloadedAdmin->isEnabled());
    }

    public function testNonAdminUserGetsForbiddenOnUserIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'user-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/user');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGrantingParticipantRoleSendsWelcomeEmail(): void
    {
        $client = self::createClient();

        $target = UserFactory::createOne([
            'username' => 'welcome-grant-'.bin2hex(random_bytes(4)),
            'roles' => [UserRole::USER],
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'welcome-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->selectRoles($form, [UserRole::USER, UserRole::PARTICIPANT]);
        $client->submit($form);
        self::assertResponseRedirects();

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertContains(UserRole::PARTICIPANT, $target->getRoles());
        self::assertCount(1, $this->welcomeEmails());
    }

    public function testSavingAlreadyActiveParticipantDoesNotResendWelcomeEmail(): void
    {
        $client = self::createClient();

        $target = UserFactory::createOne([
            'username' => 'welcome-existing-'.bin2hex(random_bytes(4)),
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'welcome-existing-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->selectRoles($form, [UserRole::USER, UserRole::PARTICIPANT]);
        $client->submit($form);
        self::assertResponseRedirects();

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertContains(UserRole::PARTICIPANT, $target->getRoles());
        self::assertCount(0, $this->welcomeEmails());
    }

    public function testCreatingUserAsParticipantSendsWelcomeEmail(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'welcome-create-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/new');
        self::assertResponseIsSuccessful();

        $suffix = bin2hex(random_bytes(4));
        $form = $crawler->selectButton('Create')->form([
            'User[username]' => 'welcome-create-'.$suffix,
            'User[email]' => 'welcome-create-'.$suffix.'@example.test',
            'User[password]' => 'password',
        ]);
        $this->selectRoles($form, [UserRole::USER, UserRole::PARTICIPANT]);
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertCount(1, $this->welcomeEmails());
    }

    public function testAdminCannotSaveUsernameWithInternalWhitespace(): void
    {
        $client = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $originalUsername = 'admin-space-target-'.$suffix;
        $target = UserFactory::createOne([
            'username' => $originalUsername,
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'admin-space-admin-'.$suffix,
            ])
        ;

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form([
            'User[username]' => 'invalid name '.$suffix,
        ]);
        $client->submit($form);

        self::assertFalse($client->getResponse()->isRedirect());
        self::assertStringContainsString(
            'Please use letters and numbers. Periods, underscores, and hyphens are fine, but spaces are not.',
            (string) $client->getResponse()->getContent(),
        );

        $targetId = $target->getId();
        self::assertNotNull($targetId);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = self::getContainer()->get(UserRepository::class)->find($targetId);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($originalUsername, $reloaded->getUsername());
    }

    public function testVerifyingUserOnEditKeepsOriginalPassword(): void
    {
        $client = self::createClient();
        $plainPassword = 'keep-original-pass-'.bin2hex(random_bytes(4));
        $target = UserFactory::createOne([
            'username' => 'verify-keep-pass-'.bin2hex(random_bytes(4)),
            'isVerified' => false,
            'password' => $plainPassword,
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'verify-keep-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->setCheckboxValue($form, 'User[isVerified]', true);
        $client->submit($form);
        self::assertResponseRedirects();

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertTrue($target->isVerified());
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($target, $plainPassword),
        );
    }

    public function testUpdatingUserWithoutSubmittedPasswordDoesNotRehashExistingHash(): void
    {
        $client = self::createClient();
        $plainPassword = 'toggle-keep-pass-'.bin2hex(random_bytes(4));
        $target = UserFactory::createOne([
            'username' => 'toggle-keep-pass-'.bin2hex(random_bytes(4)),
            'isVerified' => false,
            'password' => $plainPassword,
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'toggle-keep-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $originalHash = $target->getPassword();
        self::assertNotNull($originalHash);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $managedUser = $entityManager->find(User::class, $target->getId());
        self::assertInstanceOf(User::class, $managedUser);
        $managedUser->setIsVerified(true);

        self::getContainer()->get(UserCrudController::class)->updateEntity($entityManager, $managedUser);

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertTrue($target->isVerified());
        self::assertSame($originalHash, $target->getPassword());
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($target, $plainPassword),
        );
    }

    public function testChangingPasswordOnEditRehashesWhenANewPasswordIsSubmitted(): void
    {
        $client = self::createClient();
        $originalPassword = 'old-pass-'.bin2hex(random_bytes(4));
        $newPassword = 'new-pass-'.bin2hex(random_bytes(4));
        $target = UserFactory::createOne([
            'username' => 'change-pass-'.bin2hex(random_bytes(4)),
            'password' => $originalPassword,
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'change-pass-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form([
            'User[password]' => $newPassword,
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        \Zenstruck\Foundry\Persistence\refresh($target);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertFalse($hasher->isPasswordValid($target, $originalPassword));
        self::assertTrue($hasher->isPasswordValid($target, $newPassword));
    }

    private function setCheckboxValue(Form $form, string $name, bool $checked): void
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);

        if ($checked) {
            $field->tick();
        } else {
            $field->untick();
        }
    }

    /**
     * @param list<string> $roles
     */
    private function selectRoles(Form $form, array $roles): void
    {
        $field = $form->get('User[roles]');
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->setValue($roles);
    }

    /**
     * @return list<Email>
     */
    private function welcomeEmails(): array
    {
        $messages = [];
        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            $subject = (string) $message->getSubject();
            if (str_contains($subject, 'Welcome to') || str_contains($subject, 'Willkommen bei')) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
