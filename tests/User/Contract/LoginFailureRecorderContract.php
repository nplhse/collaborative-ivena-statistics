<?php

declare(strict_types=1);

namespace App\Tests\User\Contract;

use App\User\Application\Contract\LoginFailureRecorderInterface;
use App\User\Domain\Enum\LoginFailureKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Expectations every LoginFailureRecorderInterface meets. The fake runs them as a unit test, LoginFailureRecorder
 * against PostgreSQL (testing.md, fakes and the database). Extends KernelTestCase for the integration subclass; the unit
 * subclass never boots the kernel.
 */
abstract class LoginFailureRecorderContract extends KernelTestCase
{
    public function testEveryCallIsOneRecordOfItsKind(): void
    {
        $recorder = $this->recorder();

        $recorder->record(LoginFailureKind::Failure, 'alice', '192.0.2.1');
        $recorder->record(LoginFailureKind::Refused, 'alice', '192.0.2.1');

        self::assertSame(['failure', 'refused'], $this->recordedKinds());
    }

    public function testEqualIdentifiersInAnotherCaseAreRecordedAlike(): void
    {
        $recorder = $this->recorder();

        $recorder->record(LoginFailureKind::Failure, 'alice', '192.0.2.1');
        $recorder->record(LoginFailureKind::Failure, ' Alice ', '192.0.2.2');

        [$first, $second] = $this->recordedIdentifiers();
        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    public function testAnEmptyIdentifierIsRecordedAsUnknown(): void
    {
        $recorder = $this->recorder();

        $recorder->record(LoginFailureKind::Failure, '', null);

        self::assertSame([null], $this->recordedIdentifiers());
    }

    abstract protected function recorder(): LoginFailureRecorderInterface;

    /** @return list<string> */
    abstract protected function recordedKinds(): array;

    /** @return list<?string> in the form the implementation keeps them */
    abstract protected function recordedIdentifiers(): array;
}
