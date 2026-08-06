<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\MerchantStatus;

/**
 * A merchant's account at the payment provider, as the package sees it: the provider key, the account
 * id, and the three capability flags that decide whether money may be destined to it.
 *
 * The flags are a SNAPSHOT, not a live read. A provider reports them asynchronously (an account that
 * finished onboarding is announced by a webhook), so the value a consumer holds can be older than the
 * account. That is why the accessor below is written as a conjunction of positive facts rather than
 * "not blocked": an unknown or stale account is one whose flags are false, and false must mean "do not
 * route", never "probably fine".
 */
final readonly class MerchantAccountReference
{
    public function __construct(
        public string $provider,
        public string $accountId,
        public bool $chargesEnabled = false,
        public bool $payoutsEnabled = false,
        public bool $detailsSubmitted = false,
        /**
         * When the merchant disconnected their account, if they did. A DIFFERENT axis from the three flags
         * above and appended last on purpose, so a positional construction written before it still compiles.
         * A withheld capability stops transfers; a deauthorization stops transfers AND reversals, which is
         * the state in which a clawback becomes impossible.
         */
        public ?CarbonInterface $deauthorizedAt = null,
        /**
         * The platform's own position, beside the provider's. Appended last, like the field before it, so
         * a positional construction written earlier still compiles. It defaults to Active because the
         * default of every other field here is "nothing is wrong yet".
         */
        public MerchantStatus $status = MerchantStatus::Active,
    ) {}

    /**
     * Whether the provider has confirmed all three capabilities. Money may be destined to the account
     * only when this is true — the receiving-side gate reads exactly this.
     */
    public function isReceivable(): bool
    {
        return $this->chargesEnabled
            && $this->payoutsEnabled
            && $this->detailsSubmitted
            // A deauthorized account may still carry three true flags — the provider reported them before
            // the merchant disconnected, and nothing lowers them afterwards. Routing to it would address
            // money to a relationship that no longer exists.
            && ! $this->deauthorizedAt instanceof CarbonInterface
            // And the platform's own decision has to hold even when the provider is perfectly happy: a
            // suspension made here is invisible in the flags.
            && $this->status->permitsRouting();
    }
}
