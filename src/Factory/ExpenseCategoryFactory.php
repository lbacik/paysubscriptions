<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\ExpenseCategory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<ExpenseCategory>
 */
final class ExpenseCategoryFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return ExpenseCategory::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    protected function defaults(): array|callable
    {
        return [
            'color' => self::faker()->hexColor(),
            'name' => self::faker()->unique()->word(),
            'owner' => UserFactory::new(),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(ExpenseCategory $category): void {})
        ;
    }
}
