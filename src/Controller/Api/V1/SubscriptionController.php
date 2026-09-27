<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\Problem;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionListState;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only Subscription endpoints for API v1 (issue #96).
 *
 * Collection and detail are scoped to the bearer token User and returned as
 * ordinary `application/json`. Navigation is explicit and stateless: the
 * collection honors `categoryId`, `sort`, `order`, and 1-based `page` query
 * parameters (25 items per page) parsed from the request alone. The web
 * session's list state (SubscriptionListState) is never read or written here.
 * Missing, malformed, or foreign IDs return 404 `application/problem+json`
 * without disclosing another User's data.
 */
#[Route('/api/v1/subscriptions')]
class SubscriptionController extends AbstractController
{
    public const PER_PAGE = 25;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionService $service,
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
