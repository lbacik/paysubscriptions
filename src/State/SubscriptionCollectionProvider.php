<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\User;
use App\Service\SubscriptionListState;
use App\Service\SubscriptionService;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Serves the API v1 Subscription collection from SubscriptionService::get(),
 * which already scopes to the token User and computes the price and renewal
 * sorts. Those sorts compare hand-entered converted amounts and projected
 * renewal dates in memory, so they cannot be a Doctrine filter: the provider
 * loads the ordered whole and slices the requested page itself.
 *
 * Query contract: `order[name|price|renewal]=asc|desc` (one field at a time,
 * default name/asc, ties break by name then id), `category=<uuid>` (a
 * malformed UUID is a 422 on `category`; an unknown or foreign UUID matches
 * nothing, because the service only matches the owner's own categories), and
 * a 1-based `page` (25 items per page, out-of-range pages are empty).
 *
 * @implements ProviderInterface<object>
 */
final class SubscriptionCollectionProvider implements ProviderInterface
{
    public const PER_PAGE = 25;

    private const ALLOWED_SORTS = [
        SubscriptionListState::SORT_NAME,
        SubscriptionListState::SORT_PRICE,
        SubscriptionListState::SORT_RENEWAL,
    ];

    private const ALLOWED_ORDERS = ['asc', 'desc'];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $user = $this->security->getUser();
        \assert($user instanceof User);

        [$sortBy, $order] = $this->sort($request);
        $categoryId = $this->category($request);
        $page = $this->page($request);

        $all = $this->subscriptions->get($user, $sortBy, $order, $categoryId, $user->getMainCurrency());

        return new ArrayPaginator(array_values($all), ($page - 1) * self::PER_PAGE, self::PER_PAGE);
    }

    /**
     * @return array{string, string}
     */
    private function sort(\Symfony\Component\HttpFoundation\Request $request): array
    {
        $order = $request->query->get('order');
        if (null === $order) {
            return [SubscriptionListState::DEFAULT_SORT, SubscriptionListState::DEFAULT_ORDER];
        }

        if (!\is_array($order) || 1 !== \count($order)) {
            throw new ValidationException($this->violations('order', 'Sort must be a single "order[field]" parameter with direction "asc" or "desc".'));
        }

        $field = array_key_first($order);
        $direction = $order[$field];
        \assert(\is_string($field) || \is_int($field));

        if (!\is_string($field) || !\in_array($field, self::ALLOWED_SORTS, true)) {
            throw new ValidationException($this->violations(
                'order',
                sprintf('Invalid sort field "%s". Allowed values: %s.', (string) $field, implode(', ', self::ALLOWED_SORTS)),
            ));
        }

        if (!\is_string($direction) || !\in_array(strtolower($direction), self::ALLOWED_ORDERS, true)) {
            throw new ValidationException($this->violations(
                'order',
                sprintf('Invalid sort direction "%s". Allowed values: %s.', (string) $direction, implode(', ', self::ALLOWED_ORDERS)),
            ));
        }

        return [$field, strtolower($direction)];
    }

    private function category(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $category = $request->query->get('category');
        if (null === $category || '' === $category) {
            return null;
        }

        if (!\is_string($category) || !Uuid::isValid($category)) {
            throw new ValidationException($this->violations('category', 'The selected category is invalid.'));
        }

        return $category;
    }

    private function page(\Symfony\Component\HttpFoundation\Request $request): int
    {
        $page = $request->query->get('page', 1);

        return max(1, \is_scalar($page) ? (int) $page : 1);
    }

    private function violations(string $path, string $message): ConstraintViolationListInterface
    {
        return new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, $path, null),
        ]);
    }
}
