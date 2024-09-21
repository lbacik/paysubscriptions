<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
//    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank]
    private ?\DateTimeInterface $firstPayment = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $monthly = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $yearly = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    public function getId(): ?int
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

    public function getFirstPayment(): ?\DateTimeInterface
    {
        return $this->firstPayment;
    }

    public function setFirstPayment(\DateTimeInterface $firstPayment): static
    {
        $this->firstPayment = $firstPayment;

        return $this;
    }

    public function getMonthly(): ?string
    {
        return $this->monthly;
    }

    public function setMonthly(?string $monthly): static
    {
        $this->monthly = $monthly;

        return $this;
    }

    public function getYearly(): ?string
    {
        return $this->yearly;
    }

    public function setYearly(?string $yearly): static
    {
        $this->yearly = $yearly;

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
        return round((float) ($this->getMonthly() ?? ((float) $this->getYearly() / 12)), 2);
    }

    public function getYearlyCalculated(): float
    {
        return round((float) ($this->getYearly() ?? ((float) $this->getMonthly() * 12)), 2);
    }
}
