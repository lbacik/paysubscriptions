<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\ExpenseCategory as ExpenseCategoryDto;
use App\ApiResource\Subscription as SubscriptionDto;
use App\Entity\ExpenseCategory;
use App\Entity\Subscription as SubscriptionEntity;
use App\Entity\User;
use App\Exception\CategoryOwnershipException;
use App\Exception\CurrencyConflictException;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\CurrencyService;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Writes API v1 Subscriptions through SubscriptionService, so the API keeps
 * the web app's rules: server-controlled owner/UUID/timestamps, the
 * default-category selection for an omitted category, confirmed-main-currency
 * defaulting with manual converted amounts, legacy no-main-currency
 * behavior, and the per-User Subscription limit (409 on conflict, including
 * a lost race under concurrent creates).
 *
 * The processor reads the input DTO (never the mapped entity): the entity
 * has already been overwritten by the object mapper, so only the DTO still
 * shows the client's intent — the supplied PATCH members and the write-only
 * confirmConvertedFor. The mapped entity is what gets persisted and mapped
 * back to the DTO for the response.
 *
 * PATCH is atomic: the mapped entity is managed, so every refusal refreshes
 * it from the database and nothing is left for a later flush to write. The
 * limit gate closes the EntityManager on refusal, which is safe because the
 * request ends there.
 *
 * @implements ProcessorInterface<SubscriptionEntity, SubscriptionEntity|null>
 */
