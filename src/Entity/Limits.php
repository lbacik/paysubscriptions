<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LimitsRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: LimitsRepository::class)]
class Limits
{
    use Timestampable;

    public const DEFAULT_SUBSCRIPTIONS_LIMIT = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column]
    private ?int $subscriptions = null;

    #[ORM\OneToOne(inversedBy: 'limits', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    protected $createdAt;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    protected $updatedAt;

    public function __construct(User $user)
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();

        $this->subscriptions = self::DEFAULT_SUBSCRIPTIONS_LIMIT;
        $this->user = $user;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSubscriptions(): ?int
    {
        return $this->subscriptions;
    }

    public function setSubscriptions(int $subscriptions): static
    {
        $this->subscriptions = $subscriptions;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }
}
