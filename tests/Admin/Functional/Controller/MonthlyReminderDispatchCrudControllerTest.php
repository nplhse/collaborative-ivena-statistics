<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MonthlyReminderDispatchCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanViewReadOnlyReminderDispatchList(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'reminder-admin-'.bin2hex(random_bytes(4)),
        ]);
        $hospital = HospitalFactory::createOne();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist(new MonthlyReminderDispatch(
            $hospital,
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
            new \DateTimeImmutable(),
        ));
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/monthly-reminder-dispatch');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2026-06', $client->getResponse()->getContent());
    }

    public function testCreateActionIsDisabled(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/monthly-reminder-dispatch/new');
        self::assertResponseStatusCodeSame(403);
    }
}
