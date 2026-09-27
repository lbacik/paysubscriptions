<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\Problem;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Exception\SubscriptionLimitReachedException;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\CurrencyService;
use App\Service\SubscriptionListState;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Subscription endpoints for API v1: reads (issue #96) plus collection POST,
 * detail PATCH (issue #98), and detail DELETE (issue #97).
 *
 * Collection and detail are scoped to the bearer token User and returned as
 * ordinary `application/json`. Navigation is explicit and stateless: the
 * collection honors `categoryId`, `sort`, `order`, and 1-based `page` query
 * parameters (25 items per page) parsed from the request alone. The web
 * session's list state (SubscriptionListState) is never read or written here.
 * Missing, malformed, or foreign IDs return 404 `application/problem+json`
 * without disclosing another User's data.
 *
 * Writes accept the same editable data as the web experience — name,
 * billingCycle, amount, date-only nextPayment, currency, convertedAmount,
 * notes, and categoryId — and reuse the same service rules: server-controlled
 * owner/UUID/timestamps/convertedCurrency/pendingReview, the default-category
 * selection and creation rule for an omitted category, confirmed-main-currency
 * defaulting with manual converted amounts, legacy no-main-currency behavior,
 * and the per-User Subscription limit (409 on conflict, including a lost race
 * under concurrent creates). An explicitly supplied missing or foreign
 * category is rejected as an invalid field (422) without disclosure.
 * Constraint violations return 422 with field-level `errors`.
 *
 * PATCH changes only supplied fields and is atomic: omitted fields keep their
 * values, optional notes/convertedAmount may be cleared with explicit null
 * only when the patched record stays valid, and required fields, the owner,
 * and the UUID can never be cleared or changed. Converted-amount review
 * follows the web reconciliation (a re-entered figure is stamped, a figure
 * kept across a currency change is dropped, an untouched stale figure keeps
 * its stamp), except that supplying convertedAmount together with the
 * write-only confirmConvertedFor reviews even an unchanged figure: a
 * confirmation matching the current main currency stamps it, a mismatched one
 * returns 409 and applies nothing. confirmConvertedFor is never stored or
 * returned.
 */
#[Route('/api/v1/subscriptions')]
class SubscriptionController extends AbstractController
{
    public const PER_PAGE = 25;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionService $service,
        private readonly ExpenseCategoryRepository $categories,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_v1_subscriptions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                Problem::body(
                    Problem::UNAUTHORIZED,
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED,
                    'Authentication is required to access this resource.',
                ),
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        $categoryId = $request->query->get('categoryId');
        $categoryId = \is_string($categoryId) ? trim($categoryId) : null;
        if ($categoryId === '' || $categoryId === null) {
            $categoryId = null;
        }

        $sort = $request->query->get('sort');
        $sort = \is_string($sort) ? $sort : SubscriptionListState::DEFAULT_SORT;

        $order = $request->query->get('order');
        $order = \is_string($order) ? strtolower($order) : SubscriptionListState::DEFAULT_ORDER;
        if (!\in_array($order, ['asc', 'desc'], true)) {
            $order = SubscriptionListState::DEFAULT_ORDER;
        }

        $page = max(1, (int) $request->query->get('page', 1));

        $mainCurrency = $user->getMainCurrency();

        // Filtering and sorting run on the whole User-owned collection first;
        // pagination slices the ordered result.
        $all = $this->service->get($user, $sort, $order, $categoryId, $mainCurrency);
        $items = \array_slice(array_values($all), ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return new JsonResponse(
            array_map(static fn (Subscription $s): array => self::serialize($s, $mainCurrency), $items),
            Response::HTTP_OK,
        );
    }

    #[Route('/{id}', name: 'api_v1_subscriptions_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                Problem::body(
                    Problem::UNAUTHORIZED,
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED,
                    'Authentication is required to access this resource.',
                ),
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        $subscription = $this->findOwned($user, $id);
        if (null === $subscription) {
            return new JsonResponse(
                Problem::body(
                    Problem::SUBSCRIPTION_NOT_FOUND,
                    'Subscription not found',
                    Response::HTTP_NOT_FOUND,
                    'No subscription with this identifier.',
                ),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        return new JsonResponse(self::serialize($subscription, $user->getMainCurrency()), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'api_v1_subscriptions_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $subscription = $this->findOwned($user, $id);
        if (null === $subscription) {
            return $this->subscriptionNotFound();
        }

        $data = $this->decodeJson($request);
        if (null === $data) {
            return $this->validationFailed('Request body must be a JSON object.');
        }

        if (!$this->suppliesPatchableField($data)) {
            return $this->validationFailed('No updatable fields supplied. Send any of "name", "billingCycle", "amount", "nextPayment", "currency", "convertedAmount", "confirmConvertedFor", "categoryId" or "notes".');
        }

        $mainCurrency = $user->getMainCurrency();
        $errors = $this->validatePatchStructure($data);

        $hasConverted = \array_key_exists('convertedAmount', $data);
        $convertedCleared = $hasConverted && null === $data['convertedAmount'];
        $hasConfirm = \array_key_exists('confirmConvertedFor', $data) && null !== $data['confirmConvertedFor'];

        // An explicitly cleared currency (null) is only structural when no
        // main currency is confirmed; otherwise the Subscription would lose a
        // required value, so reject it before the service would default it.
        if (\array_key_exists('currency', $data) && null === $data['currency']
            && CurrencyService::normalizeCode($mainCurrency) !== null
        ) {
            $errors[] = ['field' => 'currency', 'message' => 'This value should not be null.'];
        }

        if ($hasConfirm && !$hasConverted) {
            $errors[] = ['field' => 'confirmConvertedFor', 'message' => 'Confirming a converted amount requires convertedAmount.'];
        } elseif ($hasConfirm && $convertedCleared) {
            $errors[] = ['field' => 'confirmConvertedFor', 'message' => 'A cleared converted amount cannot be confirmed.'];
        } elseif ($hasConfirm && CurrencyService::normalizeCode($mainCurrency) === null) {
            $errors[] = ['field' => 'confirmConvertedFor', 'message' => 'There is no confirmed main currency to review against.'];
        }

        $confirmInvalid = false;
        foreach ($errors as $error) {
            if ($error['field'] === 'confirmConvertedFor') {
                $confirmInvalid = true;

                break;
            }
        }

        // A structurally valid but mismatched confirmation is a conflict, not
        // a validation error: the client reviewed the figure against the wrong
        // main currency, so nothing is applied — even when other fields are
        // also invalid.
        if ($hasConfirm && !$confirmInvalid && CurrencyService::normalizeCode($mainCurrency) !== null
            && CurrencyService::normalizeCode((string) $data['confirmConvertedFor']) !== CurrencyService::normalizeCode($mainCurrency)
        ) {
            return new JsonResponse(
                Problem::body(
                    Problem::CURRENCY_CONFLICT,
                    'Converted amount currency conflict',
                    Response::HTTP_CONFLICT,
                    sprintf(
                        'The converted amount was confirmed for %s, but your current main currency is %s.',
                        CurrencyService::normalizeCode((string) $data['confirmConvertedFor']),
                        CurrencyService::normalizeCode($mainCurrency),
                    ),
                ),
                Response::HTTP_CONFLICT,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        // Validate on a detached copy first so a failure leaves the managed
        // record — and the database — untouched (atomic PATCH). Structural and
        // candidate violations merge so one invalid field never hides another.
        $originalCurrency = $subscription->getCurrency();
        $originalConverted = $subscription->getConvertedAmount();

        $candidate = clone $subscription;
        $this->applySuppliedFields($candidate, $data, $user);
        $this->reconcilePatchConverted($candidate, $originalCurrency, $originalConverted, $mainCurrency, $hasConfirm && !$confirmInvalid, $convertedCleared);

        $errors = array_merge($errors, $this->validateCandidate($candidate, $mainCurrency));
        if ([] !== $errors) {
            usort($errors, static fn (array $a, array $b): int => [$a['field'], $a['message']] <=> [$b['field'], $b['message']]);

            return $this->validationFailed('The given data did not pass validation.', $errors);
        }

        $this->applySuppliedFields($subscription, $data, $user);
        $this->reconcilePatchConverted($subscription, $originalCurrency, $originalConverted, $mainCurrency, $hasConfirm && !$confirmInvalid, $convertedCleared);

        try {
            $this->service->update($subscription);
        } catch (\LogicException) {
            // Category state changed mid-request after the ownership check
            // above: report it as an invalid field without disclosure.
            return $this->validationFailed('The given data did not pass validation.', [
                ['field' => 'categoryId', 'message' => 'The selected category is invalid.'],
            ]);
        } catch (\InvalidArgumentException $exception) {
            // Defense-in-depth: the currency rules were already validated
            // above, so this only fires if state changed mid-request.
            return $this->validationFailed('The given data did not pass validation.', [
                ['field' => 'convertedAmount', 'message' => $exception->getMessage()],
            ]);
        }

        return new JsonResponse(self::serialize($subscription, $user->getMainCurrency()), Response::HTTP_OK);
    }

    private function findOwned(User $user, string $id): ?Subscription
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $subscription = $this->subscriptions->find(Uuid::fromString($id));
        if (null === $subscription) {
            return null;
        }

        // Foreign IDs collapse to 404 so ownership is never disclosed.
        $owner = $subscription->getOwner();
        if (null === $owner || (string) $owner->getId() !== (string) $user->getId()) {
            return null;
        }

        return $subscription;
    }

    #[Route('', name: 'api_v1_subscriptions_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $data = $this->decodeJson($request);
        if (null === $data) {
            return $this->validationFailed('Request body must be a JSON object.');
        }

        // Invalid data stays 422 even when the limit is also reached: every
        // field check below runs before the limit gate in service->add().
        [$errors, $subscription] = $this->validateFields($data, $user);
        if ([] !== $errors || null === $subscription) {
            return $this->validationFailed('The given data did not pass validation.', $errors);
        }

        try {
            $this->service->add($subscription);
        } catch (SubscriptionLimitReachedException) {
            // The per-User Subscription limit — including a lost race under
            // concurrent creates, which the service serializes atomically.
            return new JsonResponse(
                Problem::body(
                    Problem::SUBSCRIPTION_LIMIT_REACHED,
                    'Subscription limit reached',
                    Response::HTTP_CONFLICT,
                    'You have reached the maximum number of subscriptions.',
                ),
                Response::HTTP_CONFLICT,
                ['Content-Type' => 'application/problem+json'],
            );
        } catch (\LogicException) {
            // Category ownership was already established above (an omitted
            // category follows the default rule, an explicit one was checked
            // against the owner's own records), so a remaining logic error
            // means category state changed mid-request. Report it as an
            // invalid field without disclosure, never as a conflict.
            return $this->validationFailed('The given data did not pass validation.', [
                ['field' => 'categoryId', 'message' => 'The selected category is invalid.'],
            ]);
        } catch (\InvalidArgumentException $exception) {
            // Defense-in-depth: the currency rules were already validated
            // above, so this only fires if state changed mid-request.
            return $this->validationFailed('The given data did not pass validation.', [
                ['field' => 'convertedAmount', 'message' => $exception->getMessage()],
            ]);
        }

        return new JsonResponse(
            self::serialize($subscription, $user->getMainCurrency()),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_v1_subscriptions_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $subscription = $this->findOwned($user, $id);
        if (null === $subscription) {
            return $this->subscriptionNotFound();
        }

        $this->subscriptions->remove($subscription);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private const PATCHABLE_FIELDS = ['name', 'billingCycle', 'amount', 'nextPayment', 'currency', 'convertedAmount', 'confirmConvertedFor', 'categoryId', 'notes'];

    private function suppliesPatchableField(array $data): bool
    {
        foreach (self::PATCHABLE_FIELDS as $field) {
            if (\array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Structural checks for the supplied PATCH fields only: omitted fields are
     * never touched, so only present values are type-checked here. Entity and
     * currency rules run later on the patched candidate.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    private function validatePatchStructure(array $data): array
    {
        $errors = [];

        if (\array_key_exists('name', $data)) {
            if (null === $data['name']) {
                $errors[] = ['field' => 'name', 'message' => 'This value should not be null.'];
            } elseif (!\is_string($data['name']) || trim($data['name']) === '') {
                $errors[] = ['field' => 'name', 'message' => 'This value should not be blank.'];
            }
        }

        if (\array_key_exists('billingCycle', $data)) {
            if (null === $data['billingCycle']) {
                $errors[] = ['field' => 'billingCycle', 'message' => 'This value should not be null.'];
            } elseif (!\is_string($data['billingCycle']) || null === BillingCycle::tryFrom($data['billingCycle'])) {
                $errors[] = ['field' => 'billingCycle', 'message' => 'This value should be either "monthly" or "yearly".'];
            }
        }

        if (\array_key_exists('amount', $data)) {
            if (null === $data['amount']) {
                $errors[] = ['field' => 'amount', 'message' => 'This value should not be null.'];
            } elseif (!\is_int($data['amount']) && !\is_float($data['amount'])) {
                $errors[] = ['field' => 'amount', 'message' => 'This value should be a number.'];
            }
        }

        if (\array_key_exists('nextPayment', $data)) {
            if (null === $data['nextPayment']) {
                $errors[] = ['field' => 'nextPayment', 'message' => 'This value should not be null.'];
            } elseif (!\is_string($data['nextPayment']) || null === self::parseDateOnly($data['nextPayment'])) {
                $errors[] = ['field' => 'nextPayment', 'message' => 'This value should be a date in YYYY-MM-DD format.'];
            }
        }

        if (\array_key_exists('currency', $data) && null !== $data['currency']) {
            if (!\is_string($data['currency']) || !CurrencyService::isValidCode($data['currency'])) {
                $errors[] = ['field' => 'currency', 'message' => 'This value is not a valid ISO 4217 currency code.'];
            }
        }

        if (\array_key_exists('convertedAmount', $data) && null !== $data['convertedAmount']) {
            if (!\is_int($data['convertedAmount']) && !\is_float($data['convertedAmount'])) {
                $errors[] = ['field' => 'convertedAmount', 'message' => 'This value should be a number.'];
            } elseif ((float) $data['convertedAmount'] <= 0) {
                $errors[] = ['field' => 'convertedAmount', 'message' => 'Converted amount must be positive.'];
            }
        }

        if (\array_key_exists('notes', $data) && null !== $data['notes'] && !\is_string($data['notes'])) {
            $errors[] = ['field' => 'notes', 'message' => 'This value should be of type string.'];
        }

        // A supplied category must resolve to one of the owner's own records;
        // anything else — including an explicit null or empty value that would
        // clear the required category — is invalid without disclosure. The
        // ownership lookup needs the User, so it runs in validateCandidate();
        // here only the clear attempt is rejected structurally.
        if (\array_key_exists('categoryId', $data) && (!\is_string($data['categoryId']) || '' === $data['categoryId'])) {
            $errors[] = ['field' => 'categoryId', 'message' => 'The selected category is invalid.'];
        }

        if (\array_key_exists('confirmConvertedFor', $data) && null !== $data['confirmConvertedFor']) {
            if (!\is_string($data['confirmConvertedFor']) || !CurrencyService::isValidCode($data['confirmConvertedFor'])) {
                $errors[] = ['field' => 'confirmConvertedFor', 'message' => 'This value is not a valid ISO 4217 currency code.'];
            }
        }

        return $errors;
    }

    /**
     * Applies the structurally valid supplied fields to the target. Omitted
     * fields keep their values; server-controlled fields are never read here.
     *
     * @param array<string, mixed> $data
     */
    private function applySuppliedFields(Subscription $target, array $data, User $user): void
    {
        if (\array_key_exists('name', $data) && \is_string($data['name'])) {
            $target->setName($data['name']);
        }

        if (\array_key_exists('billingCycle', $data) && \is_string($data['billingCycle'])
            && null !== BillingCycle::tryFrom($data['billingCycle'])
        ) {
            $target->setBillingCycle(BillingCycle::tryFrom($data['billingCycle']));
        }

        if (\array_key_exists('amount', $data) && (\is_int($data['amount']) || \is_float($data['amount']))) {
            $target->setAmount((float) $data['amount']);
        }

        if (\array_key_exists('nextPayment', $data) && \is_string($data['nextPayment'])
            && null !== self::parseDateOnly($data['nextPayment'])
        ) {
            $target->setNextPayment(self::parseDateOnly($data['nextPayment']));
        }

        if (\array_key_exists('currency', $data)) {
            $target->setCurrency(\is_string($data['currency']) ? $data['currency'] : null);
        }

        if (\array_key_exists('convertedAmount', $data)) {
            $converted = $data['convertedAmount'];
            $target->setConvertedAmount(\is_int($converted) || \is_float($converted) ? (float) $converted : null);
        }

        if (\array_key_exists('notes', $data)) {
            $target->setNotes(\is_string($data['notes']) ? $data['notes'] : null);
        }

        if (\array_key_exists('categoryId', $data) && \is_string($data['categoryId']) && '' !== $data['categoryId']) {
            $target->setCategory($this->findOwnedCategory($user, $data['categoryId']));
        }
    }

    /**
     * Reconciles converted input after a PATCH: an explicit confirmation
     * against the current main currency reviews even an unchanged figure,
     * while without one the shared web rules apply (a re-entered figure is
     * stamped, a figure kept across a currency change is dropped so it is
     * never reused, and an untouched stale figure keeps its old stamp).
     */
    private function reconcilePatchConverted(
        Subscription $target,
        ?string $originalCurrency,
        ?float $originalConverted,
        ?string $mainCurrency,
        bool $forceReview,
        bool $convertedCleared,
    ): void {
        if ($convertedCleared) {
            $target->setConvertedAmount(null);
            $target->setConvertedCurrency(null);

            return;
        }

        if ($forceReview) {
            $target->setConvertedCurrency($mainCurrency);

            return;
        }

        $target->reconcileConverted($originalCurrency, $originalConverted, $mainCurrency);
    }

    /**
     * Runs the shared entity constraints and the save-time currency behavior
     * on the patched candidate, plus the ownership check for a supplied
     * categoryId.
     *
     * @return list<array{field: string, message: string}>
     */
    private function validateCandidate(Subscription $candidate, ?string $mainCurrency): array
    {
        $errors = [];

        foreach ($this->validator->validate($candidate) as $violation) {
            $field = ltrim((string) $violation->getPropertyPath(), '.');
            $errors[] = ['field' => $field, 'message' => (string) $violation->getMessage()];
        }

        foreach ($candidate->validateConverted($mainCurrency, true) as $message) {
            $errors[] = ['field' => self::convertedViolationField($message), 'message' => $message];
        }

        if (null === $candidate->getCategory()) {
            $errors[] = ['field' => 'categoryId', 'message' => 'The selected category is invalid.'];
        }

        usort($errors, static fn (array $a, array $b): int => [$a['field'], $a['message']] <=> [$b['field'], $b['message']]);

        return $errors;
    }

    /**
     * Decodes a JSON object body. Returns null when the body is not a JSON
     * object (malformed JSON, empty body, or a JSON scalar/array).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(Request $request): ?array
    {
        $content = trim($request->getContent());
        if ('' === $content || str_starts_with($content, '[')) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // `{}` decodes to `[]`, which is a valid (field-less) payload here;
        // every top-level JSON array was rejected by the `[` guard above.
        return \is_array($data) ? $data : null;
    }

    /**
     * Validates the writable create fields against the same rules the web
     * experience enforces: required name/billingCycle/amount/date-only
     * nextPayment, optional ISO 4217 currency, optional positive manual
     * converted amount, optional notes, and an optional owned categoryId. An
     * explicitly supplied missing, malformed, or foreign categoryId is an
     * invalid field (422) without disclosure; an omitted category is valid
     * and follows the default-category rule on save. Every other field
     * (owner, UUID, timestamps, convertedCurrency, pendingReview) is ignored.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: list<array{field: string, message: string}>, 1: ?Subscription} Field errors with the
     *         validated candidate (null when structural checks already failed, so the caller never builds twice)
     */
    private function validateFields(array $data, User $user): array
    {
        $errors = [];

        $name = $data['name'] ?? null;
        if (!\is_string($name) || trim($name) === '') {
            $errors[] = ['field' => 'name', 'message' => 'This value should not be blank.'];
        }

        $cycle = $data['billingCycle'] ?? null;
        if (!\is_string($cycle) || null === BillingCycle::tryFrom($cycle)) {
            $errors[] = ['field' => 'billingCycle', 'message' => 'This value should be either "monthly" or "yearly".'];
        }

        $amount = $data['amount'] ?? null;
        if (!\is_int($amount) && !\is_float($amount)) {
            $errors[] = ['field' => 'amount', 'message' => 'This value should be a number.'];
        }

        $nextPayment = $data['nextPayment'] ?? null;
        if (!\is_string($nextPayment) || null === self::parseDateOnly($nextPayment)) {
            $errors[] = ['field' => 'nextPayment', 'message' => 'This value should be a date in YYYY-MM-DD format.'];
        }

        if (\array_key_exists('currency', $data) && null !== $data['currency']) {
            $currency = $data['currency'];
            if (!\is_string($currency) || !CurrencyService::isValidCode($currency)) {
                $errors[] = ['field' => 'currency', 'message' => 'This value is not a valid ISO 4217 currency code.'];
            }
        }

        if (\array_key_exists('convertedAmount', $data) && null !== $data['convertedAmount']) {
            $converted = $data['convertedAmount'];
            if (!\is_int($converted) && !\is_float($converted)) {
                $errors[] = ['field' => 'convertedAmount', 'message' => 'This value should be a number.'];
            } elseif ((float) $converted <= 0) {
                $errors[] = ['field' => 'convertedAmount', 'message' => 'Converted amount must be positive.'];
            }
        }

        if (\array_key_exists('notes', $data) && null !== $data['notes'] && !\is_string($data['notes'])) {
            $errors[] = ['field' => 'notes', 'message' => 'This value should be of type string.'];
        }

        // An explicitly supplied category must resolve to one of the owner's
        // own records; anything else is invalid without saying which.
        if (\array_key_exists('categoryId', $data) && null !== $data['categoryId'] && '' !== $data['categoryId']) {
            $categoryId = $data['categoryId'];
            if (!\is_string($categoryId) || null === $this->findOwnedCategory($user, $categoryId)) {
                $errors[] = ['field' => 'categoryId', 'message' => 'The selected category is invalid.'];
            }
        }

        if ([] !== $errors) {
            usort($errors, static fn (array $a, array $b): int => [$a['field'], $a['message']] <=> [$b['field'], $b['message']]);

            return [$errors, null];
        }

        // Structural checks passed: run the shared entity constraints (name
        // length, positive amount, notes length) and the currency behavior
        // (main-currency defaulting, required cross-currency converted amount,
        // no same-currency duplicate, legacy passthrough) on a candidate.
        $candidate = $this->buildSubscription($data, $user);
        $mainCurrency = $user->getMainCurrency();

        if ($candidate->getCurrency() === null && CurrencyService::normalizeCode($mainCurrency) !== null) {
            $candidate->setCurrency($mainCurrency);
        }

        foreach ($this->validator->validate($candidate) as $violation) {
            $field = ltrim((string) $violation->getPropertyPath(), '.');
            $errors[] = ['field' => $field, 'message' => (string) $violation->getMessage()];
        }

        // Validate before stamping, like the web form: a same-currency
        // duplicate is a violation, not silently dropped input.
        foreach ($candidate->validateConverted($mainCurrency, true) as $message) {
            $errors[] = ['field' => self::convertedViolationField($message), 'message' => $message];
        }

        usort($errors, static fn (array $a, array $b): int => [$a['field'], $a['message']] <=> [$b['field'], $b['message']]);

        return [$errors, $candidate];
    }

    /**
     * Builds an unsaved Subscription from validated payload data. The owner is
     * set on the owning side only (as the web form does), the category stays
     * null when omitted so the service applies the default rule, and
     * server-controlled convertedCurrency is never taken from the payload.
     *
     * @param array<string, mixed> $data
     */
    private function buildSubscription(array $data, User $user): Subscription
    {
        $subscription = (new Subscription())
            ->setName((string) ($data['name'] ?? ''))
            ->setBillingCycle(BillingCycle::tryFrom((string) ($data['billingCycle'] ?? '')) ?? BillingCycle::Monthly)
            ->setAmount(isset($data['amount']) && (\is_int($data['amount']) || \is_float($data['amount'])) ? (float) $data['amount'] : null)
            ->setCurrency(isset($data['currency']) && \is_string($data['currency']) ? $data['currency'] : null)
            ->setNotes(isset($data['notes']) && \is_string($data['notes']) ? $data['notes'] : null);
        $subscription->setConvertedAmount(
            isset($data['convertedAmount']) && (\is_int($data['convertedAmount']) || \is_float($data['convertedAmount']))
                ? (float) $data['convertedAmount']
                : null,
        );

        $nextPayment = isset($data['nextPayment']) && \is_string($data['nextPayment'])
            ? self::parseDateOnly($data['nextPayment'])
            : null;
        $subscription->setNextPayment($nextPayment);

        if (\array_key_exists('categoryId', $data) && null !== $data['categoryId'] && '' !== $data['categoryId']
            && \is_string($data['categoryId'])
        ) {
            $subscription->setCategory($this->findOwnedCategory($user, $data['categoryId']));
        }

        $subscription->setOwner($user);

        return $subscription;
    }

    private function findOwnedCategory(User $user, string $id): ?\App\Entity\ExpenseCategory
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $category = $this->categories->find(Uuid::fromString($id));
        if (null === $category || !$category->isOwnedBy($user)) {
            return null;
        }

        return $category;
    }

    private static function parseDateOnly(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    private static function convertedViolationField(string $message): string
    {
        if (str_contains($message, 'select a currency') || str_starts_with($message, 'Currency "')) {
            return 'currency';
        }

        return 'convertedAmount';
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            Problem::body(
                Problem::UNAUTHORIZED,
                'Authentication required',
                Response::HTTP_UNAUTHORIZED,
                'Authentication is required to access this resource.',
            ),
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    private function subscriptionNotFound(): JsonResponse
    {
        return new JsonResponse(
            Problem::body(
                Problem::SUBSCRIPTION_NOT_FOUND,
                'Subscription not found',
                Response::HTTP_NOT_FOUND,
                'No subscription with this identifier.',
            ),
            Response::HTTP_NOT_FOUND,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function validationFailed(string $detail, array $errors = []): JsonResponse
    {
        $extra = [] !== $errors ? ['errors' => $errors] : [];

        return new JsonResponse(
            Problem::body(
                Problem::VALIDATION_FAILED,
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $detail,
                $extra,
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    /**
     * @return array{id: string, name: string, billingCycle: string, amount: float|null, nextPayment: string|null, currency: string|null, convertedAmount: float|null, convertedCurrency: string|null, pendingReview: bool, categoryId: string|null, notes: string|null, createdAt: string, updatedAt: string}
     */
    private static function serialize(Subscription $subscription, ?string $mainCurrency): array
    {
        $amount = $subscription->getAmount();
        $convertedAmount = $subscription->getConvertedAmount();
        $category = $subscription->getCategory();

        return [
            'id' => (string) $subscription->getId(),
            'name' => $subscription->getName(),
            'billingCycle' => $subscription->getBillingCycle()?->value,
            'amount' => null !== $amount ? round($amount, 2) : null,
            'nextPayment' => $subscription->getNextPayment()?->format('Y-m-d'),
            'currency' => $subscription->getCurrency(),
            'convertedAmount' => null !== $convertedAmount ? round($convertedAmount, 2) : null,
            'convertedCurrency' => $subscription->getConvertedCurrency(),
            'pendingReview' => $subscription->isPendingReview($mainCurrency),
            'categoryId' => null !== $category && null !== $category->getId() ? (string) $category->getId() : null,
            'notes' => $subscription->getNotes(),
            'createdAt' => $subscription->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $subscription->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
