<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\User;
use App\Service\ExpenseCategoryService;
use Symfony\Component\Uid\Uuid;

final class ScratchSubTest extends ApiV1TestCase
{
    public function testScratch(): void
    {
        $user = $this->createUser('scratch-user@example.com');
        $other = $this->createUser('scratch-other@example.com');
        /** @var ExpenseCategoryService $cats */
        $cats = static::getContainer()->get(ExpenseCategoryService::class);
        $own = $cats->create($user, 'Own', '#111111');
        $foreign = $cats->create($other, 'Foreign', '#222222');
        $ownIri = '/api/v1/expense-categories/'.$own->getId();
        $foreignIri = '/api/v1/expense-categories/'.$foreign->getId();
        $unknownIri = '/api/v1/expense-categories/'.Uuid::v4();

        $base = [
            'name' => 'Netflix',
            'billingCycle' => 'monthly',
            'amount' => '15.99',
            'nextPayment' => '2024-02-15',
        ];

        $this->probe($user, 'POST valid own category', 'POST', '/api/v1/subscriptions', ['json' => $base + ['category' => $ownIri]]);
        $this->probe($user, 'POST foreign category', 'POST', '/api/v1/subscriptions', ['json' => $base + ['category' => $foreignIri]]);
        $this->probe($user, 'POST unknown category', 'POST', '/api/v1/subscriptions', ['json' => $base + ['category' => $unknownIri]]);
        $this->probe($user, 'POST malformed category', 'POST', '/api/v1/subscriptions', ['json' => $base + ['category' => '/api/v1/expense-categories/not-a-uuid']]);
        $this->probe($user, 'POST omitted category', 'POST', '/api/v1/subscriptions', ['json' => $base]);
        $this->probe($user, 'GET foreign subscription', 'GET', '/api/v1/subscriptions/'.Uuid::v4());
        $this->probe($user, 'GET collection', 'GET', '/api/v1/subscriptions');

        self::assertTrue(true);
    }

    private function probe(User $user, string $label, string $method, string $url, array $options = []): void
    {
        $response = $this->requestAs($user, $method, $url, $options);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        fwrite(STDERR, "\n### {$label}: {$status}\n{$body}\n");
    }
}
