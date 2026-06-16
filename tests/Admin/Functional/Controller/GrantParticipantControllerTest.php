<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Admin\Application\Service\GrantParticipantUrlGenerator;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GrantParticipantControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;

    public function testAdminCanGrantParticipantRoleViaSignedUrl(): void
    {
        $admin = UserFactory::new()
            ->asNotificationRecipient()
            ->create([
                'username' => 'grant-admin-'.bin2hex(random_bytes(4)),
            ]);
        $target = UserFactory::createOne([
            'username' => 'grant-target-'.bin2hex(random_bytes(4)),
            'roles' => [UserRole::USER],
        ]);

        /** @var GrantParticipantUrlGenerator $urlGenerator */
        $urlGenerator = self::getContainer()->get(GrantParticipantUrlGenerator::class);
        $url = $urlGenerator->generate((int) $target->getId());

        $this->browser()
            ->actingAs($admin)
            ->visit($url)
            ->assertSuccessful()
        ;

        $target->_refresh();
        self::assertContains(UserRole::PARTICIPANT, $target->getRoles());
    }

    public function testGrantParticipantIsIdempotent(): void
    {
        $admin = UserFactory::new()
            ->asNotificationRecipient()
            ->create([
                'username' => 'grant-admin-idem-'.bin2hex(random_bytes(4)),
            ]);
        $target = UserFactory::createOne([
            'username' => 'grant-target-idem-'.bin2hex(random_bytes(4)),
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);

        /** @var GrantParticipantUrlGenerator $urlGenerator */
        $urlGenerator = self::getContainer()->get(GrantParticipantUrlGenerator::class);
        $url = $urlGenerator->generate((int) $target->getId());

        $this->browser()
            ->actingAs($admin)
            ->visit($url)
            ->assertSuccessful()
        ;

        $target->_refresh();
        self::assertContains(UserRole::PARTICIPANT, $target->getRoles());
    }

    public function testInvalidSignedUrlIsRejected(): void
    {
        $admin = UserFactory::new()
            ->asNotificationRecipient()
            ->create([
                'username' => 'grant-admin-invalid-'.bin2hex(random_bytes(4)),
            ]);
        $target = UserFactory::createOne([
            'username' => 'grant-target-invalid-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/users/'.$target->getId().'/grant-participant')
            ->assertStatus(403)
        ;
    }

    public function testExpiredSignedUrlIsRejected(): void
    {
        $admin = UserFactory::new()
            ->asNotificationRecipient()
            ->create([
                'username' => 'grant-admin-expired-'.bin2hex(random_bytes(4)),
            ]);
        $target = UserFactory::createOne([
            'username' => 'grant-target-expired-'.bin2hex(random_bytes(4)),
        ]);

        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        /** @var UriSigner $uriSigner */
        $uriSigner = self::getContainer()->get(UriSigner::class);

        $url = $urlGenerator->generate(
            'app_admin_user_grant_participant',
            ['id' => $target->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $expiredUrl = $uriSigner->sign($url.'?expires='.(time() - 60));

        $this->browser()
            ->actingAs($admin)
            ->visit($expiredUrl)
            ->assertStatus(403)
        ;
    }
}
