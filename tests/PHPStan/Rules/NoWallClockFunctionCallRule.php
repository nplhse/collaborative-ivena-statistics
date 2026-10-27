<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports date functions that fall back to the current time: time(), date() without a timestamp,
 * strtotime() with a relative string and no base timestamp, mktime() without a year and date_create() with a relative string.
 * microtime() and hrtime() stay allowed, because they measure durations.
 *
 * @implements Rule<FuncCall>
 */
final readonly class NoWallClockFunctionCallRule implements Rule
{
    /**
     * Function name => [position, name] of the argument whose absence makes the call read the clock.
     * null means the function always reads the clock.
     */
    private const array TIMESTAMP_ARGUMENTS = [
        'time' => null,
        'date' => [1, 'timestamp'],
        'gmdate' => [1, 'timestamp'],
        'idate' => [1, 'timestamp'],
        'getdate' => [0, 'timestamp'],
        'localtime' => [0, 'timestamp'],
        'mktime' => [5, 'year'],
        'gmmktime' => [5, 'year'],
    ];

    private const array DATE_CREATE_FUNCTIONS = ['date_create', 'date_create_immutable'];

    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || $node->isFirstClassCallable()) {
            return [];
        }

        if (!$this->reflectionProvider->hasFunction($node->name, $scope)) {
            return [];
        }

        $function = strtolower($this->reflectionProvider->getFunction($node->name, $scope)->getName());

        if (\in_array($function, self::DATE_CREATE_FUNCTIONS, true)) {
            $expression = WallClockArguments::describeFirstDateArgument($node, $scope, 'datetime');

            return null === $expression ? [] : [$this->error(sprintf('%s(%s)', $function, $expression), WallClockMessage::IDENTIFIER_DATE_TIME)];
        }

        if ('strtotime' === $function) {
            if (WallClockArguments::has($node, 1, 'baseTimestamp')) {
                return [];
            }

            $expression = WallClockArguments::describeFirstDateArgument($node, $scope, 'datetime');

            return null === $expression ? [] : [$this->error(sprintf('strtotime(%s)', $expression), WallClockMessage::IDENTIFIER_FUNCTION)];
        }

        if (!\array_key_exists($function, self::TIMESTAMP_ARGUMENTS)) {
            return [];
        }

        $timestamp = self::TIMESTAMP_ARGUMENTS[$function];
        if (null !== $timestamp && WallClockArguments::has($node, $timestamp[0], $timestamp[1])) {
            return [];
        }

        return [$this->error(
            null === $timestamp ? $function.'()' : sprintf('%s() without $%s', $function, $timestamp[1]),
            WallClockMessage::IDENTIFIER_FUNCTION,
        )];
    }

    private function error(string $expression, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder::message(WallClockMessage::message($expression))
            ->identifier($identifier)
            ->tip(WallClockMessage::tip())
            ->build();
    }
}
