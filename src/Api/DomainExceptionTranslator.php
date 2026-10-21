<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Exception\CategoryInUseException;
use App\Exception\DuplicateCategoryNameException;
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

        $translated = $this->translate($event->getThrowable());
        if (null !== $translated) {
            $event->setThrowable($translated);
        }
    }

    private function translate(\Throwable $exception): ?\Throwable
    {
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
}
