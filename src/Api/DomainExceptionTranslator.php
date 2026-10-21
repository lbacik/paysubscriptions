<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Subscription as SubscriptionResource;
use App\Exception\CategoryInUseException;
use App\Exception\CategoryOwnershipException;
use App\Exception\CurrencyConflictException;
use App\Exception\DuplicateCategoryNameException;
use App\Exception\SubscriptionLimitReachedException;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The single place where domain exceptions become API v1 errors: a stable
 * `/problems/*` type or a 422 field violation. Services stay free of API
 * Platform and processors let domain exceptions propagate instead of each
 * repeating the mapping.
 *
 * Runs before API Platform's exception listener (priority -96) and only for
 * API Platform operations; web controllers keep catching domain exceptions
 * themselves.
 */
final class DomainExceptionTranslator
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getRequest()->attributes->has('_api_operation')) {
            return;
        }

        $operation = $event->getRequest()->attributes->get('_api_operation');
        \assert(null === $operation || $operation instanceof Operation);

        $translated = $this->translate($event->getThrowable(), $operation);
        if (null !== $translated) {
            $event->setThrowable($translated);
        }
    }

    private function translate(\Throwable $exception, ?Operation $operation): ?\Throwable
    {
        // A foreign, unknown or malformed category reference is an invalid
        // field without disclosure, never a conflict.
        if ($exception instanceof CategoryOwnershipException) {
            return new ValidationException(
                new ConstraintViolationList([
                    new ConstraintViolation(
                        'The selected category is invalid.',
                        null,
                        [],
                        null,
                        'category',
                        null,
                    ),
                ]),
                null,
                $exception,
            );
        }

        if ($exception instanceof SubscriptionLimitReachedException) {
            return new ProblemException(
                '/problems/subscription-limit-reached',
                'Subscription limit reached',
                409,
                $exception->getMessage(),
                $exception,
            );
        }

        if ($exception instanceof CurrencyConflictException) {
            return new ProblemException(
                '/problems/currency-conflict',
                'Converted amount currency conflict',
                409,
                $exception->getMessage(),
                $exception,
            );
        }

        // The currency rules already ran in the Subscription processor, so
        // this only fires if state changed mid-request: still a 422 on the
        // converted-amount fields, scoped to Subscription operations so other
        // resources' InvalidArgumentExceptions keep their default mapping.
        if ($exception instanceof \InvalidArgumentException
            && $operation instanceof Operation
            && SubscriptionResource::class === $operation->getClass()
        ) {
            return new ValidationException(
                new ConstraintViolationList([
                    new ConstraintViolation(
                        $exception->getMessage(),
                        null,
                        [],
                        null,
                        self::convertedViolationField($exception->getMessage()),
                        null,
                    ),
                ]),
                null,
                $exception,
            );
        }

        return match (true) {
            $exception instanceof CategoryInUseException => new ProblemException(
                '/problems/category-in-use',
                'Expense category in use',
                409,
                $exception->getMessage(),
                $exception,
            ),
            // The service message is the single wording for a taken name;
            // the UniqueEntity code gives the violation a stable type.
            $exception instanceof DuplicateCategoryNameException => new ValidationException(
                new ConstraintViolationList([
                    new ConstraintViolation($exception->getMessage(), null, [], null, 'name', null, null, UniqueEntity::NOT_UNIQUE_ERROR),
                ]),
                null,
                $exception,
            ),
            default => null,
        };
    }

    private static function convertedViolationField(string $message): string
    {
        if (str_contains($message, 'select a currency') || str_starts_with($message, 'Currency "')) {
            return 'currency';
        }

        return 'convertedAmount';
    }
}
