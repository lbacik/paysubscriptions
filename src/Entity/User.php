<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use Timestampable;

    public const DEFAULT_TIMEZONE = 'UTC';
    public const DEFAULT_REMINDER_LEAD_DAYS = 3;
    public const MIN_REMINDER_LEAD_DAYS = 1;
    public const MAX_REMINDER_LEAD_DAYS = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isVerified = false;

    /**
     * Account time zone as an IANA identifier (e.g. "Europe/Warsaw").
     * Initially detected from the browser; the User can correct it in settings.
     * Reminder lead times are measured in calendar days in this zone.
     */
    #[ORM\Column(length: 64, options: ['default' => self::DEFAULT_TIMEZONE])]
    #[Assert\NotBlank]
    #[Assert\Timezone]
    private ?string $timezone = self::DEFAULT_TIMEZONE;

    /**
     * Global opt-in for email reminders. The in-app upcoming-renewals view
     * stays available regardless of this flag.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $emailRemindersEnabled = false;

    /**
     * Global email-reminder lead time in calendar days.
     */
    #[ORM\Column(options: ['default' => self::DEFAULT_REMINDER_LEAD_DAYS])]
    #[Assert\Range(min: self::MIN_REMINDER_LEAD_DAYS, max: self::MAX_REMINDER_LEAD_DAYS)]
    private int $reminderLeadDays = self::DEFAULT_REMINDER_LEAD_DAYS;

    /**
     * @var Collection<int, Subscription>
     */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'owner', orphanRemoval: true)]
    private Collection $subscriptions;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Limits $limits = null;

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

        $this->subscriptions = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function setIsVerified(bool $isVerified): static
    {
        return $this->setVerified($isVerified);
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function isEmailRemindersEnabled(): bool
    {
        return $this->emailRemindersEnabled;
    }

    public function setEmailRemindersEnabled(bool $emailRemindersEnabled): static
    {
        $this->emailRemindersEnabled = $emailRemindersEnabled;

        return $this;
    }

    public function getReminderLeadDays(): int
    {
        return $this->reminderLeadDays;
    }

    public function setReminderLeadDays(int $reminderLeadDays): static
    {
        $this->reminderLeadDays = $reminderLeadDays;

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
            $subscription->setOwner($this);
        }

        return $this;
    }

    public function removeSubscription(Subscription $subscription): static
    {
        if ($this->subscriptions->removeElement($subscription)) {
            // set the owning side to null (unless already changed)
            if ($subscription->getOwner() === $this) {
                $subscription->setOwner(null);
            }
        }

        return $this;
    }

    public function getLimits(): ?Limits
    {
        return $this->limits;
    }

    public function setLimits(Limits $limits): static
    {
        // set the owning side of the relation if necessary
        if ($limits->getUser() !== $this) {
            $limits->setUser($this);
        }

        $this->limits = $limits;

        return $this;
    }

    public function getSubscriptionsLimit(): int
    {
        return $this->limits?->getSubscriptions() ?? Limits::DEFAULT_SUBSCRIPTIONS_LIMIT;
    }

    public function setSubscriptionsLimit(int $limit): static
    {
        $this->getLimits()
            ? $this->limits->setSubscriptions($limit)
            : $this->setLimits((new Limits($this))->setSubscriptions($limit));

        return $this;
    }
}
