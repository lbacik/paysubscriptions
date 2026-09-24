<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Remembers the Subscription list's filter and sort state across normal
 * navigation and Turbo updates.
 *
 * State travels in the query string when present (`?category=…&sort=…&order=…`)
 * and falls back to the session otherwise, so Turbo-stream re-renders (which
 * carry no list parameters) still show the list the User was looking at.
 * Every value written to the session is normalized first, so stale or forged
 * values can never stick.
 */
final class SubscriptionListState
{
    public const SORT_NAME = 'name';
    public const SORT_PRICE = 'price';
    public const SORT_RENEWAL = 'renewal';

    public const DEFAULT_SORT = self::SORT_NAME;
    public const DEFAULT_ORDER = 'asc';

    /**
     * Legacy sort keys from before the comparable-price sort existed. Both
     * monetary columns follow the same price ordering (a yearly equivalent is
     * the monthly one ×12), so old bookmarks keep working and order
     * consistently in the User's main currency.
     */
    private const LEGACY_SORTS = [
        'monthly' => self::SORT_PRICE,
        'yearly' => self::SORT_PRICE,
    ];

    private const ALLOWED_SORTS = [
        self::SORT_NAME,
        self::SORT_PRICE,
        self::SORT_RENEWAL,
    ];

    private const ALLOWED_ORDERS = ['asc', 'desc'];

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function sort(): string
    {
        $sort = $this->query('sort');

        if ($sort === null) {
            $sort = $this->session()->get('sort', self::DEFAULT_SORT);
        }

        \assert(\is_string($sort));
        $sort = self::normalizeSort($sort);

        $this->session()->set('sort', $sort);

        return $sort;
    }

    public function order(): string
    {
        $order = $this->query('order');

        if ($order === null) {
            $order = $this->session()->get('order', self::DEFAULT_ORDER);
        }

        if (!\in_array($order, self::ALLOWED_ORDERS, true)) {
            $order = self::DEFAULT_ORDER;
        }

        $this->session()->set('order', $order);

        return $order;
    }

    /**
     * The selected category id (UUID string), or null for "all categories".
     * Returned verbatim: ownership is enforced where the list is built, so a
     * forged foreign id matches nothing instead of leaking another User's
     * records.
     */
    public function categoryId(): ?string
    {
        $category = $this->query('category');

        if ($category === null) {
            $category = $this->session()->get('subscription_category');
        }

        if (\is_string($category)) {
            $category = trim($category);
        }

        if ($category === '' || (\is_string($category) && strtolower($category) === 'all')) {
            $category = null;
        }

        if ($category !== null && !\is_string($category)) {
            $category = null;
        }

        $this->session()->set('subscription_category', $category);

        return $category;
    }

    public function nextOrder(?string $order = null): string
    {
        return match ($order) {
            'asc' => 'desc',
            'desc' => 'asc',
            default => 'asc',
        };
    }

    /**
     * Single normalizer for sort keys, shared with SubscriptionService so
     * direct service calls accept the same keys as the request state.
     */
    public static function normalizeSort(string $sort): string
    {
        $sort = self::LEGACY_SORTS[$sort] ?? $sort;

        return \in_array($sort, self::ALLOWED_SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    private function query(string $key): ?string
    {
        $value = $this->requestStack->getCurrentRequest()?->query->get($key);

        return \is_string($value) ? $value : null;
    }

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            throw new \LogicException('The subscription list state requires a session-backed request.');
        }

        return $request->getSession();
    }
}
