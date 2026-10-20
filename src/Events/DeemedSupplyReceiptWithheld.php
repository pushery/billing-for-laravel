<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;

/**
 * A supply of goods the platform is treated as making itself was paid for on the provider's page, and the buyer's
 * document was not issued.
 *
 * The sale was classified before the session opened, as one to somebody who is not a business, for a destination
 * nobody knew yet. Three things only the payment reveals can contradict that: a tax ID the buyer entered on the page,
 * a destination the platform's supply cannot be taxed in, and a tax the provider computed at another rate than the
 * one the supply is taxed at. A document stating the supply as it was classified would then describe a sale that did
 * not happen, so none is issued, and this says why.
 *
 * The money has moved. A consumer listens to have somebody look at the sale: refund it, or document by hand what
 * really happened. Nothing in the package retries it.
 */
final readonly class DeemedSupplyReceiptWithheld implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        /** Who paid, as the application owns them. */
        public Model $buyer,
        public string $provider,
        public string $chargeReference,
        /** The paragraph the sale was classified under before the money moved. */
        public DeemedSupplyOfGoods $supply,
        /** The tax ID the buyer entered on the provider's page, where that is the reason; null for the other two. */
        public ?string $buyerTaxId,
        /** What contradicted the classification. Worth showing to an operator, not to the buyer. */
        public string $reason,
    ) {}
}
