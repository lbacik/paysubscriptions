<?php

declare(strict_types=1);

namespace App\DataFixtures;

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
        $userFactory->createMany(1, ['isVerified' => true,]);
        $userFactory->createMany(1, ['isVerified' => false,]);

        $subscriptionFactory = SubscriptionFactory::new();
        $subscriptionFactory->createMany(20, ['owner' => UserFactory::first()]);

        $manager->flush();
    }
}
