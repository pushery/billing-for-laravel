<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/**
 * A one-time add-on was bought and paid (the neutral form of a completed one-off checkout). The
 * reference is the checkout/session identifier — the natural dedup key, so the credit is applied
 * exactly once per purchase however many times the webhook is redelivered. The paymentReference is the
 * provider PAYMENT id (a PaymentIntent), a separate key: a later refund webhook carries the payment id,
 * not the session, so this is what a reversal is matched on.
 */
final readonly class AddonPurchased implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $addonKey,
        public Money $amount,
        public string $reference,
        public ?string $paymentReference = null,
        /**
         * The declaration reference the package minted BEFORE the redirect, echoed back by the provider.
         *
         * Null on every purchase that carried none -- which is every purchase on an install with no
         * consumer-rights profile, and every one made before the declarations were collected. Null is
         * therefore an ordinary state and not a failure; what it must never do is silently read as "the
         * buyer declared nothing was needed", which is why the grant path treats a missing consent as a
         * refusal rather than a pass.
         */
        public ?string $declarationReference = null,
        /**
         * Where the buyer said they were, as the provider recorded it on the session.
         *
         * The address entered in the hosted checkout, which is where a hosted sale is taxed — not a country
         * this package worked out. Null where the provider reported none, and null must never read as a
         * country: a receipt tier turns on whether the buyer is domestic, and a guessed one applies a relief
         * nobody claimed.
         */
        public ?string $buyerCountry = null,
        /**
         * What the provider charged in tax on this sale, in minor units, where it computed any.
         *
         * Null and zero are NOT the same answer, and the difference decides whether a document can be
         * issued at all. Zero says the provider computed tax and it was none — a sale with no separately
         * stated tax, where the price is the gross. Null says nothing was reported, which is the honest
         * reading of a payload that carried no total breakdown.
         *
         * A positive figure says the provider computed the tax from the buyer's address, and then this
         * package holds AMOUNTS and no rate: the rate lives on the session's line items, which a webhook
         * payload does not carry. What a document may state in that case is an open decision, not a
         * derivation — see the issuer.
         */
        public ?int $taxMinor = null,
        /**
         * Which provider's ids the two references above are, where the producer named it.
         *
         * A reader matching `paymentReference` against a stored charge needs it: the uniqueness that table
         * guarantees is on the PAIR, so a lookup without the provider is asking a question the index cannot
         * answer. Null on an event from a producer that predates this, and a reader that needs it treats
         * null as "cannot tell" rather than as a default provider.
         */
        public ?string $provider = null,
        /**
         * The caller's own correlation key, carried through the purchase and never interpreted.
         *
         * A consumer that writes its row BEFORE opening the checkout has to find that row again here. Until
         * this existed the only key that traveled was `declarationReference`, which a BUSINESS buyer never
         * has — a business has no right of withdrawal to declare — so exactly the purchases without a
         * declaration arrived with no key at all.
         *
         * IT IS NOT A SECOND DECLARATION REFERENCE, AND THE DIFFERENCE IS A LEGAL ONE. A correlation id
         * sent as `withdrawal_declaration` would come back meaning "this buyer declared", which is the one
         * statement a business checkout must not make. This package attaches no meaning to the value at all:
         * it goes out as its own provider key and comes back unchanged.
         *
         * Null on every purchase that named none, which is every existing one.
         */
        public ?string $callerReference = null,
    ) {}
}
