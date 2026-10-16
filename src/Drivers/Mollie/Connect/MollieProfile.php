<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Connect;

/**
 * One website profile of a connected Mollie account, the unit payments are created on.
 *
 * A merchant with an OAuth connection may own several; the application lets the merchant pick one and keeps its
 * id as the payment account's `profileId`.
 */
final readonly class MollieProfile
{
    public function __construct(
        public string $id,
        public string $name,
        /** `live` or `test`, as Mollie reports it. */
        public string $mode,
        /** `unverified`, `verified` or `blocked`, or null where Mollie names none. */
        public ?string $status,
        public ?string $website,
    ) {}
}
