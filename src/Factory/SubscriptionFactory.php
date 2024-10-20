<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Subscription;
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
        return $this
            ->afterInstantiate(function(Subscription $subscription): void {
                self::faker()->boolean()
                    ? $subscription->setMonthly(self::faker()->randomFloat(2, 10, 100))
                    : $subscription->setYearly(self::faker()->randomFloat(2, 100, 1000));
            })
        ;
    }
}
