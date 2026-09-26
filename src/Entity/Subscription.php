<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingCycle;
use App\Repository\SubscriptionRepository;
use App\Service\CurrencyService;
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

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $convertedAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $convertedCurrency = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $notes = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: ExpenseCategory::class, inversedBy: 'subscriptions', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?ExpenseCategory $category = null;

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

    public function setNextPayment(?\DateTimeInterface $nextPayment): static
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

    public function getCategory(): ?ExpenseCategory
    {
        return $this->category;
    }

    public function setCategory(?ExpenseCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = CurrencyService::normalizeCode($currency);

        return $this;
    }

    public function getConvertedAmount(): ?float
    {
        return $this->convertedAmount !== null ? (float) $this->convertedAmount : null;
    }

    public function setConvertedAmount(?float $convertedAmount): static
    {
        $this->convertedAmount = $convertedAmount !== null ? (string) $convertedAmount : null;

        return $this;
    }

    public function getConvertedCurrency(): ?string
    {
        return $this->convertedCurrency;
    }

    public function setConvertedCurrency(?string $convertedCurrency): static
    {
        $this->convertedCurrency = CurrencyService::normalizeCode($convertedCurrency);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes !== null && trim($notes) === '' ? null : $notes;

        return $this;
    }

    public function hasConvertedAmount(): bool
    {
        return $this->getConvertedAmount() !== null;
    }

    /**
     * Whether this Subscription is priced in a currency other than the given
     * main currency. Null on either side means "unknown" (legacy data or a
     * User who has not confirmed a main currency yet): never cross-currency,
     * so legacy amounts pass through unchanged.
     */
    public function isCrossCurrency(?string $mainCurrency): bool
    {
        $main = CurrencyService::normalizeCode($mainCurrency);

        return $this->currency !== null && $main !== null && $this->currency !== $main;
    }

    /**
     * Whether the stored converted amount was entered against a different
     * main currency than the current one (e.g. the User changed their main
     * currency). Such amounts must be reviewed before aggregates use them,
     * never silently reinterpreted. A freshly entered, not-yet-stamped
     * amount is never stale: saving stamps it with the current main currency.
     */
    public function needsConvertedReview(?string $currentMainCurrency): bool
    {
        if (!$this->hasConvertedAmount() || $this->convertedCurrency === null) {
            return false;
        }

        return $this->convertedCurrency !== CurrencyService::normalizeCode($currentMainCurrency);
    }

    /**
     * Whether this Subscription must be reviewed before aggregates use it:
     * either its converted amount was entered against a previous main
     * currency, or it is cross-currency without any converted amount (e.g.
     * saved before the User confirmed a main currency).
     */
    public function isPendingReview(?string $currentMainCurrency): bool
    {
        $main = CurrencyService::normalizeCode($currentMainCurrency);

        if ($main === null || !$this->isCrossCurrency($main)) {
            return false;
        }

        return $this->needsConvertedReview($main) || !$this->hasConvertedAmount();
    }

    /**
     * Stamps a freshly entered converted amount with the main currency it
     * was entered against, and drops converted input when it is not needed
     * (same-currency Subscriptions must not carry duplicate input). A stale
     * stamp (entered against a previous main currency) is always preserved:
     * only the User re-entering the amount may move it to a new currency.
     */
    public function syncConvertedCurrency(?string $mainCurrency): static
    {
        $main = CurrencyService::normalizeCode($mainCurrency);

        if (!$this->isCrossCurrency($main) || !$this->hasConvertedAmount()) {
            $this->convertedAmount = null;
            $this->convertedCurrency = null;

            return $this;
        }

        if ($this->convertedCurrency === null) {
            $this->convertedCurrency = $main;
        }

        return $this;
    }

    /**
     * Reconciles converted input after an edit, given the pre-edit currency
     * and converted amount. Re-entering the converted amount reviews it: the
     * current main currency is stamped. Changing the currency while keeping
     * the old figure clears it instead: a figure computed for another
     * currency must be re-entered, never carried over. Untouched stale
     * amounts keep their old stamp and stay excluded from aggregates until
     * reviewed.
     */
    public function reconcileConverted(?string $originalCurrency, ?float $originalConvertedAmount, ?string $mainCurrency): static
    {
        if ($this->getConvertedAmount() !== $originalConvertedAmount) {
            $this->setConvertedCurrency($mainCurrency);

            return $this;
        }

        if ($this->getCurrency() !== CurrencyService::normalizeCode($originalCurrency)
            && $this->isCrossCurrency($mainCurrency)
        ) {
            $this->convertedAmount = null;
            $this->convertedCurrency = null;
        }

        return $this;
    }

    /**
     * @return list<string> Violation messages; empty when valid.
     */
    public function validateConverted(?string $mainCurrency, bool $forSave = false): array
    {
        $violations = [];
        $main = CurrencyService::normalizeCode($mainCurrency);

        if ($this->currency !== null && !CurrencyService::isValidCode($this->currency)) {
            $violations[] = sprintf('Currency "%s" is not a valid ISO 4217 code.', $this->currency);
        }

        if ($this->convertedCurrency !== null && !CurrencyService::isValidCode($this->convertedCurrency)) {
            $violations[] = sprintf('Converted currency "%s" is not a valid ISO 4217 code.', $this->convertedCurrency);
        }

        if ($this->getConvertedAmount() !== null && $this->getConvertedAmount() <= 0) {
            $violations[] = 'Converted amount must be positive.';
        }

        // Legacy data (unknown Subscription currency with an unconfirmed
        // main currency) is reported unchanged: no converted amount required.
        if ($this->currency === null) {
            if ($main !== null) {
                $violations[] = 'Please select a currency for this Subscription.';
            }

            return $violations;
        }

        if ($main === null) {
            return $violations;
        }

        if ($this->currency === $main) {
            if ($this->hasConvertedAmount()) {
                $violations[] = 'A Subscription in your main currency must not carry a converted amount.';
            }

            return $violations;
        }

        if ($this->getConvertedAmount() === null || $this->getConvertedAmount() <= 0) {
            $violations[] = sprintf(
                'A Subscription in %s requires a positive converted amount in %s.',
                $this->currency,
                $main
            );
        } elseif ($this->needsConvertedReview($main) && !$forSave) {
            // A stale amount stays saveable so unrelated edits are not
            // blocked; aggregates exclude it until the User reviews it.
            $violations[] = sprintf(
                'The converted amount was entered in %s; please review it for %s.',
                $this->convertedCurrency ?? 'an unknown currency',
                $main
            );
        }

        return $violations;
    }

    /**
     * Amount in the main currency used for reporting: the user-entered
     * converted amount for fresh cross-currency Subscriptions, otherwise the
     * stored amount. Stale cross-currency amounts are excluded from
     * aggregates (see SubscriptionService::getTotals()).
     */
    public function getReportingAmount(?string $mainCurrency): ?float
    {
        if ($this->isCrossCurrency($mainCurrency)
            && !$this->needsConvertedReview($mainCurrency)
            && $this->getConvertedAmount() !== null
        ) {
            return $this->getConvertedAmount();
        }

        return $this->getAmount();
    }

    public function getReportingMonthlyCalculated(?string $mainCurrency): float
    {
        if (null === $this->billingCycle || null === $this->amount) {
            throw new \LogicException('Cannot calculate a monthly amount before billingCycle and amount are set.');
        }

        $amount = $this->getReportingAmount($mainCurrency);

        return round($this->isMonthly() ? $amount : $amount / 12, 2);
    }

    public function getReportingYearlyCalculated(?string $mainCurrency): float
    {
        if (null === $this->billingCycle || null === $this->amount) {
            throw new \LogicException('Cannot calculate a yearly amount before billingCycle and amount are set.');
        }

        $amount = $this->getReportingAmount($mainCurrency);

        return round($this->isYearly() ? $amount : $amount * 12, 2);
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
