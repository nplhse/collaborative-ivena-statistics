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
        $areaUser = UserFactory::createOne([
            'username' => 'area-user',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $client->loginUser($areaUser);

        return $client;
    }

    protected function loginAsRoleUser(KernelBrowser $client): User
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $client->loginUser($user);

        return $user;
    }

    protected function createClientAsParticipant(): KernelBrowser
    {
        $client = static::createClient();
        $this->loginAsParticipant($client);

        return $client;
    }

    protected function loginAsParticipant(KernelBrowser $client): User
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);

        return $user;
    }
}