final class SubscriptionProcessor implements ProcessorInterface
{
    /**
     * PATCH members the API accepts. Read-only members (id, convertedCurrency,
     * pendingReview, createdAt, updatedAt) and unknown members never count:
     * a body of only those is an empty PATCH and is refused like `{}`.
     */
    private const PATCHABLE_FIELDS = [
        'name',
        'billingCycle',
        'amount',
        'nextPayment',
        'currency',
        'convertedAmount',
        'confirmConvertedFor',
        'category',
        'notes',
    ];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionService $service,
        private readonly ExpenseCategoryRepository $categories,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?SubscriptionEntity
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);

        if ($operation instanceof DeleteOperationInterface) {
            // The entity the scoped provider read, kept by API Platform.
            $subscription = $request->attributes->get('mapped_data');
            \assert($subscription instanceof SubscriptionEntity);

            $this->subscriptions->remove($subscription);

            return null;
        }

        \assert($data instanceof SubscriptionEntity);
        $dto = $request->attributes->get('data');
        \assert($dto instanceof SubscriptionDto);

        $user = $this->security->getUser();
        \assert($user instanceof User);

        if ($operation instanceof Post) {
            return $this->create($data, $dto, $user);
        }

        return $this->patch($data, $dto, $user, $request);
    }

    private function create(SubscriptionEntity $subscription, SubscriptionDto $dto, User $user): SubscriptionEntity
    {
        $subscription->setOwner($user);
        $mainCurrency = $user->getMainCurrency();

        // Invalid data stays 422 even when the limit is also reached: every
        // check below runs before the limit gate in service->add().
        if ($subscription->getCurrency() === null && CurrencyService::normalizeCode($mainCurrency) !== null) {
            $subscription->setCurrency($mainCurrency);
        }

        $this->assertConvertedValid($subscription, $mainCurrency);

        if (null !== $dto->category) {
            $subscription->setCategory($this->managedCategory($dto->category, $user));
        }

        // An omitted category stays null so the service applies the
        // default-category rule on save.
        $this->service->add($subscription);

        return $subscription;
    }

    private function patch(SubscriptionEntity $subscription, SubscriptionDto $dto, User $user, Request $request): SubscriptionEntity
    {
        $previous = $request->attributes->get('previous_data');
        \assert($previous instanceof SubscriptionDto);

        $mainCurrency = $user->getMainCurrency();
        $supplied = $this->suppliedMembers($request);

        if ([] === array_intersect($supplied, self::PATCHABLE_FIELDS)) {
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation(
                    'No updatable fields supplied. Send any of "name", "billingCycle", "amount", "nextPayment", "currency", "convertedAmount", "confirmConvertedFor", "category" or "notes".',
                    null,
                    [],
                    null,
                    '',
                    null,
                ),
            ]));
        }

        $violations = [];

        // An explicitly cleared currency is only structural when no main
        // currency is confirmed; otherwise the Subscription would lose a
        // required value, so it is rejected before the service would default
        // it back.
        if (\in_array('currency', $supplied, true) && null === $dto->currency
            && CurrencyService::normalizeCode($mainCurrency) !== null
        ) {
            $violations[] = $this->violation('currency', 'This value should not be null.');
        }

        if (\in_array('category', $supplied, true) && null === $dto->category) {
            $violations[] = $this->violation('category', 'The selected category is invalid.');
        }

        $hasConverted = \in_array('convertedAmount', $supplied, true);
        $convertedCleared = $hasConverted && null === $dto->convertedAmount;
        $hasConfirm = \in_array('confirmConvertedFor', $supplied, true) && null !== $dto->confirmConvertedFor;

        if ($hasConfirm && !$hasConverted) {
            $violations[] = $this->violation('confirmConvertedFor', 'Confirming a converted amount requires convertedAmount.');
        } elseif ($hasConfirm && $convertedCleared) {
            $violations[] = $this->violation('confirmConvertedFor', 'A cleared converted amount cannot be confirmed.');
        } elseif ($hasConfirm && CurrencyService::normalizeCode($mainCurrency) === null) {
            $violations[] = $this->violation('confirmConvertedFor', 'There is no confirmed main currency to review against.');
        }

        $confirmInvalid = false;
        foreach ($violations as $violation) {
            if ('confirmConvertedFor' === $violation->getPropertyPath()) {
                $confirmInvalid = true;

                break;
            }
        }

        // A structurally valid but mismatched confirmation is a conflict, not
        // a validation error: the client reviewed the figure against the wrong
        // main currency, so nothing is applied — even when other fields are
        // also invalid.
        if ($hasConfirm && !$confirmInvalid && CurrencyService::normalizeCode($mainCurrency) !== null
            && CurrencyService::normalizeCode((string) $dto->confirmConvertedFor) !== CurrencyService::normalizeCode($mainCurrency)
        ) {
            $this->refresh($subscription);

            throw new CurrencyConflictException(sprintf(
                'The converted amount was confirmed for %s, but your current main currency is %s.',
                CurrencyService::normalizeCode((string) $dto->confirmConvertedFor),
                CurrencyService::normalizeCode($mainCurrency),
            ));
        }

        if ([] !== $violations) {
            $this->refresh($subscription);

            throw new ValidationException(new ConstraintViolationList($violations));
        }

        if (\in_array('category', $supplied, true) && null !== $dto->category) {
            try {
                $subscription->setCategory($this->managedCategory($dto->category, $user));
            } catch (CategoryOwnershipException $exception) {
                $this->refresh($subscription);

                throw $exception;
            }
        }

        $this->reconcileConverted($subscription, $previous, $mainCurrency, $hasConfirm && !$confirmInvalid, $convertedCleared);

        // Runs the save-time currency behavior on the patched record, plus the
        // ownership check for a supplied category.
        foreach ($subscription->validateConverted($mainCurrency, true) as $message) {
            $violations[] = $this->violation(self::convertedViolationField($message), $message);
        }

        if ([] !== $violations) {
            $this->refresh($subscription);

            throw new ValidationException(new ConstraintViolationList($violations));
        }

        try {
            $this->service->update($subscription);
        } catch (CategoryOwnershipException $exception) {
            // Category state changed mid-request after the ownership check
            // above: report it as an invalid field without disclosure.
            $this->refresh($subscription);

            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            // Defense-in-depth: the currency rules were already validated
            // above, so this only fires if state changed mid-request.
            $this->refresh($subscription);

            throw $exception;
        }

        return $subscription;
    }

    /**
     * The JSON member names of a merge-patch body. Omitted fields keep their
     * values; explicit nulls are the client's intent to clear.
     *
     * @return list<string>
     */
    private function suppliedMembers(Request $request): array
    {
        try {
            $body = $request->toArray();
        } catch (\JsonException) {
            return [];
        }

        return array_keys($body);
    }

    /**
     * Reconciles converted input after a PATCH: an explicit confirmation
     * against the current main currency reviews even an unchanged figure,
     * while without one the shared web rules apply (a re-entered figure is
     * stamped, a figure kept across a currency change is dropped so it is
     * never reused, and an untouched stale figure keeps its old stamp).
     */
    private function reconcileConverted(
        SubscriptionEntity $subscription,
        SubscriptionDto $previous,
        ?string $mainCurrency,
        bool $forceReview,
        bool $convertedCleared,
    ): void {
        if ($convertedCleared) {
            $subscription->setConvertedAmount(null);
            $subscription->setConvertedCurrency(null);

            return;
        }

        if ($forceReview) {
            $subscription->setConvertedCurrency($mainCurrency);

            return;
        }

        $originalConverted = null !== $previous->convertedAmount ? (float) $previous->convertedAmount : null;
        $subscription->reconcileConverted($previous->currency, $originalConverted, $mainCurrency);
    }

    /**
     * The managed category for a resolved category IRI. IRI resolution
     * already scoped the read to the token User, so a mismatch here only
     * happens when category state changed mid-request: report it as an
     * invalid field without disclosure, never as a conflict.
     */
    private function managedCategory(ExpenseCategoryDto $categoryDto, User $user): ExpenseCategory
    {
        $category = $this->categories->find($categoryDto->id);
        if (null === $category || !$category->isOwnedBy($user)) {
            throw new CategoryOwnershipException('The selected category does not belong to this account.');
        }

        return $category;
    }

    /**
     * The save-time currency behavior, checked before the service persists so
     * invalid data stays 422 even when the per-User limit is also reached.
     */
    private function assertConvertedValid(SubscriptionEntity $subscription, ?string $mainCurrency): void
    {
        $violations = [];
        foreach ($subscription->validateConverted($mainCurrency, true) as $message) {
            $violations[] = $this->violation(self::convertedViolationField($message), $message);
        }

        if ([] !== $violations) {
            throw new ValidationException(new ConstraintViolationList($violations));
        }
    }

    private static function convertedViolationField(string $message): string
    {
        if (str_contains($message, 'select a currency') || str_starts_with($message, 'Currency "')) {
            return 'currency';
        }

        return 'convertedAmount';
    }

    private function violation(string $field, string $message): ConstraintViolation
    {
        return new ConstraintViolation($message, null, [], null, $field, null);
    }

    private function refresh(SubscriptionEntity $subscription): void
    {
        if ($this->entityManager->isOpen() && $this->entityManager->contains($subscription)) {
            $this->entityManager->refresh($subscription);
        }
    }
}
