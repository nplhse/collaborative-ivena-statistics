<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller\User;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Wiring of the sign-in security page (#1487). Counts and the trend are tested on the query and presenter level.
 */
#[ResetDatabase]
final class SignInSecurityControllerTest extends WebTestCase
{
    use Factories;

    public function testAnAdministratorLiftsALockFromThePage(): void
    {
        $client = self::createClient();
        $locked = UserFactory::new(['username' => 'locked'])
            ->afterInstantiate(static fn (User $user) => $user->lockLoginUntil(new \DateTimeImmutable('+3 hours')))
            ->create();
        $client->loginUser(UserFactory::new()->asAdmin()->create(['username' => 'admin']));
        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/sign-in-security');
        $form = $crawler->filter('[data-testid="locked-accounts"]')->selectButton('Lift lock')->form();

        $client->submit($form);

        self::assertResponseRedirects();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $locked->getId())?->getLoginLockedUntil());
    }

    public function testParticipantsCannotOpenThePage(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asParticipant()->create(['username' => 'participant']));

        $client->request(Request::METHOD_GET, '/admin/operations/sign-in-security');

        self::assertResponseStatusCodeSame(403);
    }
}
