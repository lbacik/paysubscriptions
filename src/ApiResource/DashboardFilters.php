<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The selectors the dashboard snapshot report was computed with, echoed so a
 * client can label a cached report without re-deriving defaults.
 */
final class DashboardFilters
{
    public function __construct(
        public readonly string $chartMode,
        public readonly string $basis,
        public readonly ?string $category,
        public readonly ?int $month,
    ) {
    }
}
