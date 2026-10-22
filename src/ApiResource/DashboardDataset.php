<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * One per-Subscription per-month charge series of the bar view.
 *
 * Values are decimal strings parallel to the twelve month labels of the
 * report chart; a month the Subscription is not charged in carries "0.00".
 */
final class DashboardDataset
{
    /**
     * @param list<string> $data decimal strings parallel to the month labels
     */
    public function __construct(
        public readonly string $label,
        public readonly array $data,
    ) {
    }
}
