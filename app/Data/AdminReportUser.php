<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

/**
 * Charges of one user in the report range. `userId` is null for deleted users.
 */
final readonly class AdminReportUser
{
    public function __construct(
        public ?int $userId,
        public string $name,
        public ?string $email,
        public int $generations,
        public Money $charged,
        public Money $providerCost,
        public Money $profit,
    ) {}
}
