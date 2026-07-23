<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The outcome of a PaymentRails charge (on- or off-session). Provider-neutral: every driver returns
 * this shape so the engine above never inspects a provider response object.
 *
 * A charge has more than two outcomes. Besides succeeded and declined, a European card payment can
 * come back needing the cardholder to authenticate (3-D Secure — `requiresAction`, with the
 * `clientSecret` the front end confirms against), and a bank-debit method (SEPA) is `pending` for days
 * before it settles. Collapsing those onto "failed" reports a good payment as a decline. `successful`
 * stays true only for a settled charge; `requiresAction` and `pending` are NOT failures — they are
 * "not yet", so `failed()` excludes them.
 */
final readonly class ChargeResult
{
    public function __construct(
        public bool $successful,
        public string $reference,
        public Money $amount,
        public ?string $failureReason = null,
        /** The charge needs cardholder authentication (3-D Secure) before it can settle. */
        public bool $requiresAction = false,
        /** The charge is in flight (a bank debit that settles asynchronously); not yet money, not a decline. */
        public bool $pending = false,
        /** The provider secret the front end confirms an action against (a Stripe PaymentIntent client secret). */
        public ?string $clientSecret = null,
    ) {}

    /** A genuine failure — a decline. An unsettled charge that still might succeed (action/pending) is NOT failed. */
    public function failed(): bool
    {
        return ! $this->successful && ! $this->requiresAction && ! $this->pending;
    }
}
