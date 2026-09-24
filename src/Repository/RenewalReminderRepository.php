<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RenewalReminder;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RenewalReminder>
 */
class RenewalReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RenewalReminder::class);
    }

    public function findForSubscriptionOnDate(Subscription $subscription, \DateTimeInterface $renewalDate): ?RenewalReminder
    {
        return $this->findOneBy([
            'subscription' => $subscription,
            'renewalDate' => $renewalDate->format('Y-m-d'),
        ]);
    }

    /**
     * @return list<RenewalReminder>
     */
    public function findNeedingReview(): array
    {
        return $this->findBy(['status' => \App\Enum\ReminderStatus::NeedsReview], ['createdAt' => 'ASC']);
    }
}
