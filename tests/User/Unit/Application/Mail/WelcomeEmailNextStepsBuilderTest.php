<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Mail;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Application\Mail\WelcomeEmailNextStepsBuilder;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class WelcomeEmailNextStepsBuilderTest extends TestCase
{
    public function testBuildForUserWithoutOwnedHospitalsOmitsImport(): void
    {
        $steps = new WelcomeEmailNextStepsBuilder()->buildForUser(new User());

        self::assertSame(
            [
                'app_explore_hospital_list',
                'app_explore_index',
                'app_stats_dashboard',
            ],
            array_map(static fn (\App\User\Application\Mail\WelcomeEmailNextStep $step): string => $step->route, $steps),
        );
        self::assertSame('email.welcome.next_steps.hospitals.all.title', $steps[0]->titleKey);
    }

    public function testBuildForUserWithOwnedHospitalsIncludesImport(): void
    {
        $user = new User();
        $user->addHospital(new Hospital()->setName('Test Hospital'));

        $steps = new WelcomeEmailNextStepsBuilder()->buildForUser($user);

        self::assertSame(
            [
                'app_hospitals_index',
                'app_explore_index',
                'app_stats_dashboard',
                'app_import_new',
            ],
            array_map(static fn (\App\User\Application\Mail\WelcomeEmailNextStep $step): string => $step->route, $steps),
        );
        self::assertSame('email.welcome.next_steps.hospitals.owned.title', $steps[0]->titleKey);
        self::assertSame('email.welcome.next_steps.import.title', $steps[3]->titleKey);
    }
}
