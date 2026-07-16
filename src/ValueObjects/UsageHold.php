<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A granted claim on a metered allowance: the units are already held against the owner's ceiling, so no
 * other request can spend them. The caller does its work and then SETTLES the hold — turning it into
 * recorded usage, or handing it back.
 *
 * The token is the hold's identity, and settling is keyed on it: a hold settled twice (a retried job, a
 * duplicated call) settles once. A hold nobody settles expires, and the allowance comes back on its own —
 * a crashed worker must not cost a paying customer the rest of their month.
 */
final readonly class UsageHold
{
    public function __construct(
        public string $token,
        public string $meterKey,
        public string $period,
        public int $amount,
    ) {}
}
