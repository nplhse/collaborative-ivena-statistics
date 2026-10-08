<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfilePackedMembersParser
{
    /**
     * @return list<ClosureProfilePackedMember>
     */
    public function parse(string $packed): array
    {
        $members = [];
        foreach (explode("\x1e", $packed) as $member) {
            if ('' === $member) {
                continue;
            }
            $parts = explode("\x1f", $member, 4);
            if (4 !== \count($parts)) {
                continue;
            }
            $members[] = new ClosureProfilePackedMember(
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3],
            );
        }

        return $members;
    }
}
