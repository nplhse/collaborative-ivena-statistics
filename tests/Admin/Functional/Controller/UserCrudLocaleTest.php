<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Shared\Application\Locale\SupportedLocales;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UserCrudLocaleTest extends WebTestCase
{
    use Factories;

    public function testAdminCanEditLocaleAndReminderPreference(): void
    {
        $client = self::createClient();

        $target = UserFactory::createOne([
            'username' => 'locale-user-'.bin2hex(random_bytes(4)),
            'locale' => SupportedLocales::DEFAULT,
            'receivesMonthlySubmissionReminder' => true,
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'locale-admin-'.bin2hex(random_bytes(4)),
            ]);

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/user/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $this->selectChoice($form, 'User[locale]', SupportedLocales::GERMAN);
        $this->setCheckboxValue($form, 'User[receivesMonthlySubmissionReminder]', false);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        \Zenstruck\Foundry\Persistence\refresh($target);
        self::assertSame(SupportedLocales::GERMAN, $target->getLocale());
        self::assertFalse($target->receivesMonthlySubmissionReminder());
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

    private function selectChoice(Form $form, string $name, string $value): void
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->select($value);
    }
}
