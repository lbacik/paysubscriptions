<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Repository\ExpenseCategoryRepository;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Subscription>
 */
final class SubscriptionFactory extends PersistentProxyObjectFactory
{
    public function __construct(
        private ExpenseCategoryRepository $categoryRepository
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return Subscription::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    protected function defaults(): array|callable
    {
        return [
            'firstPayment' => self::faker()->dateTime(),
            'name' => self::faker()->domainName(),
            'owner' => UserFactory::new(),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    protected function initialize(): static
    {
        $categoryRepository = $this->categoryRepository;

        return $this
            ->afterInstantiate(function(Subscription $subscription) use ($categoryRepository): void {
                self::faker()->boolean()
                    ? $subscription->setMonthly(self::faker()->randomFloat(2, 10, 100))
                    : $subscription->setYearly(self::faker()->randomFloat(2, 100, 1000));

                if (null !== $subscription->getCategory()) {
                    return;
                }

                $owner = $subscription->getOwner();

                foreach ($owner->getExpenseCategories() as $category) {
                    if ($category->getName() === ExpenseCategory::DEFAULT_NAME) {
                        $subscription->setCategory($category);

                        return;
                    }
                }

                if (null !== $owner->getId()) {
                    $persisted = $categoryRepository->findOneBy([
                        'owner' => $owner,
                        'name' => ExpenseCategory::DEFAULT_NAME,
                    ]);

                    if (null !== $persisted) {
                        $subscription->setCategory($persisted);

                        return;
                    }
                }

                $category = (new ExpenseCategory())
                    ->setName(ExpenseCategory::DEFAULT_NAME)
                    ->setColor(ExpenseCategory::DEFAULT_COLOR);
                $owner->addExpenseCategory($category);
                $subscription->setCategory($category);
            })
        ;
    }
}
