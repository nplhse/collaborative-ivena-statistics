<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Functional;

use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class FeedbackSubmitControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use InteractsWithAuthenticatedUser;
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testGuestCanSubmitFeedback(): void
    {
        $client = self::createClient();
        $this->acceptEssentialCookiesOnly($client);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $token = $this->csrfTokenFromFeedbackForm($client);
        self::assertNotSame('', $token);

        $this->submitFeedbackPost($client, [
            '_token' => $token,
            '_redirect_target' => '/',
            'guestEmail' => 'alpha-tester@example.test',
            'category' => 'bug',
            'message' => 'Something broke on the dashboard.',
            'extraContext' => '',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorExists('.alert-success');

        /** @var FeedbackRepository $repo */
        $repo = self::getContainer()->get(FeedbackRepository::class);
        self::assertSame(1, $repo->count([]));
    }

    public function testGuestWithoutEmailGetsValidationFlash(): void
    {
        $client = self::createClient();
        $this->acceptEssentialCookiesOnly($client);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $token = $this->csrfTokenFromFeedbackForm($client);

        $this->submitFeedbackPost($client, [
            '_token' => $token,
            '_redirect_target' => '/',
            'guestEmail' => '',
            'category' => 'question',
            'message' => 'Needs an email for guests.',
            'extraContext' => '',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        $repo = self::getContainer()->get(FeedbackRepository::class);
        self::assertSame(0, $repo->count([]));
    }

    public function testSubmitRedirectsBackToPathWithQueryAndStoresContext(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->acceptEssentialCookiesOnly($client);
        $client->followRedirects(false);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/explore/hospital?search=clinic');
        self::assertResponseIsSuccessful();

        $token = $this->csrfTokenFromFeedbackForm($client);
        $target = '/explore/hospital?search=clinic';

        $this->submitFeedbackPost($client, [
            '_token' => $token,
            '_redirect_target' => $target,
            '_source_route' => 'app_explore_hospital_list',
            '_source_route_params' => '{}',
            'category' => 'bug',
            'message' => 'List filters break after refresh.',
            'extraContext' => '',
        ]);

        self::assertResponseRedirects($target);

        /** @var FeedbackRepository $repo */
        $repo = self::getContainer()->get(FeedbackRepository::class);
        $feedback = $repo->findOneBy(['message' => 'List filters break after refresh.']);
        self::assertNotNull($feedback);
        self::assertSame('app_explore_hospital_list', $feedback->getRouteName());
        self::assertStringEndsWith($target, $feedback->getPageUrl());
        self::assertSame('/explore/hospital', $feedback->getPagePath());
    }

    public function testAuthenticatedUserCanSubmitWithoutGuestEmail(): void
    {
        UserFactory::new([
            'email' => 'participant@example.test',
            'isVerified' => true,
            'username' => 'participant-user',
        ])->create();

        $browser = $this->loginWithConsent($this->browser(), 'participant-user');

        /** @var KernelBrowser $client */
        $client = $browser->client();
        $client->followRedirects(false);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $client->getCrawler()->filter('input[name="feedback_submit[guestEmail]"]'));

        $token = $this->csrfTokenFromFeedbackForm($client);

        $this->submitFeedbackPost($client, [
            '_token' => $token,
            '_redirect_target' => '/',
            'category' => 'improvement',
            'message' => 'Please improve the charts.',
            'extraContext' => '',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();

        $repo = self::getContainer()->get(FeedbackRepository::class);
        self::assertSame(1, $repo->count([]));
    }

    /**
     * @param array<string, string> $fields keys without feedback_submit prefix (e.g. _token, category, …)
     */
    private function submitFeedbackPost(KernelBrowser $client, array $fields): void
    {
        $formNode = $client->getCrawler()->filter('#feedbackOffcanvas form')->first();
        self::assertGreaterThan(0, $formNode->count(), 'Feedback form not found in rendered page.');

        $values = [];
        foreach ($fields as $name => $value) {
            $values[\sprintf('feedback_submit[%s]', $name)] = $value;
        }

        $form = $formNode->form($values);
        $client->submit($form);
    }

    private function csrfTokenFromFeedbackForm(KernelBrowser $client): string
    {
        $n = $client->getCrawler()->filter('input[name="feedback_submit[_token]"]')->first();
        if (0 === $n->count()) {
            self::fail('feedback_submit[_token] not found.');
        }

        return (string) $n->attr('value');
    }

    private function acceptEssentialCookiesOnly(KernelBrowser $client): void
    {
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $crawler = $client->getCrawler();
        $hasBannerButton = $crawler->filter('form[action="/cookies/banner"] button[name="cookie_consent_banner[essential]"]')->count() > 0;

        if ($hasBannerButton) {
            $form = $crawler->selectButton('Essential Cookies Only')->form();
            $client->submit($form);
        }
    }
}
