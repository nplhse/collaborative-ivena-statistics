<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Controller;

use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Tests\Support\Foundry\DatabaseWebTestCase;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;

final class NotificationSettingsControllerTest extends DatabaseWebTestCase
{
    use InteractsWithAuthenticatedUser;

    private const string FORM = 'notification_preferences';

    public function testAnonymousUsersAreSentToTheLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/settings/notifications');

        self::assertResponseRedirects('/login');
    }

    public function testShowsCategoriesTypesAndDefaults(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="preference-category-imports"] thead', 'Imports');
        self::assertSame('default', $this->selectedValue($crawler, 'category-imports-in_app'));
        self::assertSame(['Default (on)', 'On', 'Off'], $this->optionLabels($crawler, 'category-imports-in_app'));
        self::assertSame(['Default (off)', 'On', 'Off'], $this->optionLabels($crawler, 'type-import-completed-email'));
        self::assertSelectorExists('[data-testid="preference-type-import.completed"]');
        self::assertSelectorExists('[data-testid="preference-type-participation.activated"]');
        self::assertSelectorNotExists('[data-testid="preference-type-import.batch_finished"]', 'role-scoped types need the role');
        self::assertSelectorNotExists('[data-testid="preference-category-administration"]');
        self::assertSelectorExists('#notification-preferences-types-imports.collapse:not(.show)');
        self::assertSelectorTextContains('body', 'Monthly data submission reminder');
    }

    public function testAdministratorsSeeRoleScopedTypes(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));

        $client->request(Request::METHOD_GET, '/settings/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="preference-type-import.batch_finished"]');
    }

    public function testShowsStoredOverridesAndInheritedValues(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        $this->store()->save($this->id($user), [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Toast, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Email, true),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');

        self::assertSame('off', $this->selectedValue($crawler, 'category-imports-toast'));
        self::assertSame('on', $this->selectedValue($crawler, 'type-import-failed-email'));
        self::assertSame('default', $this->selectedValue($crawler, 'type-import-completed-toast'));
        self::assertSame('Default (off)', $this->optionLabels($crawler, 'type-import-completed-toast')[0], 'a type shows the category override as its default');
        self::assertSelectorExists('#notification-preferences-types-imports.collapse.show', 'categories with type overrides start expanded');
    }

    public function testSavesTheThreeStates(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        $userId = $this->id($user);
        $this->store()->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Toast, false),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        $form = $crawler->selectButton('Save')->form();
        $form[self::FORM.'[preferences][category-imports-email]'] = 'on';
        $form[self::FORM.'[preferences][type-import-completed-in_app]'] = 'off';
        $form[self::FORM.'[preferences][type-import-failed-toast]'] = 'default';
        $client->submit($form);

        self::assertResponseRedirects('/settings/notifications');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Notification preferences saved.');
        self::assertSame(
            [
                ['scope' => 'category', 'preference_key' => 'imports', 'channel' => 'email', 'enabled' => true],
                ['scope' => 'type', 'preference_key' => 'import.completed', 'channel' => 'in_app', 'enabled' => false],
            ],
            $this->rows($userId),
        );
    }

    public function testInvalidCsrfTokenChangesNothing(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_POST, '/settings/notifications', [self::FORM => [
            '_token' => 'invalid',
            'preferences' => ['category-imports-email' => 'on'],
        ]]);

        self::assertResponseIsUnprocessable();
        self::assertSame([], $this->rows($this->id($user)));
    }

    public function testTypesThatAreNotOfferedCannotBeConfigured(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        $token = (string) $crawler->filter('input[name="'.self::FORM.'[_token]"]')->attr('value');
        $client->request(Request::METHOD_POST, '/settings/notifications', [self::FORM => [
            '_token' => $token,
            'preferences' => ['type-import-batch_finished-in_app' => 'off'],
        ]]);

        self::assertResponseIsUnprocessable();
        self::assertSame([], $this->rows($this->id($user)));
    }

    public function testUnknownStateIsRejected(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        $token = (string) $crawler->filter('input[name="'.self::FORM.'[_token]"]')->attr('value');
        $client->request(Request::METHOD_POST, '/settings/notifications', [self::FORM => [
            '_token' => $token,
            'preferences' => ['category-imports-email' => 'maybe'],
        ]]);

        self::assertResponseIsUnprocessable();
        self::assertSame([], $this->rows($this->id($user)));
    }

    public function testUserCanOptOutOfMonthlyReminder(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER'], 'receivesMonthlySubmissionReminder' => true]);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        $form = $crawler->selectButton('Save')->form();
        $checkbox = $form[self::FORM.'[reminder][receivesMonthlySubmissionReminder]'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $client->submit($form);

        self::assertResponseRedirects('/settings/notifications');
        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertFalse($user->receivesMonthlySubmissionReminder());
        self::assertSame([], $this->rows($this->id($user)), 'defaults are not stored');
    }

    private function selectedValue(Crawler $crawler, string $field): ?string
    {
        $select = $crawler->filter(\sprintf('select[name="%s[preferences][%s]"]', self::FORM, $field));
        self::assertCount(1, $select, $field);

        return $select->filter('option[selected]')->attr('value');
    }

    /**
     * @return list<string>
     */
    private function optionLabels(Crawler $crawler, string $field): array
    {
        return array_values($crawler
            ->filter(\sprintf('select[name="%s[preferences][%s]"] option', self::FORM, $field))
            ->each(static fn (Crawler $option): string => trim($option->text())));
    }

    /**
     * @return list<array{scope: string, preference_key: string, channel: string, enabled: bool}>
     */
    private function rows(int $userId): array
    {
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT scope, preference_key, channel, enabled FROM user_notification_preference WHERE user_id = ? ORDER BY scope, preference_key, channel',
            [$userId],
        );

        return array_map(static fn (array $row): array => [
            'scope' => (string) $row['scope'],
            'preference_key' => (string) $row['preference_key'],
            'channel' => (string) $row['channel'],
            'enabled' => (bool) $row['enabled'],
        ], $rows);
    }

    private function store(): NotificationPreferenceStoreInterface
    {
        return self::getContainer()->get(NotificationPreferenceStoreInterface::class);
    }

    private function id(User $user): int
    {
        return (int) $user->getId();
    }
}
