<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Factory\UserFactory;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UpcomingRenewalsWidgetTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testWidgetOrdersNextFiveRenewalsByDateAndLinksEachToItsSubscription(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);

        // Six subscriptions: the widget must show only the five soonest, ordered by date.
        $leads = [30, 5, 10, 1, 20, 15];
        $ids = [];
        foreach ($leads as $index => $lead) {
            $subscription = $this->createSubscription(
                $client,
                $owner->_real(),
                sprintf('Sub %02d days', $lead),
                BillingCycle::Monthly,
                new \DateTime(sprintf('today +%d days', $lead)),
                10.00 + $index,
            );
            $ids[$lead] = (string) $subscription->getId();
        }

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $widget = $this->widgetSection((string) $client->getResponse()->getContent());

        $expectedOrder = [1, 5, 10, 15, 20];
        $positions = [];
        foreach ($expectedOrder as $lead) {
            $pos = strpos($widget, sprintf('Sub %02d days', $lead));
            self::assertNotFalse($pos, sprintf('Expected Sub %02d days in the widget', $lead));
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Renewals are not ordered by date');

        // The sixth, latest renewal stays out of the widget.
        self::assertStringNotContainsString('Sub 30 days', $widget);

        // Each shown renewal links to its Subscription.
        foreach ($expectedOrder as $lead) {
            self::assertStringContainsString('/subscription/'.$ids[$lead].'/edit', $widget);
        }
    }

    public function testWidgetShowsDateNameAndExpectedChargeAndLabelsThemAsForecasts(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $anchor = new \DateTime('today +7 days');
        $this->createSubscription(
            $client,
            $owner->_real(),
            'Forecast Netflix',
            BillingCycle::Monthly,
            $anchor,
            15.99,
        );

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Forecast Netflix', $content);
        self::assertStringContainsString($anchor->format('Y-m-d'), $content);
        self::assertStringContainsString('15.99', $content);
        // The surface must clearly label these as forecasts, not recorded transactions.
        self::assertStringContainsString('Forecast', $content);
    }

    public function testWidgetShowsOnlySignedInUsersSubscriptions(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $other = UserFactory::createOne(['email' => 'other@example.com', 'isVerified' => true]);

        $this->createSubscription(
            $client,
            $owner->_real(),
            'Owner Sub',
            BillingCycle::Monthly,
            new \DateTime('today +3 days'),
            9.99,
        );
        $this->createSubscription(
            $client,
            $other->_real(),
            'Stranger Sub',
            BillingCycle::Monthly,
            new \DateTime('today +1 day'),
            5.00,
        );

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Owner Sub', $content);
        self::assertStringNotContainsString('Stranger Sub', $content);
    }

    public function testWidgetRendersWithoutAnyReminderOptIn(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $this->createSubscription(
            $client,
            $owner->_real(),
            'Always Visible',
            BillingCycle::Yearly,
            new \DateTime('today +9 days'),
            99.00,
        );

        // No email reminder preference is ever configured: the widget must
        // still show the forecast renewal.
        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="upcoming-renewals"', $content);
        self::assertStringContainsString('Always Visible', $content);
    }

    public function testEmptyStateExplainsHowToAddSubscription(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['email' => 'fresh@example.com', 'isVerified' => true]);

        $this->loginAs($client, 'fresh@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="upcoming-renewals"', $content);
        self::assertStringContainsString('/subscription/new', $content);
    }

    private function createSubscription(
        KernelBrowser $client,
        User $owner,
        string $name,
        BillingCycle $cycle,
        \DateTimeInterface $nextPayment,
        float $amount,
    ): Subscription {
        $managedOwner = $this->managedUser($client, $owner);
        $categoryService = $client->getContainer()->get(ExpenseCategoryService::class);

        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($cycle)
            ->setNextPayment($nextPayment)
            ->setAmount($amount)
            ->setOwner($managedOwner)
            ->setCategory($categoryService->ensureDefaultCategory($managedOwner));

        $client->getContainer()->get(SubscriptionRepository::class)->save($subscription);

        return $subscription;
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $client->loginUser($this->managedUser($client, $email));
    }

    /**
     * Returns the widget's own section so assertions stay scoped to it: the
     * subscriptions table below lists every Subscription, including ones the
     * widget deliberately leaves out.
     */
    private function widgetSection(string $content): string
    {
        $start = strpos($content, 'id="upcoming-renewals"');
        self::assertNotFalse($start, 'Upcoming renewals widget not found on the dashboard');
        $end = strpos($content, '</section>', $start);
        self::assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }

    private function managedUser(KernelBrowser $client, User|string $user): User
    {
        // The client's kernel reboots between requests, so always resolve
        // entities from its current container rather than a stale one.
        $repository = $client->getContainer()->get(UserRepository::class);
        $managed = $user instanceof User
            ? $repository->find($user->getId())
            : $repository->findOneBy(['email' => $user]);

        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }
}
