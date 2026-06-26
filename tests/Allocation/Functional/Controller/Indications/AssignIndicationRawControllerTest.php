<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AssignIndicationRawControllerTest extends WebTestCase
{
    use Factories;

    public function testAssignRedirectsToReview(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        $raw = IndicationRawFactory::createOne();
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/assign/%d', $raw->getId()));

        self::assertResponseRedirects(sprintf('/explore/indication/raw/review/%d', $raw->getId()));
    }
}
