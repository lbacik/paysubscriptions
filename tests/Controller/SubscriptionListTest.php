<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Factory\ExpenseCategoryFactory;
use App\Factory\UserFactory;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Category filtering and name/price/renewal sorting of the Subscription list
 * (issue #43), driven through the dashboard like a User: combinations, empty
 * results, session survival across navigation and Turbo updates, and two-User
 * isolation.
 */
final class SubscriptionListTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testCategoryFilterShowsOnlyMatchingSubscriptions(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $music = $this->createCategory($owner->_real(), 'Music');
        $this->createSubscription($client, $owner->_real(), $video, 'AlphaFlix', BillingCycle::Monthly, '2024-01-05', 10.0);
        $this->createSubscription($client, $owner->_real(), $video, 'BetaFlix', BillingCycle::Monthly, '2024-01-06', 20.0);
        $this->createSubscription($client, $owner->_real(), $music, 'GammaTunes', BillingCycle::Monthly, '2024-01-07', 30.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?category='.(string) $video->getId());

        self::assertResponseIsSuccessful();
        $table = $this->tableSection((string) $client->getResponse()->getContent());

        self::assertStringContainsString('AlphaFlix', $table);
        self::assertStringContainsString('BetaFlix', $table);
        self::assertStringNotContainsString('GammaTunes', $table);
    }

    public function testFilterOptionsAreScopedToSignedInUser(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $stranger = UserFactory::createOne(['email' => 'stranger@example.com', 'isVerified' => true]);
        $own = $this->createCategory($owner->_real(), 'Own Video');
        $this->createCategory($stranger->_real(), 'Stranger Music');

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $select = $this->filterSelect($content);

        self::assertStringContainsString('Own Video', $select);
        self::assertStringContainsString((string) $own->getId(), $select);
        self::assertStringNotContainsString('Stranger Music', $select);
    }

    public function testForgedForeignCategoryShowsEmptyAndLeaksNothing(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $stranger = UserFactory::createOne(['email' => 'stranger@example.com', 'isVerified' => true]);
        $ownCat = $this->createCategory($owner->_real(), 'Own Video');
        $foreignCat = $this->createCategory($stranger->_real(), 'Stranger Music');
        $this->createSubscription($client, $owner->_real(), $ownCat, 'OwnFlix', BillingCycle::Monthly, '2024-01-05', 10.0);
        $this->createSubscription($client, $stranger->_real(), $foreignCat, 'StrangerFlix', BillingCycle::Monthly, '2024-01-05', 10.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?category='.(string) $foreignCat->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $table = $this->tableSection($content);

        self::assertStringNotContainsString('StrangerFlix', $content);
        self::assertStringNotContainsString('OwnFlix', $table);
        self::assertStringContainsString('No subscriptions found.', $table);
        // The forged id is never offered back as a selected option: the
        // dropdown falls back to "All categories" while the list stays empty.
        $select = $this->filterSelect($content);
        self::assertStringNotContainsString((string) $foreignCat->getId(), $select);
        self::assertStringContainsString('<option value="all" selected>', $select);
    }

    public function testTwoUserIsolation(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $stranger = UserFactory::createOne(['email' => 'stranger@example.com', 'isVerified' => true]);
        $ownCat = $this->createCategory($owner->_real(), 'Own Video');
        $foreignCat = $this->createCategory($stranger->_real(), 'Stranger Music');
        $this->createSubscription($client, $owner->_real(), $ownCat, 'OwnFlix', BillingCycle::Monthly, '2024-01-05', 10.0);
        $this->createSubscription($client, $stranger->_real(), $foreignCat, 'StrangerFlix', BillingCycle::Monthly, '2024-01-05', 10.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('OwnFlix', $this->tableSection($content));
        self::assertStringNotContainsString('StrangerFlix', $content);
        self::assertStringNotContainsString('Stranger Music', $content);
    }

    public function testSortByNameSupportsBothDirections(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $category = $this->createCategory($owner->_real(), 'Video');
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            $this->createSubscription($client, $owner->_real(), $category, $name, BillingCycle::Monthly, '2024-01-05', 10.0);
        }

        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard?sort=name&order=asc');
        self::assertTableOrder($this->tableSection((string) $client->getResponse()->getContent()), ['Alpha', 'Bravo', 'Charlie']);

        $client->request('GET', '/dashboard?sort=name&order=desc');
        self::assertTableOrder($this->tableSection((string) $client->getResponse()->getContent()), ['Charlie', 'Bravo', 'Alpha']);
    }

    public function testSortByPriceUsesConvertedAmountsInMainCurrency(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true, 'mainCurrency' => 'USD']);
        $category = $this->createCategory($owner->_real(), 'Video');

        // EUR 10.00 converted by hand to USD 11.00; USD 120.00 yearly is USD 10.00 monthly.
        $this->createSubscription($client, $owner->_real(), $category, 'Same Pricey', BillingCycle::Monthly, '2024-01-05', 12.0, 'USD');
        $this->createSubscription($client, $owner->_real(), $category, 'Cross Mid', BillingCycle::Monthly, '2024-01-05', 10.0, 'EUR', 11.0, 'USD');
        $this->createSubscription($client, $owner->_real(), $category, 'Yearly Cheap', BillingCycle::Yearly, '2024-01-05', 120.0, 'USD');

        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard?sort=price&order=asc');
        self::assertTableOrder(
            $this->tableSection((string) $client->getResponse()->getContent()),
            ['Yearly Cheap', 'Cross Mid', 'Same Pricey'],
        );

        $client->request('GET', '/dashboard?sort=price&order=desc');
        self::assertTableOrder(
            $this->tableSection((string) $client->getResponse()->getContent()),
            ['Same Pricey', 'Cross Mid', 'Yearly Cheap'],
        );
    }

    public function testSortByRenewalSupportsBothDirections(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $category = $this->createCategory($owner->_real(), 'Video');

        $this->createSubscription($client, $owner->_real(), $category, 'Far Renewal', BillingCycle::Monthly, 'today +20 days', 10.0);
        $this->createSubscription($client, $owner->_real(), $category, 'Soon Renewal', BillingCycle::Monthly, 'today +5 days', 10.0);
        $this->createSubscription($client, $owner->_real(), $category, 'Mid Renewal', BillingCycle::Monthly, 'today +10 days', 10.0);

        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard?sort=renewal&order=asc');
        self::assertTableOrder(
            $this->tableSection((string) $client->getResponse()->getContent()),
            ['Soon Renewal', 'Mid Renewal', 'Far Renewal'],
        );

        $client->request('GET', '/dashboard?sort=renewal&order=desc');
        self::assertTableOrder(
            $this->tableSection((string) $client->getResponse()->getContent()),
            ['Far Renewal', 'Mid Renewal', 'Soon Renewal'],
        );
    }

    public function testTiesBreakByNameInBothDirections(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $category = $this->createCategory($owner->_real(), 'Video');

        // Identical price (12.00 monthly vs 144.00 yearly) and identical
        // renewal date: name order wins regardless of direction.
        $this->createSubscription($client, $owner->_real(), $category, 'Tie Beta', BillingCycle::Monthly, 'today +7 days', 12.0);
        $this->createSubscription($client, $owner->_real(), $category, 'Tie Alpha', BillingCycle::Yearly, 'today +7 days', 144.0);

        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard?sort=price&order=desc');
        self::assertTableOrder(
            $this->tableSection((string) $client->getResponse()->getContent()),
            ['Tie Alpha', 'Tie Beta'],
        );

        $client->request('GET', '/dashboard?sort=renewal&order=desc');
        self::assertTableOrder(
            $this->tableSection((string) $client->getResponse()->getContent()),
            ['Tie Alpha', 'Tie Beta'],
        );
    }

    public function testFilterAndSortCombine(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $music = $this->createCategory($owner->_real(), 'Music');
        $this->createSubscription($client, $owner->_real(), $video, 'Cheap Video', BillingCycle::Monthly, '2024-01-05', 5.0);
        $this->createSubscription($client, $owner->_real(), $video, 'Pricey Video', BillingCycle::Monthly, '2024-01-05', 50.0);
        $this->createSubscription($client, $owner->_real(), $music, 'Mid Music', BillingCycle::Monthly, '2024-01-05', 25.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?category='.(string) $video->getId().'&sort=price&order=desc');

        self::assertResponseIsSuccessful();
        $table = $this->tableSection((string) $client->getResponse()->getContent());

        self::assertTableOrder($table, ['Pricey Video', 'Cheap Video']);
        self::assertStringNotContainsString('Mid Music', $table);
    }

    public function testEmptyResultsExplainHowToClearTheFilter(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $empty = $this->createCategory($owner->_real(), 'Empty');
        $this->createSubscription($client, $owner->_real(), $video, 'Only Video', BillingCycle::Monthly, '2024-01-05', 10.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?category='.(string) $empty->getId());

        self::assertResponseIsSuccessful();
        $table = $this->tableSection((string) $client->getResponse()->getContent());

        self::assertStringNotContainsString('Only Video', $table);
        self::assertStringContainsString('No subscriptions found.', $table);
        self::assertStringContainsString('Clear filter', $table);
    }

    public function testStateSurvivesNavigationWithoutQueryParameters(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $music = $this->createCategory($owner->_real(), 'Music');
        $this->createSubscription($client, $owner->_real(), $video, 'Cheap Video', BillingCycle::Monthly, '2024-01-05', 5.0);
        $this->createSubscription($client, $owner->_real(), $video, 'Pricey Video', BillingCycle::Monthly, '2024-01-05', 50.0);
        $this->createSubscription($client, $owner->_real(), $music, 'Mid Music', BillingCycle::Monthly, '2024-01-05', 25.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?category='.(string) $video->getId().'&sort=price&order=desc');
        self::assertResponseIsSuccessful();

        // Plain navigation re-applies the remembered filter and sort.
        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $table = $this->tableSection((string) $client->getResponse()->getContent());

        self::assertTableOrder($table, ['Pricey Video', 'Cheap Video']);
        self::assertStringNotContainsString('Mid Music', $table);
    }

    public function testSortLinksPreserveTheCategoryFilter(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $this->createSubscription($client, $owner->_real(), $video, 'Only Video', BillingCycle::Monthly, '2024-01-05', 10.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?category='.(string) $video->getId());

        self::assertResponseIsSuccessful();
        $table = $this->tableSection((string) $client->getResponse()->getContent());

        self::assertStringContainsString('category='.(string) $video->getId(), $table);
    }

    public function testTurboStreamKeepsFilterAndSort(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $music = $this->createCategory($owner->_real(), 'Music');
        $this->createSubscription($client, $owner->_real(), $video, 'AAA Stream', BillingCycle::Monthly, '2024-01-05', 5.0);
        $this->createSubscription($client, $owner->_real(), $video, 'ZZZ Stream', BillingCycle::Monthly, '2024-01-06', 15.0);
        $this->createSubscription($client, $owner->_real(), $music, 'MMM Other', BillingCycle::Monthly, '2024-01-07', 25.0);

        $this->loginAs($client, 'owner@example.com');
        // Prime the remembered list state.
        $client->request('GET', '/dashboard?category='.(string) $video->getId().'&sort=name&order=desc');
        self::assertResponseIsSuccessful();

        // Create through the Turbo modal, like the dashboard does.
        $client->request('GET', '/subscription/new');
        self::assertResponseIsSuccessful();
        $form = $client->getCrawler()->selectButton('Save')->form();
        $form['subscription[name]'] = 'NNN Fresh';
        $form['subscription[billingCycle]'] = BillingCycle::Monthly->value;
        $form['subscription[amount]'] = '9.99';
        $form['subscription[nextPayment]'] = (new \DateTime('today +30 days'))->format('Y-m-d');
        $form['subscription[category]'] = (string) $video->getId();
        $client->submit($form, [], ['HTTP_TURBO_FRAME' => 'modal']);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('subscriptions-table', $content);
        self::assertTableOrder($content, ['ZZZ Stream', 'NNN Fresh', 'AAA Stream']);
        self::assertStringNotContainsString('MMM Other', $content);
    }

    public function testYearlyChartRendersWithFilterActive(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com', 'isVerified' => true]);
        $video = $this->createCategory($owner->_real(), 'Video');
        $music = $this->createCategory($owner->_real(), 'Music');
        $this->createSubscription($client, $owner->_real(), $video, 'Only Video', BillingCycle::Yearly, '2024-01-05', 120.0);
        $this->createSubscription($client, $owner->_real(), $music, 'Only Music', BillingCycle::Monthly, '2024-01-05', 10.0);

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard?chartType=yearly&category='.(string) $video->getId());

        self::assertResponseIsSuccessful();
        $table = $this->tableSection((string) $client->getResponse()->getContent());
        self::assertStringContainsString('Only Video', $table);
        self::assertStringNotContainsString('Only Music', $table);
    }

    private static function assertTableOrder(string $table, array $names): void
    {
        $positions = [];

        foreach ($names as $name) {
            $position = strpos($table, $name);
            self::assertNotFalse($position, sprintf('Expected "%s" in the subscription table', $name));
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, sprintf(
            'Expected table order [%s]',
            implode(', ', $names),
        ));
    }

    /**
     * Returns only the subscriptions table so similarly-named renewals in the
     * widget above cannot disturb order assertions.
     */
    private function tableSection(string $content): string
    {
        $start = strpos($content, '<table id="subscriptions-table"');
        self::assertNotFalse($start, 'Subscriptions table not found on the dashboard');
        $end = strpos($content, '</table>', $start);
        self::assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }

    private function filterSelect(string $content): string
    {
        $start = strpos($content, 'id="category-filter"');
        self::assertNotFalse($start, 'Category filter not found on the dashboard');
        $open = strrpos(substr($content, 0, $start), '<select');
        self::assertNotFalse($open);
        $end = strpos($content, '</select>', $start);
        self::assertNotFalse($end);

        return substr($content, $open, $end - $open);
    }

    private function createCategory(User $owner, string $name): ExpenseCategory
    {
        return ExpenseCategoryFactory::createOne([
            'owner' => $owner,
            'name' => $name,
            'color' => '#577399',
        ])->_real();
    }

    private function createSubscription(
        KernelBrowser $client,
        User $owner,
        ExpenseCategory $category,
        string $name,
        BillingCycle $cycle,
        string $nextPayment,
        float $amount,
        ?string $currency = null,
        ?float $convertedAmount = null,
        ?string $convertedCurrency = null,
    ): Subscription {
        $managedOwner = $this->managedUser($client, $owner);
        $managedCategory = $client->getContainer()->get(ExpenseCategoryRepository::class)->find($category->getId());
        self::assertInstanceOf(ExpenseCategory::class, $managedCategory);

        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($cycle)
            ->setNextPayment(new \DateTime($nextPayment))
            ->setAmount($amount)
            ->setOwner($managedOwner)
            ->setCategory($managedCategory);

        if ($currency !== null) {
            $subscription->setCurrency($currency);
        }

        if ($convertedAmount !== null) {
            $subscription->setConvertedAmount($convertedAmount);
            $subscription->setConvertedCurrency($convertedCurrency);
        }

        $client->getContainer()->get(SubscriptionRepository::class)->save($subscription);

        return $subscription;
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $client->loginUser($this->managedUser($client, $email));
    }

    private function managedUser(KernelBrowser $client, User|string $user): User
    {
        $repository = $client->getContainer()->get(UserRepository::class);
        $managed = $user instanceof User
            ? $repository->find($user->getId())
            : $repository->findOneBy(['email' => $user]);

        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }
}
