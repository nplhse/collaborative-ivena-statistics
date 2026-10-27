<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports `new \DateTimeImmutable()` and `new \DateTime()` without an argument or with a relative
 * constant string such as 'now', 'today' or '-30 days'.
 *
 * @implements Rule<New_>
 */
final class NoWallClockInstantiationRule implements Rule
{
    private const array CLASSES = ['DateTimeImmutable', 'DateTime'];

    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $className = ltrim($scope->resolveName($node->class), '\\');
        $matched = array_find(self::CLASSES, fn ($candidate): bool => 0 === strcasecmp($className, $candidate));

        if (null === $matched) {
            return [];
        }

        $expression = WallClockArguments::describeFirstDateArgument($node, $scope, 'datetime');
        if (null === $expression) {
            return [];
        }

        return [
            RuleErrorBuilder::message(WallClockMessage::message(sprintf('new \%s(%s)', $matched, $expression)))
                ->identifier(WallClockMessage::IDENTIFIER_DATE_TIME)
                ->tip(WallClockMessage::tip())
                ->build(),
        ];
    }
}
