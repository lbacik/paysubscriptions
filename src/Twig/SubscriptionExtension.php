<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Subscription;
use App\Service\RenewalCalculator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes RenewalCalculator to templates so the dashboard shows the
 * projected next renewal instead of the raw, possibly stale nextPayment
 * anchor - the only place that anchor is meant to be read directly is this
 * calculator.
 */
final class SubscriptionExtension extends AbstractExtension
{
    public function __construct(
        private readonly RenewalCalculator $renewalCalculator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('next_renewal', $this->nextRenewal(...)),
        ];
    }

    public function nextRenewal(Subscription $subscription): \DateTimeImmutable
    {
        $anchor = $subscription->getNextPayment();
        $cycle = $subscription->getBillingCycle();

        if (null === $anchor || null === $cycle) {
            throw new \LogicException('Cannot calculate a renewal date before nextPayment and billingCycle are set.');
        }

        return $this->renewalCalculator->nextRenewal($anchor, $cycle);
    }
}
