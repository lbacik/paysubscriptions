<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Post;
use App\Entity\Subscription as SubscriptionEntity;
use App\Enum\BillingCycle;
use App\State\SubscriptionCollectionProvider;
use App\State\SubscriptionPendingReview;
use App\State\SubscriptionProcessor;
use App\Validator\CurrencyCode;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\TargetClass;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * API v1 Subscription: a DTO backed by the entity through stateOptions and
 * the object mapper (ADR 0007). Item reads use API Platform's Doctrine
 * provider, scoped to the token User by UserOwnedExtension, so a foreign
 * identifier is a 404; the collection uses a custom provider because the
 * price and renewal sorts are computed in SubscriptionService and cannot be
 * expressed as Doctrine ordering. Writes go through SubscriptionService in
 * SubscriptionProcessor, which reads this DTO (never the mapped entity) so
 * the client's intent stays visible: the supplied PATCH members and the
 * write-only confirmConvertedFor.
 *
 * Money travels as a decimal string ("15.99") in both directions; category
 * is an expense-category IRI and appears under _links.category in HAL. The
 * collection is paginated (25 per page, 1-based page) and sorted/filtered
 * through the `order` and `category` query parameters, validated in the
 * collection provider.
 */
#[ApiResource(
    description: "",
    operations: [
        // The api_v1_subscriptions_* route names are kept from the
        // hand-written controller so the CI route-name contract is stable
        // across the migration.
        new GetCollection(
            name: 'api_v1_subscriptions_list',
            provider: SubscriptionCollectionProvider::class,
            paginationItemsPerPage: 25,
            // The sort and category selectors are documented here for
            // generated clients, but never validated here: the collection
            // provider stays the only validator so violation paths and
            // messages stay stable.
            queryParameterValidationEnabled: false,
            parameters: [
                'order[name]' => new QueryParameter(
                    key: 'order[name]',
                    description: 'Sort by subscription name ("asc" or "desc"). Only one "order[field]" parameter may be sent at a time (otherwise 422). Default sort is name/asc, ties broken by name then id.',
                    schema: ['type' => 'string', 'enum' => ['asc', 'desc']],
                ),
                'order[price]' => new QueryParameter(
                    key: 'order[price]',
                    description: 'Sort by comparable price ("asc" or "desc"). Only one "order[field]" parameter may be sent at a time (otherwise 422). Default sort is name/asc, ties broken by name then id.',
                    schema: ['type' => 'string', 'enum' => ['asc', 'desc']],
                ),
                'order[renewal]' => new QueryParameter(
                    key: 'order[renewal]',
                    description: 'Sort by next renewal date ("asc" or "desc"). Only one "order[field]" parameter may be sent at a time (otherwise 422). Default sort is name/asc, ties broken by name then id.',
                    schema: ['type' => 'string', 'enum' => ['asc', 'desc']],
                ),
                'category' => new QueryParameter(
                    key: 'category',
                    description: 'Filter by ExpenseCategory UUID. A malformed UUID is a 422 on "category"; an unknown or foreign UUID matches nothing.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
            ],
        ),
        new Post(name: 'api_v1_subscriptions_create', processor: SubscriptionProcessor::class),
        new Get(name: 'api_v1_subscriptions_show'),
        new Patch(name: 'api_v1_subscriptions_update', processor: SubscriptionProcessor::class),
        new Delete(name: 'api_v1_subscriptions_delete', processor: SubscriptionProcessor::class),
    ],
    stateOptions: new Options(entityClass: SubscriptionEntity::class),
)]
#[Map(source: SubscriptionEntity::class, target: SubscriptionEntity::class)]
final class Subscription
{
    // Read-only members are copied entity -> DTO only, never DTO -> entity
    // (a plain `if: false` would block both directions).
    #[ApiProperty(identifier: true, writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?Uuid $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    public ?string $name = null;

    #[Assert\NotNull]
    public ?BillingCycle $billingCycle = null;

    #[Assert\NotNull]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'This value should be a decimal string like "15.99".')]
    #[Assert\Positive]
    #[Map(transform: [SubscriptionTransforms::class, 'mapAmount'])]
    public ?string $amount = null;

    #[Assert\NotNull]
    #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'This value should be a date in YYYY-MM-DD format.')]
    #[Assert\Callback([SubscriptionTransforms::class, 'validateRealDate'])]
    #[Map(transform: [SubscriptionTransforms::class, 'mapNextPayment'])]
    public ?string $nextPayment = null;

    #[CurrencyCode]
    public ?string $currency = null;

    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'This value should be a decimal string like "15.99".')]
    #[Assert\Positive]
    #[Map(transform: [SubscriptionTransforms::class, 'mapAmount'])]
    public ?string $convertedAmount = null;

    #[ApiProperty(writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?string $convertedCurrency = null;

    #[ApiProperty(writable: false)]
    #[Map(source: 'id', if: new TargetClass(self::class), transform: SubscriptionPendingReview::class)]
    public ?bool $pendingReview = null;

    // The relation must stay a resource type so HAL renders _links.category.
    // IRI resolution hands back the managed entity, so a dedicated
    // denormalizer converts the resolved entity to this DTO on input; input
    // mapping is skipped (the processor resolves the managed category from
    // the DTO's id itself), and output maps entity -> DTO through
    // SubscriptionTransforms::mapCategory.
    #[Map(if: new TargetClass(self::class), transform: [SubscriptionTransforms::class, 'mapCategory'])]
    public ?ExpenseCategory $category = null;

    // Write-only: never mapped (the entity has no such property) and never
    // serialized. The processor reads it from this DTO to review a converted
    // amount against the current main currency.
    #[ApiProperty(readable: false)]
    #[CurrencyCode]
    public ?string $confirmConvertedFor = null;

    #[Assert\Length(max: 2000)]
    public ?string $notes = null;

    #[ApiProperty(writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?\DateTimeInterface $createdAt = null;

    #[ApiProperty(writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?\DateTimeInterface $updatedAt = null;
}
