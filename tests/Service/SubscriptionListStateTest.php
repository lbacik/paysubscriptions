<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SubscriptionListState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Query/session state for the Subscription list (issue #43): values travel in
 * the query string when present, fall back to the session otherwise, and are
 * always normalized before they stick.
 */
final class SubscriptionListStateTest extends TestCase
{
    public function testDefaultsWithoutAnyState(): void
    {
        $state = $this->state([]);

        self::assertSame('name', $state->sort());
        self::assertSame('asc', $state->order());
        self::assertNull($state->categoryId());
    }

    public function testQueryStateIsRememberedInTheSession(): void
    {
        $session = $this->session();
        $state = $this->state(['sort' => 'price', 'order' => 'desc', 'category' => 'cat-id'], $session);
        self::assertSame('price', $state->sort());
        self::assertSame('desc', $state->order());
        self::assertSame('cat-id', $state->categoryId());

        // Plain navigation without query parameters re-applies the memory.
        $later = $this->state([], $session);
        self::assertSame('price', $later->sort());
        self::assertSame('desc', $later->order());
        self::assertSame('cat-id', $later->categoryId());
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $state = $this->state(['sort' => 'DROP TABLE', 'order' => 'sideways']);

        self::assertSame('name', $state->sort());
        self::assertSame('asc', $state->order());
    }

    public function testLegacySortKeysNormalizeToPrice(): void
    {
        self::assertSame('price', $this->state(['sort' => 'monthly'])->sort());
        self::assertSame('price', $this->state(['sort' => 'yearly'])->sort());
    }

    public function testAllAndEmptyCategoryMeanNoFilter(): void
    {
        self::assertNull($this->state(['category' => 'all'])->categoryId());
        self::assertNull($this->state(['category' => ''])->categoryId());

        // Clearing sticks: a previous selection is forgotten.
        $session = $this->session();
        self::assertSame('cat-id', $this->state(['category' => 'cat-id'], $session)->categoryId());
        self::assertNull($this->state(['category' => 'all'], $session)->categoryId());
        self::assertNull($this->state([], $session)->categoryId());
    }

    public function testNextOrder(): void
    {
        $state = $this->state([]);

        self::assertSame('desc', $state->nextOrder('asc'));
        self::assertSame('asc', $state->nextOrder('desc'));
        self::assertSame('asc', $state->nextOrder(null));
    }

    /**
     * @param array<string, string> $query
     */
    private function state(array $query, ?Session $session = null): SubscriptionListState
    {
        $request = new Request($query);
        $request->setSession($session ?? $this->session());

        $stack = new RequestStack();
        $stack->push($request);

        return new SubscriptionListState($stack);
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
