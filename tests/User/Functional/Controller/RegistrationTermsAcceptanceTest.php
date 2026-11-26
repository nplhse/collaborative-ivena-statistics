<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Adapter\DoctrineTermsDocumentProvider;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/** Registration stores the acceptance of the terms with version, language and content hash (#1564, ADR 043). */
#[ResetDatabase]
final class RegistrationTermsAcceptanceTest extends WebTestCase
{
    use Factories;

    private const array CONTENT = [
        ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Terms of use, version 2026-12</p>']],
    ];

    public function testRegistrationStoresTheAcceptanceOfTheConfiguredVersionAndThePublishedText(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(TermsVersion::class, new TermsVersion('2026-12'));
        $page = PageFactory::new()->withoutDefaultTranslation()->create(['key' => PageKey::Terms]);
        PageTranslationFactory::createOne(['page' => $page, 'locale' => 'en', 'content' => self::CONTENT]);

        $this->register($client, 'terms-registrant');

        $acceptance = $this->onlyAcceptanceOf('terms-registrant');
        self::assertSame('2026-12', $acceptance->getTermsVersion());
        self::assertSame('en', $acceptance->getLocale());
        self::assertSame(DoctrineTermsDocumentProvider::contentHash(self::CONTENT), $acceptance->getContentHash());
    }

    public function testWithoutVersionAndTermsPageTheAcceptanceIsStoredWithoutBoth(): void
    {
        $client = self::createClient();

        $this->register($client, 'unversioned-registrant');

        $acceptance = $this->onlyAcceptanceOf('unversioned-registrant');
        self::assertNull($acceptance->getTermsVersion());
        self::assertNull($acceptance->getContentHash());
        self::assertSame('en', $acceptance->getLocale());
    }

    public function testNoAcceptanceIsStoredWhenTheTermsAreNotAccepted(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Register', [
            'registration_form[username]' => 'no-terms-registrant',
            'registration_form[email]' => 'no-terms-registrant@example.test',
            'registration_form[plainPassword]' => 'super-secret-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(TermsAcceptanceRepository::class)->count([]));
    }

    private function register(KernelBrowser $client, string $username): void
    {
        $client->setServerParameter('HTTP_Accept-Language', 'en-US,en;q=0.9');
        $client->request(Request::METHOD_GET, '/register');
        self::assertResponseIsSuccessful();

        $client->submitForm('Register', [
            'registration_form[username]' => $username,
            'registration_form[email]' => $username.'@example.test',
            'registration_form[plainPassword]' => 'super-secret-password',
            'registration_form[acceptTerms]' => true,
        ]);
        self::assertResponseRedirects('/register/check-email');
    }

    private function onlyAcceptanceOf(string $username): TermsAcceptance
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        $acceptances = self::getContainer()->get(TermsAcceptanceRepository::class)->findBy(['user' => $user]);
        self::assertCount(1, $acceptances);

        return $acceptances[0];
    }
}
