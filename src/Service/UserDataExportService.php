<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the machine-readable payload for a manual-request data export
 * (issue #48).
 *
 * Scope is deliberately narrow: the requesting User's own account profile,
 * Subscriptions (with their ExpenseCategory), ExpenseCategories, and Limits.
 * Credentials (password hash), roles, password-reset tokens, reminder
 * send-state, messenger queue rows, and every other User's records are never
 * included. The newsletter list is a separate opt-in on an external provider
 * and is neither exported nor touched.
 */
class UserDataExportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{format: string, version: int, exportedAt: string, account: array<string, mixed>, subscriptions: list<array<string, mixed>>, expenseCategories: list<array<string, mixed>>, limits: array<string, mixed>}
     */
    public function export(User $user): array
    {
        $email = (string) $user->getEmail();

        return [
            'format' => 'paysubscriptions-user-export',
            'version' => 1,
            'exportedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'account' => [
                'email' => $email,
                'isVerified' => $user->isVerified(),
                'mainCurrency' => $user->getMainCurrency(),
                'timezone' => $user->getTimezone(),
                'emailRemindersEnabled' => $user->isEmailRemindersEnabled(),
                'reminderLeadDays' => $user->getReminderLeadDays(),
                'createdAt' => self::formatDateTime($user->getCreatedAt()),
                'updatedAt' => self::formatDateTime($user->getUpdatedAt()),
            ],
            'subscriptions' => array_map(
                fn (Subscription $subscription): array => $this->exportSubscription($subscription, $email),
                $this->orderedSubscriptions($user)
            ),
            'expenseCategories' => array_map(
                self::exportCategory(...),
                $this->orderedCategories($user)
            ),
            'limits' => [
                'subscriptions' => $user->getSubscriptionsLimit(),
            ],
        ];
    }

    /**
     * @return list<Subscription>
     */
    private function orderedSubscriptions(User $user): array
    {
        $subscriptions = $this->entityManager
            ->getRepository(Subscription::class)
            ->findBy(['owner' => $user], ['name' => 'ASC']);

        \assert(\is_array($subscriptions));

        return $subscriptions;
    }

    /**
     * @return list<ExpenseCategory>
     */
    private function orderedCategories(User $user): array
    {
        $categories = $this->entityManager
            ->getRepository(ExpenseCategory::class)
            ->findBy(['owner' => $user], ['name' => 'ASC']);

        \assert(\is_array($categories));

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportSubscription(Subscription $subscription, string $ownerEmail): array
    {
        $category = $subscription->getCategory();

        return [
            'id' => (string) $subscription->getId(),
            'ownerEmail' => $ownerEmail,
            'name' => $subscription->getName(),
            'billingCycle' => $subscription->getBillingCycle()?->value,
            'amount' => $subscription->getAmount(),
            'currency' => $subscription->getCurrency(),
            'convertedAmount' => $subscription->getConvertedAmount(),
            'convertedCurrency' => $subscription->getConvertedCurrency(),
            'nextPayment' => $subscription->getNextPayment()?->format('Y-m-d'),
            'notes' => $subscription->getNotes(),
            'category' => null === $category ? null : [
                'name' => $category->getName(),
                'color' => $category->getColor(),
            ],
            'createdAt' => self::formatDateTime($subscription->getCreatedAt()),
            'updatedAt' => self::formatDateTime($subscription->getUpdatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function exportCategory(ExpenseCategory $category): array
    {
        return [
            'id' => (string) $category->getId(),
            'name' => $category->getName(),
            'color' => $category->getColor(),
            'createdAt' => self::formatDateTime($category->getCreatedAt()),
            'updatedAt' => self::formatDateTime($category->getUpdatedAt()),
        ];
    }

    private static function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return null;
    }
}
