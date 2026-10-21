<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\ExpenseCategoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixture helpers shared by the database-backed base classes
 * (App\Tests\DatabaseTestCase for web tests, App\Tests\Api\V1\ApiV1TestCase
 * for API v1 tests). The using class boots the kernel and sets $em in setUp().
 */
trait DatabaseFixtures
{
    protected EntityManagerInterface $em;

    protected function freshEm(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, string $plainPassword = 'Fixture-Password-1', bool $verified = true): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setVerified($verified);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createSubscription(
        User $owner,
        string $name = 'Netflix',
        BillingCycle $billingCycle = BillingCycle::Monthly,
        float $amount = 15.99,
        ?\DateTimeInterface $nextPayment = null,
    ): Subscription {
        // Resolved before building the Subscription: ensureDefaultCategory()
        // flushes internally, which would otherwise trip Doctrine's cascade
        // check on the not-yet-persisted Subscription reachable through
        // User#subscriptions.
        $category = static::getContainer()->get(ExpenseCategoryService::class)->ensureDefaultCategory($owner);

        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($billingCycle)
            ->setAmount($amount)
            ->setNextPayment($nextPayment ?? new \DateTime('2024-01-15'))
            ->setCategory($category);
        // Keep both sides of the association in sync: the subscription-limit
        // check counts the owner's in-memory collection, so setOwner() alone
        // would leave it stale within the same entity-manager lifecycle.
        $owner->addSubscription($subscription);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    protected function freshUser(string $email): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
