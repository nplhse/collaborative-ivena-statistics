<?php

declare(strict_types=1);

namespace App\Content\Domain\Validation\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconRendererInterface;

final class KnownIconValidator extends ConstraintValidator
{
    /** Icon names as the content blocks use them, e.g. tabler:info-circle. */
    private const string ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*:[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly IconRendererInterface $iconRenderer,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof KnownIcon) {
            throw new UnexpectedTypeException($constraint, KnownIcon::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (1 !== preg_match(self::ICON_PATTERN, $value)) {
            $this->context->buildViolation($constraint->formatMessage)->addViolation();

            return;
        }

        try {
            $this->iconRenderer->renderIcon($value);
        } catch (IconNotFoundException) {
            $this->context->buildViolation($constraint->unknownMessage)->setParameter('{icon}', $value)->addViolation();
        }
    }
}
