<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports createFromFormat() and date_create(_immutable)_from_format() with a constant format that neither starts with '!'
 * nor ends with '|'. Without one of them, every field the format does not mention is taken from the current time.
 * Formats that are not constant are not reported, like in the other wall clock rules.
 *
 * @implements Rule<CallLike>
 */
final readonly class NoWallClockCreateFromFormatRule implements Rule
{
    private const array FUNCTIONS = ['date_create_from_format', 'date_create_immutable_from_format'];

    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable()) {
            return [];
        }

        $callee = match (true) {
            $node instanceof StaticCall => $this->describeStaticCall($node, $scope),
            $node instanceof FuncCall => $this->describeFunctionCall($node, $scope),
            default => null,
        };

        if (null === $callee) {
            return [];
        }

        $format = $this->findFormatWithoutReset($node, $scope);
        if (null === $format) {
            return [];
        }

        return [
            RuleErrorBuilder::message(WallClockMessage::message(sprintf('%s(%s)', $callee, var_export($format, true))))
                ->identifier(WallClockMessage::IDENTIFIER_CREATE_FROM_FORMAT)
                ->tip(WallClockMessage::createFromFormatTip())
                ->build(),
        ];
    }

    private function describeStaticCall(StaticCall $call, Scope $scope): ?string
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            return null;
        }

        if (0 !== strcasecmp($call->name->toString(), 'createFromFormat')) {
            return null;
        }

        $className = $scope->resolveName($call->class);
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($className);
        if (!$class->implementsInterface(\DateTimeInterface::class)) {
            return null;
        }

        return sprintf('\%s::createFromFormat', $class->getName());
    }

    private function describeFunctionCall(FuncCall $call, Scope $scope): ?string
    {
        if (!$call->name instanceof Name || !$this->reflectionProvider->hasFunction($call->name, $scope)) {
            return null;
        }

        $function = strtolower($this->reflectionProvider->getFunction($call->name, $scope)->getName());

        return \in_array($function, self::FUNCTIONS, true) ? $function : null;
    }

    /**
     * Returns the first constant format that fills missing fields from the clock, null when there is none.
     */
    private function findFormatWithoutReset(CallLike $call, Scope $scope): ?string
    {
        $arg = WallClockArguments::find($call, 0, 'format');
        if (!$arg instanceof Arg || $arg->unpack) {
            return null;
        }

        foreach ($scope->getType($arg->value)->getConstantStrings() as $string) {
            $format = $string->getValue();
            if (!str_starts_with($format, '!') && !str_ends_with($format, '|')) {
                return $format;
            }
        }

        return null;
    }
}
