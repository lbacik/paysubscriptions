<?php

declare(strict_types=1);

namespace App\Prototype;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PROTOTYPE (#246), variants A and C: scopes every Doctrine-provider read of
 * ExpenseCategory to the token User, so a foreign id is a plain 404.
 */
final class OwnerScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->scope($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->scope($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function scope(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass): void
    {
        if (ExpenseCategory::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        $alias = $queryBuilder->getRootAliases()[0];

        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parameter = $queryNameGenerator->generateParameterName('owner');
        $queryBuilder
            ->andWhere(sprintf('%s.owner = :%s', $alias, $parameter))
            ->setParameter($parameter, $user->getId(), UuidType::NAME);
    }
}
