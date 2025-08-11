<?php

namespace App\Tests\Integration\Controller\Data;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

class DataControllerTest extends WebTestCase
{
    use HasBrowser;

    public function testUsersGetRedirected(): void
    {
        // Act& Assert
        $this->browser()
            ->interceptRedirects()
            ->visit('/data')
            ->assertRedirectedTo('/data/area')
            ->assertSuccessful()
        ;
    }
}
