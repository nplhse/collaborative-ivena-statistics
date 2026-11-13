<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class FeedbackCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testListAndDetailEscapeTheMessageAndPath(): void
    {
        $client = $this->loggedInAdmin();
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('<img src="https://tracker.example.test/p.gif"> broken chart')
            ->setPageUrl('https://example.test/<b>bold</b>')
            ->setRouteName('<i>route</i>');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table img'));
        self::assertCount(0, $crawler->filter('table i:not([class])'), 'the route is escaped');
        self::assertStringContainsString('&lt;img src=&quot;https://tracker.example.test/p.gif&quot;&gt; broken chart', (string) $client->getResponse()->getContent());

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback/'.$feedback->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.content-body b, main b'), 'the path is escaped');
    }

    private function loggedInAdmin(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }
}
