<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\Money;

/**
 * A fan's tip was paid on a hosted checkout page.
 *
 * ## Why a tip needs its own event rather than riding on the add-on one
 *
 * `AddonPurchased` names a catalog key, and a tip has none: the amount is the fan's, chosen at the
 * moment of paying, and there is no product behind it. Reading a tip as an add-on would send it to the
 * grant path, which would look up a key that does not exist and credit nothing — quietly, because a
 * missing grant is an ordinary state there.
 *
 * ## What travels, and why each piece cannot be re-derived later
 *
 * A tip has no treatment of its own: it is taxed by what it was paid ON, and the archetype that says so
 * is known when the session opens and nowhere afterwards. The same holds for whether the buyer is
 * domestic — a fact about the buyer that this package deliberately never invents — and for the merchant
 * the tip is destined to. All three are written into the session's metadata before the redirect and come
 * back here, which is the only reason the settled tip can be recorded with the treatment it was priced
 * under rather than one re-read from today's configuration.
 *
 * The `paymentReference` is the PaymentIntent, not the session, and the distinction is the same one
 * `AddonPurchased` documents: a later refund webhook carries the payment id. It is also the key the
 * routed ledger claims, so a redelivery finds the row that already exists instead of writing a second.
 */
final readonly class FanTipPaid implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public Money $amount,
        public string $reference,
        public ?string $paymentReference,
        /** What the tip was paid ON — a tip on commissioned work and a tip on a download are placed differently. */
        public TaxArchetype $soldAlongside,
        /** The merchant the tip is destined for, as the package recorded it when the session opened. */
        public string $merchantReference,
        /**
         * Whether the small-value rules of the seller's own country apply — or null where nobody could say.
         *
         * NULLABLE, AND THAT IS THE CORRECTION. It was a plain `bool`, and the session opener never wrote the
         * key it is read from: every real tip arrived `false`, under a reader comment calling that the
         * conservative direction. Conservative is the right instinct about an ABSENT value and the wrong
         * word for one nothing could produce — a listener reading `false` was told a fact about the buyer
         * that nobody had established.
         *
         * The opener states it now from the buyer's country as the caller gave it against the seller's own,
         * and states nothing where the caller named no country. Null therefore means "not established", which
         * a listener may treat as it likes; `false` now means somebody compared two countries and they
         * differed.
         */
        public ?bool $buyerIsDomestic,
        public ?string $declarationReference = null,
    ) {}
}
