<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use App\Entity\UserOwned;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Restricts every API Platform Doctrine read of a UserOwned entity to the
 * token User: ownership is a scoped read, never a security expression, so a
 * foreign identifier is a plain 404 and a collection never lists foreign rows.
 *
 * The same scoped item read resolves IRIs in write bodies, so a reference to
 * another User's row looks unknown.
 */
final class UserOwnedExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if (!is_a($resourceClass, UserOwned::class, true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            // Fail closed: without a token User nothing is visible.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        // Bound with its UUID type: an inferred string binding never matches
        // the BINARY(16) column on MySQL.
        $parameter = $queryNameGenerator->generateParameterName('owner');
        $queryBuilder
            ->andWhere(\sprintf('%s.owner = :%s', $alias, $parameter))
            ->setParameter($parameter, $user->getId(), UuidType::NAME);
    }
}
