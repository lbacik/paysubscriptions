<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthConsent;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthConsent>
 */
class OAuthConsentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthConsent::class);
    }

    public function findForClient(User $user, string $clientId): ?OAuthConsent
    {
        return $this->findOneBy(['user' => $user, 'clientId' => $clientId]);
    }
}
