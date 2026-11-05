<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Connect;

/**
 * Where a connected Mollie account stands in Mollie's onboarding, as {@see MollieConnect::onboarding()} read it.
 *
 * Mollie lets a merchant take payments before the onboarding is complete and pays the money out only once it is, so
 * the two flags answer two questions: whether a shop may take orders, and whether the merchant is paid for them.
 */
final readonly class MollieOnboarding
{
    public function __construct(
        /**
         * Whether the access token may read the onboarding: false without the `onboarding.read` permission, and then
         * the rest says nothing.
         */
        public bool $permitted,
        /** `needs-data`, `in-review` or `completed`, as Mollie reports it, or null where Mollie said nothing. */
        public ?string $status = null,
        /** Whether Mollie lets the merchant take real payments. */
        public bool $canReceivePayments = false,
        /** Whether Mollie pays the merchant's money out. */
        public bool $canReceiveSettlements = false,
        /**
         * The page in Mollie's dashboard where the merchant gives what the onboarding still needs, or null where Mollie
         * names none.
         */
        public ?string $dashboard = null,
    ) {}
}
