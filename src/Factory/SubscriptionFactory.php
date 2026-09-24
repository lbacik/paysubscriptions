<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Subscription;
use App\Enum\BillingCycle;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Subscription>
 */
final class SubscriptionFactory extends PersistentProxyObjectFactory
{

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
            'nextPayment' => self::faker()->dateTimeBetween('-1 year', '+1 year'),
            'name' => self::faker()->domainName(),
            'owner' => UserFactory::new(),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function(Subscription $subscription): void {
                $cycle = self::faker()->boolean() ? BillingCycle::Monthly : BillingCycle::Yearly;
                $subscription->setBillingCycle($cycle);
                $subscription->setAmount(
                    $cycle === BillingCycle::Monthly
                        ? self::faker()->randomFloat(2, 10, 100)
                        : self::faker()->randomFloat(2, 100, 1000)
                );
            })
        ;
    }
}
