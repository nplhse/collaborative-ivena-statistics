<?php

declare(strict_types=1);

namespace App\Tests\Support\Security;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait InteractsWithAuthenticatedUser
{
    protected function createClientAsRoleUser(): KernelBrowser
    {
        $client = static::createClient();
        $this->loginAsRoleUser($client);

        return $client;
    }

    protected function createClientAsAreaUser(): KernelBrowser
    {
        $client = static::createClient();
        $areaUser = UserFactory::createOne(['username' => 'area-user'])->_real();
        $client->loginUser($areaUser);

        return $client;
    }

    protected function loginAsRoleUser(KernelBrowser $client): User
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $client->loginUser($user);

        return $user;
    }
}
