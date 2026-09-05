<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a payment driver can do natively, so the package/UI can query capabilities and fill the gaps
 * with its own local engine. This is how a local-engine driver slots in without reducing Stripe: Stripe
 * reports rich native capabilities; the others report fewer, and the package supplies the rest.
 *
 * ## Every flag here describes REMOTE payment, and that boundary is stated rather than left to be found
 *
 * A buyer who is not present: a hosted checkout, a stored mandate, a webhook. Card-present payment is
 * outside it -- there is no flag that says a driver can take money at a counter, and there is deliberately
 * no flag that says it cannot either, because the question is not one this object can carry.
 *
 * In-person payment is a payment PATH, not a capability: terminal pairing, reader state, offline
 * behavior, a receipt, and a place of supply that is not the one distance selling uses -- which reaches
 * `src/Tax/`, where a wrong answer is a filed return rather than a missing feature. Hanging a tenth
 * boolean here would let a consumer ask the question and get an answer this package could not keep.
 *
 * The sentence exists because the absence reads exactly like an oversight. Measured 2026-09-04: both
 * providers' SDKs carry terminal APIs, and a case-insensitive search for "terminal" over this tree returns
 * 22 hits that look like support and are, every one of them, something else -- a state machine or the
 * shell. Somebody will make that measurement again; this paragraph is what stops it costing them the
 * afternoon it cost once.
 */
final readonly class DriverCapabilities
{
    /**
     * @param  list<string>  $availablePaymentMethods
     * @param  list<string>  $recurringCapableMethods
     */
    public function __construct(
        public bool $supportsHostedPortal = false,
        public bool $supportsProviderTax = false,
        public bool $supportsMeteredNative = false,
        public bool $supportsProviderProration = false,
        public bool $supportsProviderCredit = false,
        public array $availablePaymentMethods = [],
        public array $recurringCapableMethods = [],
        // Appended LAST, and it has to stay last. The value object is constructed positionally by
        // driver code outside this package, so inserting a parameter anywhere else silently shifts
        // every argument after it — a capability flag would start reading a payment-method list.
        public bool $supportsConnectDestinationCharges = false,
        // Appended LAST for the same reason as the line above, and it answers a question the package now
        // has to ask: does this provider tell the customer their trial is about to end?
        //
        // Stripe does — `customer.subscription.trial_will_end` reaches the mapper, which produces a
        // TrialEnding event, and the notice effect is registered on it. A local-engine driver announces
        // nothing, because nothing at the provider knows a trial is running: the package holds that date
        // itself and its own sweep collects the first charge.
        //
        // A capability is a promise the PACKAGE keeps, so this is true where a mapper genuinely produces
        // the event, never where the provider merely could.
        public bool $supportsProviderTrialNotice = false,
    ) {}

    public function offersMethod(string $method): bool
    {
        return in_array($method, $this->availablePaymentMethods, true);
    }

    public function canRecurWith(string $method): bool
    {
        return in_array($method, $this->recurringCapableMethods, true);
    }
}
