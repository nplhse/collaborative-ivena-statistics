<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail\Messenger;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Permanent SMTP 5xx responses (e.g. 554 spam rejection) must not be retried.
 *
 * @psalm-suppress UnusedClass Wired via messenger.bus.default middleware.
 */
final readonly class SkipPermanentSmtpRetryMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $smtp = $this->findPermanentSmtpException($exception);
            if ($smtp instanceof UnexpectedResponseException) {
                throw new UnrecoverableMessageHandlingException(sprintf('Permanent SMTP rejection (code %d); not retrying.', $smtp->getCode()), $smtp->getCode(), $exception);
            }

            throw $exception;
        }
    }

    private function findPermanentSmtpException(\Throwable $exception): ?UnexpectedResponseException
    {
        foreach ($this->flattenExceptions($exception) as $candidate) {
            if (!$candidate instanceof UnexpectedResponseException) {
                continue;
            }

            $code = $candidate->getCode();
            if ($code >= 500 && $code < 600) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<\Throwable>
     */
    private function flattenExceptions(\Throwable $exception): array
    {
        $found = [];
        $queue = [$exception];

        while ([] !== $queue) {
            $current = array_shift($queue);
            $found[] = $current;

            if ($current instanceof HandlerFailedException) {
                foreach ($current->getWrappedExceptions() as $wrapped) {
                    $queue[] = $wrapped;
                }
            }

            $previous = $current->getPrevious();
            if (null !== $previous) {
                $queue[] = $previous;
            }
        }

        return $found;
    }
}
