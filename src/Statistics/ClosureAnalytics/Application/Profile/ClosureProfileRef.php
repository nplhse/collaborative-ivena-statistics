<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileRef
{
    public function __construct(
        public ClosureProfileKind $kind,
        public int $hospitalId,
        public string $key,
    ) {
        $scopeWide = 0 === $this->hospitalId && \in_array($this->kind, [ClosureProfileKind::Speciality, ClosureProfileKind::Department], true);
        if ($this->hospitalId < 1 && !$scopeWide) {
            throw new \InvalidArgumentException('Hospital id must be positive.');
        }
        if (\in_array($this->kind, [ClosureProfileKind::Speciality, ClosureProfileKind::Department, ClosureProfileKind::Hospital], true) && !ctype_digit($this->key)) {
            throw new \InvalidArgumentException('Profile id must be numeric.');
        }
        if (ClosureProfileKind::Hospital === $this->kind && (int) $this->key !== $this->hospitalId) {
            throw new \InvalidArgumentException('Hospital profile key must match the hospital id.');
        }
        if (ClosureProfileKind::ClosureUnit === $this->kind && '' === $this->key) {
            throw new \InvalidArgumentException('Closure unit must not be empty.');
        }
        if (ClosureProfileKind::Group === $this->kind && 1 !== preg_match('/^[a-f0-9]{32}$/', $this->key)) {
            throw new \InvalidArgumentException('Group key must be an md5 hex string.');
        }
    }

    public static function hospital(int $hospitalId): self
    {
        return new self(ClosureProfileKind::Hospital, $hospitalId, (string) $hospitalId);
    }

    public static function speciality(int $hospitalId, int $specialityId): self
    {
        return new self(ClosureProfileKind::Speciality, $hospitalId, (string) $specialityId);
    }

    public static function department(int $hospitalId, int $departmentId): self
    {
        return new self(ClosureProfileKind::Department, $hospitalId, (string) $departmentId);
    }

    public static function closureUnit(int $hospitalId, string $unit): self
    {
        return new self(ClosureProfileKind::ClosureUnit, $hospitalId, $unit);
    }

    public static function group(int $hospitalId, string $profileKey): self
    {
        return new self(ClosureProfileKind::Group, $hospitalId, $profileKey);
    }

    public static function fromQuery(?string $value): ?self
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $parts = explode(':', $value, 3);
        if ('hospital' === $parts[0] && isset($parts[1]) && ctype_digit($parts[1]) && (int) $parts[1] > 0) {
            return self::hospital((int) $parts[1]);
        }
        if (\count($parts) < 3 || !ctype_digit($parts[1])) {
            return null;
        }
        $hospitalId = (int) $parts[1];
        $key = $parts[2];
        if ('speciality' === $parts[0] && ctype_digit($key) && $hospitalId >= 0) {
            return self::speciality($hospitalId, (int) $key);
        }
        if ('department' === $parts[0] && ctype_digit($key) && $hospitalId >= 0) {
            return self::department($hospitalId, (int) $key);
        }
        if ('group' === $parts[0] && $hospitalId > 0 && 1 === preg_match('/^[a-f0-9]{32}$/', $key)) {
            return self::group($hospitalId, $key);
        }
        if ('closure_unit' === $parts[0] && $hospitalId > 0) {
            $unit = rawurldecode($key);
            if ('' === $unit) {
                return null;
            }

            return self::closureUnit($hospitalId, $unit);
        }

        return null;
    }

    public function toQuery(): string
    {
        if (ClosureProfileKind::Hospital === $this->kind) {
            return $this->kind->value.':'.$this->hospitalId;
        }
        $key = ClosureProfileKind::ClosureUnit === $this->kind ? rawurlencode($this->key) : $this->key;

        return $this->kind->value.':'.$this->hospitalId.':'.$key;
    }

    public function specialityId(): int
    {
        if (ClosureProfileKind::Speciality !== $this->kind) {
            throw new \LogicException('Only a speciality profile has a speciality id.');
        }

        return (int) $this->key;
    }
}
