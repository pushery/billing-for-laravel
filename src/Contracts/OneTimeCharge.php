<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\FanPriceTooLow;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\Money;

/**
 * A first-class, subscription-independent one-time purchase (an add-on / top-up). Returns a
 * driver-shaped payload the front-end completes; the credit effect is applied once per session by the
 * webhook backbone, and what the credit grants is project-defined.
 */
interface OneTimeCharge
{
    /**
     * @param  ?string  $declarationReference  the key the package minted for the buyer's pre-purchase
     *                                         declarations, to be carried to the provider as opaque
     *                                         metadata and handed back on the webhook. Null on every
     *                                         install with no consumer-rights profile, and a driver that
     *                                         gets null must send exactly the payload it sent before this
     *                                         parameter existed -- Mode S is a byte-identity promise, not
     *                                         a behavioral one.
     * @param  ?string  $buyerCountry  the buyer's ISO country where the caller already knows it; checked against
     *                                 `billing.tax_markets` before the provider is asked for anything, so a market
     *                                 that is not open throws MarketNotOpen. Null checks nothing here
     * @param  ?bool  $collectTaxId  whether THIS checkout asks the buyer for a tax ID while the provider computes tax;
     *                               null follows `billing.checkout.tax_id_collection`
     */
    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $callerReference = null): ClientIntent;

    /**
     * A hosted checkout for a tip — a buyer-chosen amount with no catalog entry behind it.
     *
     * ## Why it is a second method rather than a special add-on key
     *
     * `purchase()` takes a KEY and never an amount, and that is deliberate: the price comes from the
     * catalog so a caller cannot inject one. A tip has no catalog entry and cannot have one — the figure
     * is the buyer's, chosen at the moment of paying. The two rules are opposite, so they are two methods
     * rather than one with a flag, and the anti-injection rule stays absolute where it applies.
     *
     * What replaces the catalog as the guard is the SERVER: the amount is refused when tipping is off,
     * when it is not positive, when it is below the operator's floor, and when the installation has no
     * merchant for the sale to route to.
     *
     * The floor is `billing.marketplace.tips.minimum_minor`, inheriting the pay-what-you-want one where it
     * says nothing. It was absent while the sale floor beside it was enforced, and a reader of both was
     * entitled to assume otherwise — a tip is a buyer-chosen amount by the paragraph above, which is the
     * same argument the floor rests on.
     *
     * ## Why a tip has to say what it was paid ON
     *
     * A tip has no tax treatment of its own. It is placed by the supply it accompanies — a tip on
     * commissioned work and a tip on a file download are taxed in different countries — so
     * `$soldAlongside` is required rather than defaulted. There is no safe guess, and a default would make
     * the wrong one the quiet normal case.
     *
     * @param  Money  $chosen  the gross amount the buyer chose, tax included, as they will be charged it
     * @param  TaxArchetype  $soldAlongside  what the tip was paid on
     *
     * @throws MarketplaceUnsupported when the installation has no merchant to route to
     * @throws InvalidArgumentException when tipping is off or the amount is not positive
     * @throws FanPriceTooLow when the amount is below the configured floor
     */
    public function tip(Model $billable, Money $chosen, TaxArchetype $soldAlongside, ?string $declarationReference = null, ?string $buyerCountry = null): ClientIntent;
}
