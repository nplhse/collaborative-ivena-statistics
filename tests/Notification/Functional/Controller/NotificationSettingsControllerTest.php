<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Controller;

use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Tests\Support\Foundry\DatabaseWebTestCase;
use App\Tests\Support\Notification\Type\FixedTestNotification;
use App\Tests\Support\Notification\Type\MandatoryInAppTestNotification;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
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
        self::assertTrue($this->isChecked($crawler, 'category-imports-in_app'));
        self::assertFalse($this->isChecked($crawler, 'category-imports-email'));
        self::assertFalse($this->isChecked($crawler, 'type-import-completed-email'));
        self::assertTrue($this->isChecked($crawler, 'type-import-completed-toast'));
        self::assertSelectorExists('[data-testid="preference-type-import.completed"]');
        self::assertSelectorExists('[data-testid="preference-type-participation.activated"]');
        self::assertSelectorNotExists('[data-testid="preference-type-import.batch_finished"]', 'role-scoped types need the role');
        self::assertSelectorNotExists('[data-testid="preference-category-administration"]');
        self::assertSelectorExists('#notification-preferences-types-imports.collapse:not(.show)');
        self::assertSelectorNotExists('[data-controller="notification-preferences"] select', 'no three-state selects any more');
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

    public function testMandatoryAndFixedChannelsAreDisabledCheckboxesWithAnExplanation(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        self::getContainer()->set(NotificationRegistry::class, new NotificationRegistry([
            'test.mandatory_in_app' => MandatoryInAppTestNotification::class,
            'test.fixed' => FixedTestNotification::class,
        ]));

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');

        self::assertResponseIsSuccessful();
        $mandatory = $crawler->filter('[data-testid="preference-mandatory-type-test-mandatory_in_app-in_app"]');
        self::assertCount(1, $mandatory);
        self::assertNotNull($mandatory->attr('disabled'));
        self::assertNotNull($mandatory->attr('checked'));
        self::assertNull($mandatory->attr('name'), 'disabled checkboxes are not part of the form');
        $hintId = (string) $mandatory->attr('aria-describedby');
        self::assertSame('This notification is always delivered this way and cannot be turned off.', trim($crawler->filter('#'.$hintId)->text()));
        self::assertSame('This notification is always delivered this way and cannot be turned off.', $mandatory->ancestors()->filter('label')->attr('title'));

        $fixed = $crawler->filter('[data-testid="preference-fixed-type-test-fixed-toast"]');
        self::assertNotNull($fixed->attr('disabled'));
        self::assertNull($fixed->attr('checked'));
        self::assertSame('This notification cannot be configured.', trim($crawler->filter('#'.$fixed->attr('aria-describedby'))->text()));

        self::assertFalse($this->isChecked($crawler, 'type-test-mandatory_in_app-toast'));
        self::assertSame('true', $crawler->filter('input[name="'.self::FORM.'[preferences][category-account-toast]"]')->attr('data-follows'));
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

        self::assertFalse($this->isChecked($crawler, 'category-imports-toast'));
        self::assertTrue($this->isChecked($crawler, 'type-import-failed-email'));
        self::assertFalse($this->isChecked($crawler, 'type-import-completed-toast'), 'a type follows the category override');
        self::assertSame('false', $crawler->filter('input[name="'.self::FORM.'[preferences][type-import-failed-email]"]')->attr('data-follows'));
        self::assertSelectorExists('#notification-preferences-types-imports.collapse.show', 'categories with type overrides start expanded');
    }

    public function testSavingCheckboxesStoresOnlyDifferencesFromTheDefaults(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        $userId = $this->id($user);
        $this->store()->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Toast, false),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        $form = $crawler->selectButton('Save')->form();
        $this->checkbox($form, 'category-imports-email')->tick();
        $this->checkbox($form, 'type-import-completed-in_app')->untick();
        $this->checkbox($form, 'type-import-failed-toast')->tick();
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
            'ticking import.failed toast again matches its default, so the override is removed',
        );

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        self::assertTrue($this->isChecked($crawler, 'type-import-completed-email'), 'untouched types follow the category');
    }

    public function testInvalidCsrfTokenChangesNothing(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_POST, '/settings/notifications', [self::FORM => [
            '_token' => 'invalid',
            'preferences' => ['category-imports-email' => '1'],
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
            'preferences' => ['type-import-batch_finished-in_app' => '1'],
        ]]);

        self::assertResponseIsUnprocessable();
        self::assertSame([], $this->rows($this->id($user)));
    }

    public function testMandatoryChannelsCannotBeSubmitted(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        self::getContainer()->set(NotificationRegistry::class, new NotificationRegistry([
            'test.mandatory_in_app' => MandatoryInAppTestNotification::class,
        ]));

        $crawler = $client->request(Request::METHOD_GET, '/settings/notifications');
        $token = (string) $crawler->filter('input[name="'.self::FORM.'[_token]"]')->attr('value');
        $client->request(Request::METHOD_POST, '/settings/notifications', [self::FORM => [
            '_token' => $token,
            'preferences' => ['type-test-mandatory_in_app-in_app' => '0'],
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

    private function isChecked(Crawler $crawler, string $field): bool
    {
        $checkbox = $crawler->filter(\sprintf('input[type="checkbox"][name="%s[preferences][%s]"]', self::FORM, $field));
        self::assertCount(1, $checkbox, $field);

        return null !== $checkbox->attr('checked');
    }

    private function checkbox(Form $form, string $field): ChoiceFormField
    {
        $checkbox = $form[\sprintf('%s[preferences][%s]', self::FORM, $field)];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);

        return $checkbox;
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
