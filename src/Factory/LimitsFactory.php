<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Limits;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Limits>
 */
final class LimitsFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Limits::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    protected function defaults(): array|callable
    {
        return [
            'subscriptions' => self::faker()->randomNumber(),
            'user' => UserFactory::new(),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(Limits $limits): void {})
        ;
    }
}
