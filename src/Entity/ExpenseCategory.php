<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Prototype\EntityCategoryProcessor;
use App\Repository\ExpenseCategoryRepository;
use Symfony\Component\Serializer\Attribute\Groups;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

// PROTOTYPE (#246) variant A: the Doctrine entity is the API resource.
#[ApiResource(
    shortName: 'ProtoEntityExpenseCategory',
    operations: [
        new GetCollection(uriTemplate: '/v1/proto-entity/expense-categories', paginationEnabled: false, order: ['name' => 'ASC']),
        new Get(uriTemplate: '/v1/proto-entity/expense-categories/{id}'),
        new Post(uriTemplate: '/v1/proto-entity/expense-categories', processor: EntityCategoryProcessor::class),
        new Patch(uriTemplate: '/v1/proto-entity/expense-categories/{id}', processor: EntityCategoryProcessor::class),
        new Delete(uriTemplate: '/v1/proto-entity/expense-categories/{id}', processor: EntityCategoryProcessor::class),
    ],
    normalizationContext: ['groups' => ['proto_category:read']],
    denormalizationContext: ['groups' => ['proto_category:write']],
)]
#[ORM\Entity(repositoryClass: ExpenseCategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EXPENSE_CATEGORY_OWNER_NAME', fields: ['owner', 'name'])]
#[UniqueEntity(fields: ['owner', 'name'], message: 'You already have a category with this name.')]
class ExpenseCategory
{
    use Timestampable;

    public const DEFAULT_NAME = 'Subscriptions';

    public const DEFAULT_COLOR = '#577399';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['proto_category:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'expenseCategories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[Groups(['proto_category:read', 'proto_category:write'])]
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private ?string $name = null;

    #[Groups(['proto_category:read', 'proto_category:write'])]
    #[ORM\Column(length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^#[0-9a-fA-F]{6}$/',
        message: 'Please provide a color tag in hex format, e.g. #577399.',
    )]
    private ?string $color = null;

    /**
     * @var Collection<int, Subscription>
     */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'category')]
    private Collection $subscriptions;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    #[Groups(['proto_category:read'])]
    protected $createdAt;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    #[Groups(['proto_category:read'])]
    protected $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();

        $this->subscriptions = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        if (null !== $this->owner && null !== $owner && $this->owner !== $owner && !$this->isOwnedBy($owner)) {
            // Re-parenting a persisted category would silently break the
            // (owner, name) uniqueness invariant for both the old and the new
            // owner; categories are never moved between accounts.
            throw new \LogicException('An expense category cannot be moved to a different owner.');
        }

        $this->owner = $owner;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function addSubscription(Subscription $subscription): static
    {
        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
            $subscription->setCategory($this);
        }

        return $this;
    }

    public function removeSubscription(Subscription $subscription): static
    {
        $this->subscriptions->removeElement($subscription);

        return $this;
    }

    public function isOwnedBy(User $user): bool
    {
        if (null === $this->owner) {
            return false;
        }

        if (null !== $this->owner->getId() && null !== $user->getId()) {
            return $this->owner->getId()->equals($user->getId());
        }

        if ($this->owner === $user) {
            return true;
        }

        // At least one side is unpersisted (no id yet): fall back to the
        // unique account identifier — but two empty identifiers must never
        // compare equal, or any two email-less users would own everything.
        $mine = $this->owner->getUserIdentifier();
        $theirs = $user->getUserIdentifier();

        return '' !== $mine && $mine === $theirs;
    }
}
