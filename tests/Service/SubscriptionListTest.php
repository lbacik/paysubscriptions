<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\ExpenseCategoryService;
use App\Service\RenewalCalculator;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Category filtering and name/price/renewal sorting for the Subscription list
 * (issue #43). Pure unit tests: the repository is stubbed per owner so
 * two-user isolation is proven without a database.
 */
final class SubscriptionListTest extends TestCase
{
    public function testCategoryFilterShowsOnlyMatchingSubscriptions(): void
    {
        $owner = $this->user('owner@example.com');
        $catA = $this->category($owner, 'Video');
        $catB = $this->category($owner, 'Music');

        $inA = $this->subscription($owner, $catA, 'AlphaFlix', BillingCycle::Monthly, '2024-01-05', 10.0);
        $inB = $this->subscription($owner, $catB, 'BetaTunes', BillingCycle::Monthly, '2024-01-06', 20.0);

        $result = $this->service([$inA, $inB])->get($owner, 'name', 'asc', (string) $catA->getId());

        self::assertSame([$inA], array_values($result));
    }

    public function testUnknownCategoryFilterShowsEmptyResult(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');
        $sub = $this->subscription($owner, $cat, 'AlphaFlix', BillingCycle::Monthly, '2024-01-05', 10.0);

        $result = $this->service([$sub])->get($owner, 'name', 'asc', (string) Uuid::v4());

        self::assertSame([], array_values($result));
    }

    public function testForeignCategoryFilterNeverLeaksAnotherUsersRecords(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $ownCat = $this->category($owner, 'Video');
        $foreignCat = $this->category($stranger, 'Video');

        $own = $this->subscription($owner, $ownCat, 'OwnFlix', BillingCycle::Monthly, '2024-01-05', 10.0);

        // Only the signed-in owner's subscriptions are ever loaded; a forged
        // foreign category id can therefore match nothing, never the
        // stranger's records.
        $service = $this->service([$own], [$this->subscription(
            $stranger,
            $foreignCat,
            'StrangerFlix',
            BillingCycle::Monthly,
            '2024-01-05',
            10.0,
        )]);

        $result = $service->get($owner, 'name', 'asc', (string) $foreignCat->getId());

        self::assertSame([], array_values($result));
    }

    public function testNameSortSupportsBothDirections(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');
        $alpha = $this->subscription($owner, $cat, 'Alpha', BillingCycle::Monthly, '2024-01-05', 10.0);
        $bravo = $this->subscription($owner, $cat, 'Bravo', BillingCycle::Monthly, '2024-01-05', 10.0);
        $charlie = $this->subscription($owner, $cat, 'Charlie', BillingCycle::Monthly, '2024-01-05', 10.0);

        $service = $this->service([$charlie, $alpha, $bravo]);

        self::assertSame(
            [$alpha, $bravo, $charlie],
            array_values($service->get($owner, 'name', 'asc')),
        );
        self::assertSame(
            [$charlie, $bravo, $alpha],
            array_values($service->get($owner, 'name', 'desc')),
        );
    }

    public function testPriceSortUsesConvertedAmountsInMainCurrency(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');

        // EUR 10.00 converted by hand to USD 11.00 must compare as 11 USD.
        $cross = $this->subscription($owner, $cat, 'Cross', BillingCycle::Monthly, '2024-01-05', 10.0, 'EUR', 11.0, 'USD');
        $same = $this->subscription($owner, $cat, 'Same', BillingCycle::Monthly, '2024-01-05', 10.5, 'USD');
        // USD 120.00 yearly normalizes to USD 10.00 monthly.
        $yearly = $this->subscription($owner, $cat, 'Yearly', BillingCycle::Yearly, '2024-01-05', 120.0, 'USD');

        $service = $this->service([$same, $cross, $yearly]);

        self::assertSame(
            [$yearly, $same, $cross],
            array_values($service->get($owner, 'price', 'asc', null, 'USD')),
        );
        self::assertSame(
            [$cross, $same, $yearly],
            array_values($service->get($owner, 'price', 'desc', null, 'USD')),
        );
    }

    public function testPriceTiesBreakStablyByNameThenId(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');

        // Same comparable price (12.00 monthly vs 144.00 yearly): name order
        // wins in both directions so the tie never flips with the direction.
        $beta = $this->subscription($owner, $cat, 'Beta', BillingCycle::Monthly, '2024-01-05', 12.0);
        $alpha = $this->subscription($owner, $cat, 'Alpha', BillingCycle::Yearly, '2024-01-05', 144.0);

        $service = $this->service([$beta, $alpha]);

        self::assertSame(
            [$alpha, $beta],
            array_values($service->get($owner, 'price', 'asc')),
        );
        self::assertSame(
            [$alpha, $beta],
            array_values($service->get($owner, 'price', 'desc')),
        );
    }

    public function testRenewalSortSupportsBothDirections(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');
        $today = new \DateTimeImmutable('2024-03-01');

        $soon = $this->subscription($owner, $cat, 'Soon', BillingCycle::Monthly, '2024-03-05', 10.0);
        $later = $this->subscription($owner, $cat, 'Later', BillingCycle::Monthly, '2024-03-20', 10.0);
        $far = $this->subscription($owner, $cat, 'Far', BillingCycle::Yearly, '2024-02-10', 10.0);

        $service = $this->service([$far, $later, $soon]);

        self::assertSame(
            [$soon, $later, $far],
            array_values($service->get($owner, 'renewal', 'asc', null, null, $today)),
        );
        self::assertSame(
            [$far, $later, $soon],
            array_values($service->get($owner, 'renewal', 'desc', null, null, $today)),
        );
    }

    public function testRenewalTiesBreakStablyByName(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');
        $today = new \DateTimeImmutable('2024-03-01');

        $beta = $this->subscription($owner, $cat, 'Beta', BillingCycle::Monthly, '2024-03-05', 10.0);
        $alpha = $this->subscription($owner, $cat, 'Alpha', BillingCycle::Monthly, '2024-03-05', 10.0);

        $service = $this->service([$beta, $alpha]);

        self::assertSame(
            [$alpha, $beta],
            array_values($service->get($owner, 'renewal', 'asc', null, null, $today)),
        );
        self::assertSame(
            [$alpha, $beta],
            array_values($service->get($owner, 'renewal', 'desc', null, null, $today)),
        );
    }

    public function testLegacyMonthlyAndYearlySortKeysBehaveLikePrice(): void
    {
        $owner = $this->user('owner@example.com');
        $cat = $this->category($owner, 'Video');

        $cheap = $this->subscription($owner, $cat, 'Cheap', BillingCycle::Monthly, '2024-01-05', 5.0);
        $pricey = $this->subscription($owner, $cat, 'Pricey', BillingCycle::Monthly, '2024-01-05', 50.0);

        $service = $this->service([$pricey, $cheap]);

        foreach (['monthly', 'yearly'] as $legacy) {
            self::assertSame(
                [$cheap, $pricey],
                array_values($service->get($owner, $legacy, 'asc')),
                sprintf('Legacy sort "%s" should order like price', $legacy),
            );
        }
    }

    public function testFilterAndSortCombine(): void
    {
        $owner = $this->user('owner@example.com');
        $catA = $this->category($owner, 'Video');
        $catB = $this->category($owner, 'Music');

        $cheapA = $this->subscription($owner, $catA, 'CheapA', BillingCycle::Monthly, '2024-01-05', 5.0);
        $priceyA = $this->subscription($owner, $catA, 'PriceyA', BillingCycle::Monthly, '2024-01-05', 50.0);
        $middleB = $this->subscription($owner, $catB, 'MiddleB', BillingCycle::Monthly, '2024-01-05', 25.0);

        $service = $this->service([$middleB, $cheapA, $priceyA]);

        self::assertSame(
            [$priceyA, $cheapA],
            array_values($service->get($owner, 'price', 'desc', (string) $catA->getId())),
        );
    }

    /**
     * @param list<Subscription> $ownerSubscriptions
     * @param list<Subscription> $strangerSubscriptions
     */
    private function service(array $ownerSubscriptions, array $strangerSubscriptions = []): SubscriptionService
    {
        $repository = $this->createMock(SubscriptionRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($ownerSubscriptions, $strangerSubscriptions): array {
                $wanted = $criteria['owner'] ?? null;

                foreach ([...$ownerSubscriptions, ...$strangerSubscriptions] as $subscription) {
                    if ($subscription->getOwner() === $wanted) {
                        $matched[] = $subscription;
                    }
                }

                return $matched ?? [];
            }
        );

        return new SubscriptionService(
            $repository,
            $this->createMock(UserRepository::class),
            $this->createMock(ExpenseCategoryService::class),
            new RenewalCalculator(),
        );
    }

    private function user(string $email): User
    {
        return (new User())->setEmail($email);
    }

    private function category(User $owner, string $name): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setName($name)
            ->setColor('#577399');
        $owner->addExpenseCategory($category);
        $this->assignId($category);

        return $category;
    }

    private function subscription(
        User $owner,
        ExpenseCategory $category,
        string $name,
        BillingCycle $cycle,
        string $nextPayment,
        float $amount,
        ?string $currency = null,
        ?float $convertedAmount = null,
        ?string $convertedCurrency = null,
    ): Subscription {
        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($cycle)
            ->setNextPayment(new \DateTime($nextPayment))
            ->setAmount($amount)
            ->setOwner($owner)
            ->setCategory($category)
            ->setCurrency($currency);
        $subscription->setConvertedAmount($convertedAmount);
        $subscription->setConvertedCurrency($convertedCurrency);
        $this->assignId($subscription);

        return $subscription;
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
