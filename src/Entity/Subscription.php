<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingCycle;
use App\Repository\SubscriptionRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
    use Timestampable;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: BillingCycle::class)]
    #[Assert\NotNull]
    private ?BillingCycle $billingCycle = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeInterface $nextPayment = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    protected $createdAt;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    protected $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getBillingCycle(): ?BillingCycle
    {
        return $this->billingCycle;
    }

    public function setBillingCycle(BillingCycle $billingCycle): static
    {
        $this->billingCycle = $billingCycle;

        return $this;
    }

    public function isMonthly(): bool
    {
        return $this->billingCycle === BillingCycle::Monthly;
    }

    public function isYearly(): bool
    {
        return $this->billingCycle === BillingCycle::Yearly;
    }

    public function getAmount(): ?float
    {
        return $this->amount !== null ? (float) $this->amount : null;
    }

    public function setAmount(?float $amount): static
    {
        $this->amount = $amount !== null ? (string) $amount : null;

        return $this;
    }

    public function getNextPayment(): ?\DateTimeInterface
    {
        return $this->nextPayment;
    }

    public function setNextPayment(\DateTimeInterface $nextPayment): static
    {
        $this->nextPayment = $nextPayment;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getMonthlyCalculated(): float
    {
        if (null === $this->billingCycle || null === $this->amount) {
            throw new \LogicException('Cannot calculate a monthly amount before billingCycle and amount are set.');
        }

        return round($this->isMonthly() ? $this->getAmount() : $this->getAmount() / 12, 2);
    }

    public function getYearlyCalculated(): float
    {
        if (null === $this->billingCycle || null === $this->amount) {
            throw new \LogicException('Cannot calculate a yearly amount before billingCycle and amount are set.');
        }

        return round($this->isYearly() ? $this->getAmount() : $this->getAmount() * 12, 2);
    }
}
