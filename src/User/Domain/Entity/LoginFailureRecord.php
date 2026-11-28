<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use App\User\Domain\Enum\LoginFailureKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One sign-in failure (#1487), kept for LoginFailureDaily::EVENT_RETENTION_DAYS and folded into LoginFailureDaily.
 *
 * Username and IP are stored only as keyed hashes (HMAC-SHA256 with the app secret): equal values can be counted and
 * compared, but not read back. Written with DBAL by `LoginFailureRecorder`; the entity only maps the table.
 *
 * @psalm-suppress UnusedClass Maps the table for the schema; rows are written and read with DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'login_failure_event')]
#[ORM\Index(name: 'idx_login_failure_event_occurred_at', columns: ['occurred_at'])]
class LoginFailureRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 32, enumType: LoginFailureKind::class)]
        private LoginFailureKind $kind,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $identifierHash,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $ipHash,
    ) {
    }
}
