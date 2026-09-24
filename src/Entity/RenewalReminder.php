<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReminderStatus;
use App\Repository\RenewalReminderRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Durable send identity for one scheduled forecast-renewal reminder.
 *
 * The (subscription, renewalDate) pair is unique: retries and concurrent
 * workers claiming the same scheduled renewal hit the same row instead of
 * sending a second message. The row carries no Subscription details (no
 * name, amount, or email) so delivery logs and failure queues stay free of
 * financial data; only identifiers needed to correlate a failure.
 */
#[ORM\Entity(repositoryClass: RenewalReminderRepository::class)]
#[ORM\Table(name: 'renewal_reminder')]
#[ORM\UniqueConstraint(name: 'UNIQ_RENEWAL_REMINDER_SUBSCRIPTION_DATE', fields: ['subscription', 'renewalDate'])]
class RenewalReminder
{
    use Timestampable;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $renewalDate = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ReminderStatus::class)]
    private ReminderStatus $status = ReminderStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected $createdAt;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
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

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(Subscription $subscription): static
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getRenewalDate(): ?\DateTimeImmutable
    {
        return $this->renewalDate;
    }

    public function setRenewalDate(\DateTimeImmutable $renewalDate): static
    {
        $this->renewalDate = $renewalDate->setTime(0, 0, 0);

        return $this;
    }

    public function getStatus(): ReminderStatus
    {
        return $this->status;
    }

    public function setStatus(ReminderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }
}
