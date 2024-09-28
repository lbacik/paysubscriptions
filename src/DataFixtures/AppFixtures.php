<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Factory\LimitsFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $userFactory = UserFactory::new();
        $userFactory->createMany(1, ['email' => 'foo@bar.com', 'isVerified' => true,]);
        $userFactory->createMany(1, ['email' => 'bar@bar.com', 'isVerified' => true,]);
        $userFactory->createMany(1, ['isVerified' => true,]);
        $userFactory->createMany(1, ['isVerified' => false,]);

        $limitFactory = LimitsFactory::new();
        $limitFactory->create(['user' => UserFactory::first(), 'subscriptions' => 50]);

        $subscriptionFactory = SubscriptionFactory::new();
        $subscriptionFactory->createMany(49, ['owner' => UserFactory::first()]);
        $subscriptionFactory->createMany(29, ['owner' => UserFactory::find(['email' => 'bar@bar.com'])]);

        $manager->flush();
    }
}
