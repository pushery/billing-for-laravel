<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PlatformFeeResolver;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\VoucherInstrumentType;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\ReceiveEligibilityDenied;
use Pushery\Billing\Marketplace\ChargedBuyerFee;
use Pushery\Billing\Marketplace\CreditTopUpVolume;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\FeeLine;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * A first-class, subscription-independent one-time purchase for the Stripe driver. It opens a hosted
 * Checkout Session for the add-on and returns the driver-shaped payload the front-end redirects to.
 * The price is resolved from the add-on KEY through the catalog — the client never submits a price
 * (anti-price-injection) — and the add-on key is stamped on the session metadata, which the webhook
 * mapper reads on completion to credit the owner exactly once. This is the front half of the add-on
 * money loop whose back half (credit) already ships.
 */
final readonly class StripeOneTimeCharge implements OneTimeCharge
{
    public function __construct(
        private StripeClient $stripe,
        private AddonCatalog $addons,
        private Repository $config,
        private CanTransactMoney $eligibility,
        private StripeCustomerRegistry $customers,
        private MerchantAccountDirectory $accounts,
        private PlatformFeeResolver $fees,
        private CanReceiveMoney $receiving,
        private MarketplaceSaleContext $context,
        /**
         * Where a routed sale is written down.
         *
         * The synchronous lane records its charge in the same call that takes the money. This one cannot —
         * the payment happens on Stripe's page, minutes later — so it records the sale as PENDING when the
         * session opens, and the confirmation webhook settles that row. Without it the confirmation has
         * nothing to find: `SettleRoutedChargeOnConfirmation` looks the reference up and returns when there
         * is no row, so every hosted routed sale settled into silence.
         */
        private RoutedChargeLedger $ledger,
        /**
         * Whether THIS sale carries a buyer fee — the developer's switch and the sale's regime, together.
         *
         * NULLABLE AND RESOLVED FROM THE CONTAINER, never default-constructed here. A hand-built one would
         * need a configuration repository, and an empty one reads every setting as absent — so the switch
         * would report itself permanently off and the failure would look exactly like an installation that
         * chose not to charge fees. Nullable keeps the class constructible by a consumer without making a
         * silent wrong answer the price of that.
         */
        private ?ChargedBuyerFee $buyerFees = null,
    ) {}

    /** The buyer-fee seam, resolved once from the container when the caller supplied none. */
    private function buyerFee(): ChargedBuyerFee
    {
        return $this->buyerFees ?? Container::getInstance()->make(ChargedBuyerFee::class);
    }

    /**
     * The line the buyer fee rides on, priced INCLUSIVE of tax.
     *
     * Inclusive is not a preference, it follows from what the number means: a buyer fee is quoted GROSS —
     * the buyer is told 5.00 and pays 5.00, with the net and the tax read back out of it. Sent exclusive
     * under a provider tax mode, the provider would add tax ON TOP and the buyer would be charged more than
     * they were quoted, while the figures this package recorded described the smaller sale.
     *
     * @return array{price_data: array{currency: string, unit_amount: int, tax_behavior: string, product_data: array{name: string}}, quantity: int}
     */
    private function buyerFeeLine(FeeLine $fee): array
    {
        return [
            'price_data' => [
                'currency' => strtolower($fee->gross->currency),
                'unit_amount' => $fee->gross->minorUnits,
                'tax_behavior' => 'inclusive',
                // Named from the package's own translations so a buyer sees their language rather than an
                // internal key, and so an operator can publish a wording of their own.
                'product_data' => ['name' => (string) Lang::get('billing::checkout.buyer_fee')],
            ],
            'quantity' => 1,
        ];
    }

    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null): ClientIntent
    {
        // Defense in depth: refuse to open a paid checkout for an ineligible owner even if a caller
        // bypassed the UI eligibility guard.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        // The buyer's country, where the caller already knows it, is checked before the provider is asked for
        // anything. A hosted checkout cannot restrict the address the buyer enters afterwards, so what they enter
        // is checked again once the provider reports where it taxed the sale (ReverseSaleIntoClosedMarket).
        $this->context->assertMarketOpen($buyerCountry);

        $price = $this->addons->providerPriceFor($addonKey);

        if ($price === null) {
            throw new InvalidArgumentException("Add-on '{$addonKey}' is not purchasable (no provider price configured).");
        }

        $customerId = $this->customers->resolve($billable);

        $merchant = $this->context->routedMerchant();

        // Resolved ONCE, and that is what makes the ledger row and the payment describe the same sale. The
        // amounts below used to be computed, handed to Stripe and thrown away; recomputing them after the
        // session exists would mean a second provider call and a second chance for the two to disagree.
        $routed = $merchant instanceof Model ? $this->routing($merchant, $price) : null;

        // The buyer fee, if this installation charges one AND this sale's regime has one. Resolved from the
        // ITEM's price, never from a total: the fee is charged on top, so computing it from a figure that
        // already contains it would compound.
        //
        // Null on every installation that has not switched fees on, which is the default and nearly all of
        // them -- and then every line below is byte-for-byte what it always was.
        $buyerFee = $routed === null ? null : $this->buyerFee()->on(
            $routed['gross'],
            // The RATE IS NOT THIS LANE'S TO INVENT. Under a provider tax mode Stripe computes it from the
            // line's own tax_behavior, and the fee line below is sent INCLUSIVE precisely so the figure the
            // buyer was quoted is the figure they pay. Zero here means "this lane states no rate", which is
            // what makes the recorded net equal the gross rather than a number nobody computed.
            0,
        );

        // Built before the payload so the rise is one statement rather than a condition inside an array
        // literal — and so a reader can see it only happens where a routing exists at all.
        //
        // NESTED, NOT `?? null` PLUS A SEPARATE CONDITION, AND THAT IS A CORRECTION. `$buyerFee` is
        // non-null only where `$routed` is, so the two conditions were the same condition — but stated
        // apart, whether the analyzer can join them is a property of the ANALYZER. An older phpstan than
        // the one this package pins did not join them and reported the offset as possibly missing, so the
        // check the gate ran and the check a developer ran disagreed about shipped code. The pinned one
        // joins them — the shape stays anyway, because a relationship the code relies on is cheaper said
        // than inferred, and this one cost nothing to say.
        $intent = null;

        if ($routed !== null) {
            $intent = $routed['intent'];

            // On a separate transfer there is no application fee to raise: the platform keeps the whole payment,
            // the buyer fee with it, and moves only the merchant's net share of the ITEM afterwards.
            if ($buyerFee instanceof FeeLine && isset($intent['application_fee_amount'])) {
                // THE WHOLE CORRECTNESS OF THIS LANE. The buyer now pays item + fee, and the provider moves
                // everything that is not the application fee to the merchant — so leaving it alone would hand
                // the platform's own intermediation revenue to the seller, on every sale, silently. What must
                // not move is the merchant's share of the ITEM.
                $intent['application_fee_amount'] += $buyerFee->gross->minorUnits;
            }
        }

        $payload = array_filter([
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => $buyerFee instanceof FeeLine
                ? [['price' => $price, 'quantity' => 1], $this->buyerFeeLine($buyerFee)]
                : [['price' => $price, 'quantity' => 1]],
            // The webhook mapper reads this on checkout.session.completed to credit the owner -- and, when
            // the buyer made pre-purchase declarations, to find them again. The declaration key is appended
            // only when there is one, so a session opened without a consumer-rights profile carries the same
            // single-entry bag it always did.
            'metadata' => $declarationReference === null
                ? ['addon_key' => $addonKey]
                : ['addon_key' => $addonKey, 'withdrawal_declaration' => $declarationReference],
            'success_url' => $this->returnUrl('success_url'),
            'cancel_url' => $this->returnUrl('cancel_url'),
            // Absent for a single-seller install, so the session it opens is byte-identical to before.
            //
            // WITH A BUYER FEE THE APPLICATION FEE RISES BY IT, and that is the whole correctness of this
            // lane. The buyer now pays item + fee, and the provider moves everything that is not the
            // application fee to the merchant -- so leaving the application fee alone would hand the
            // platform's own intermediation revenue to the seller, on every sale, silently. The merchant's
            // share of the ITEM is what must not move.
            'payment_intent_data' => $intent,
        ]);

        // The same question the subscription lane answers, answered the same way — because it is ONE
        // installation-wide setting and must not give two opposite answers. Under a provider mode the
        // package deliberately computes nothing (the local calculator returns zero, correctly), so if the
        // provider is also never asked, the tax simply does not exist. That is not hypothetical: it is the
        // exact failure StripeCheckout::providerTax() documents having already shipped once, where the
        // 'stripe' alias missed the comparison and "every invoice went out untaxed, and nothing raised
        // anything". This lane had the same hole for every one-time add-on.
        //
        // Nothing is red when it happens. Stripe opens a valid session, the money moves, the webhook grants
        // the add-on. The absence surfaces at a VAT return, or never.
        // A money-credit add-on is a voucher, and a voucher's tax falls where its instrument type says. An
        // add-on that grants no units credits the owner's balance at face value: money against a promise,
        // with neither the place nor the rate of the eventual supply decided. Taxing it here taxes a supply
        // nobody has made yet — and the same money is taxed again when the balance pays an invoice, which is
        // the error a credit cannot be corrected out of afterwards.
        //
        // Where an installation sells into exactly one country at one rate, the supply IS determined at
        // issue; `billing.marketplace.vouchers.instrument_type` says so, and then the tax falls here as it
        // always did. That knob is the voucher feature's own, deliberately — a second one beside it would let
        // one installation configure the same instrument two ways and never notice.
        $taxedHere = ! CreditTopUpVolume::isMoneyCredit($this->addons, $addonKey)
            || VoucherInstrumentType::fromConfigured($this->config->get(VoucherInstrumentType::CONFIG_KEY))->taxedAtIssue();

        if ($this->context->providerTax() && $taxedHere) {
            $payload['automatic_tax'] = ['enabled' => true];

            // The same switch as the subscription lane, read the same way, and overridable for this checkout the same
            // way. See StripeCheckout for why a platform selling to consumers turns it off.
            if ($collectTaxId ?? $this->config->get('billing.checkout.tax_id_collection', true) !== false) {
                $payload['tax_id_collection'] = ['enabled' => true];
            }

            // Stripe rejects automatic_tax against an existing customer without permission to save the
            // address it collects — the same caveat, and the same fix, as the subscription lane.
            $payload['customer_update'] = ['address' => 'auto'];
        }

        $session = $this->stripe->checkout->sessions->create($payload);

        if ($routed !== null) {
            // `?? null` rather than a plain read, and the operator is doing real work here. A Stripe object
            // answers an UNDEFINED property by emitting a notice and returning null — so reading it to find
            // out whether it is there writes to the output of whatever is running, and the refusal path
            // below (the one case where it is legitimately absent) printed a warning every time it did its
            // job. The null-coalescing operator asks `__isset` first, which looks in the same value bag
            // without complaining.
            $this->recordPendingSale($routed, $session->payment_intent ?? null, $buyerFee);
        }

        $url = $session->url ?? null;

        return new ClientIntent(
            driver: 'stripe',
            payload: ['checkout_url' => is_string($url) ? $url : '', 'session_id' => $session->id],
            offSessionCapable: false,
        );
    }

    public function tip(Model $billable, Money $chosen, TaxArchetype $soldAlongside, ?string $declarationReference = null, ?string $buyerCountry = null): ClientIntent
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $this->context->assertMarketOpen($buyerCountry);

        // The switch belongs to the pricing class and is asked of it. An installation with tipping off
        // that still opened a session would take money it has said it does not take.
        if (! $this->context->tipsEnabled()) {
            throw new InvalidArgumentException(
                'Tipping is switched off for this installation, so no tip checkout can be opened. '
                .'Turn it on under `billing.marketplace.fan_pricing` before offering one.'
            );
        }

        // THE GUARD THE CATALOG NORMALLY IS. `purchase()` cannot be handed an amount at all; this method
        // must be, so the refusal that a key gives for free is written out here. A zero is the ordinary way
        // to get here — a buyer who leaves the tip box empty — and what it would otherwise buy is a
        // provider call, a charge row, an earnings figure and a line in a tax return, all describing a sale
        // nobody made. Negative is the same claim and is refused by the same check.
        if (! $chosen->isPositive()) {
            throw new InvalidArgumentException(
                "A tip needs a positive amount; got {$chosen->minorUnits} {$chosen->currency}."
            );
        }

        $merchant = $this->context->routedMerchant();

        // A tip TO THE PLATFORM is not what this seam is for, and silently opening one would be the worst
        // reading of a null: the buyer would pay, the platform would keep all of it, and the person they
        // meant to tip would never hear. A single-seller installation has no merchant to route to, and
        // that is a configuration answer rather than a runtime one.
        if (! $merchant instanceof Model) {
            throw MarketplaceUnsupported::noMerchantToRouteTo();
        }

        $customerId = $this->customers->resolve($billable);
        $merchantKey = $merchant->getKey();
        $routed = $this->tipRouting($merchant, $chosen);

        $payload = array_filter([
            'mode' => 'payment',
            'customer' => $customerId,
            // `price_data` rather than a price id, and this is the one place in the package that builds a
            // line from an amount instead of resolving one. It is what a buyer-chosen figure means: there
            // is no catalog entry to point at, and there cannot be. The server-side refusals above are what
            // stands in for the catalog's protection.
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($chosen->currency),
                    'unit_amount' => $chosen->minorUnits,
                    'product_data' => ['name' => $this->tipLineName()],
                ],
            ]],
            // What the confirmation needs and cannot re-derive. The archetype the tip was paid ON is known
            // here and nowhere later; the merchant is written down so a reconciler reading the session sees
            // who the money was destined for without joining anything. The declaration key rides the same
            // round trip it rides on a purchase, so a session that lost one lost both.
            'metadata' => array_filter([
                'tip_sold_alongside' => $soldAlongside->value,
                // THE MAPPER HAS READ THIS KEY SINCE THE LANE WAS BUILT AND NOTHING EVER WROTE IT. Every
                // real tip therefore arrived with the flag off, under a comment calling that the
                // conservative direction — a decision about an absent value, describing one that could not
                // be present. It is written here now, from the two stated countries and from nothing else:
                // the buyer's as the caller gave it, the seller's from configuration. Where the caller named
                // no country the key stays out, and the reader answers `unknown` rather than `not domestic`.
                'tip_buyer_domestic' => match ($this->buyerIsDomestic($buyerCountry)) {
                    true => '1',
                    false => '0',
                    null => null,
                },
                // Narrowed the way every other Stripe class in this package narrows a model key: a key is
                // typed `mixed` and a custom one need not be scalar, and metadata is a string map.
                'tip_merchant' => is_scalar($merchantKey) ? (string) $merchantKey : '',
                'withdrawal_declaration' => $declarationReference,
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
            'success_url' => $this->returnUrl('success_url'),
            'cancel_url' => $this->returnUrl('cancel_url'),
            'payment_intent_data' => $routed['intent'],
        ]);

        // NO BUYER FEE ON A TIP, and the omission is a decision. A buyer fee is charged ON TOP of a price
        // the buyer was quoted; a tip has no quoted price — the total IS what they chose. Adding a fee to
        // it would charge them more than the number they typed, which is the one thing a voluntary payment
        // must never do.
        //
        // No `automatic_tax` either, for the reason the routing below states: a tip's rate is decided by
        // what it was paid alongside, and that decision belongs to the supply rather than to the buyer's
        // address. The lane records no rate rather than asserting the provider's.
        $session = $this->stripe->checkout->sessions->create($payload);

        $this->recordPendingSale($routed, $session->payment_intent ?? null, null);

        $url = $session->url ?? null;

        return new ClientIntent(
            driver: 'stripe',
            payload: ['checkout_url' => is_string($url) ? $url : '', 'session_id' => $session->id],
            offSessionCapable: false,
        );
    }

    /**
     * What a tip line is called on the buyer's checkout page.
     *
     * Configurable because it is the only string in this lane a buyer reads, and a package that hardcoded
     * it in English would put a foreign word on a German checkout. The default is deliberately plain: it
     * names the act rather than the merchant, because the merchant's name is the consumer's to place and
     * a provider page is not where this package starts composing sentences about other people.
     */
    private function tipLineName(): string
    {
        $configured = $this->config->get('billing.marketplace.tips.line_name');

        return is_string($configured) && $configured !== '' ? $configured : 'Tip';
    }

    /**
     * The routing a TIP carries — the same shape a purchase gets, from an amount instead of a price.
     *
     * ## Why it is not `routing()` with a different argument
     *
     * `routing()` reads the unit amount off the provider's price, and its whole justification is that the
     * commission and the payment must come from the same number. For a tip that number is the argument:
     * the buyer chose it, it is what the line will charge, and there is no price to retrieve. So the
     * provider call that method makes is not merely unnecessary here — there is nothing for it to ask
     * about.
     *
     * ## And the commission is the TIP's, not the platform's ordinary one
     *
     * `feeForTip()` is asked, which is the whole reason `FanChosenPricing` is a dependency of this class.
     * An installation that charges less on voluntary payments — the common case, and why the setting
     * exists — would otherwise have its ordinary rate applied to every hosted tip while the token lane
     * honored the tip rate. Two lanes, two answers, and the one nobody watches is the one that ships.
     *
     * ## What it records as the tax basis, and why zero is honest here
     *
     * Zero, exactly as `routing()` records it and for the same reason: this lane holds no rate. A tip's
     * rate is decided by what it was paid alongside, and that decision needs the buyer's evidenced place
     * of supply — which does not exist when the session opens. Zero is the honest record of what was
     * computed here; it is not a claim that the basis was the net.
     *
     * @return array{
     *     merchant: Model,
     *     chargeType: ChargeType,
     *     intent: array{application_fee_amount?: int, transfer_data?: array{destination: string}},
     *     gross: Money,
     *     platformFee: Money,
     *     policy: PlatformFee,
     * }
     */
    private function tipRouting(Model $merchant, Money $chosen): array
    {
        $chargeType = $this->context->chargeType();

        $this->context->assertRoutingCompatible($chargeType);

        if (! $this->receiving->check($merchant)) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        $account = $this->accounts->accountFor($merchant);

        if (! $account instanceof MerchantAccountReference) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        $policy = $this->context->tipFee($this->fees->feeFor($merchant));

        [$platformFee] = $policy->splitOf($chosen);

        return [
            'merchant' => $merchant,
            'chargeType' => $chargeType,
            'intent' => $this->intentFor($chargeType, $platformFee, $account),
            'gross' => $chosen,
            'platformFee' => $platformFee,
            'policy' => $policy,
        ];
    }

    /**
     * The routing a one-off purchase carries, so a merchant's share actually reaches them.
     *
     * ## Why this had to exist
     *
     * A subscription checkout has routed since it was built; a one-off purchase did not, and nothing said
     * so. A routed add-on — a work, a ticket, a tip — opened a session with no destination, the platform
     * took the whole payment, and the merchant was never paid. The same shape of silence as the
     * separate-transfer lane: a successful session, no error, and money in the wrong account.
     *
     * ## Why the amount comes from the provider and not from the local catalog
     *
     * A payment-mode session takes `application_fee_amount` — an absolute figure — where a subscription
     * takes a percentage. A percentage needs no amount; an absolute one does. The catalog's
     * `price_display.amount` is a DISPLAY value and is allowed to be absent or stale, and computing a
     * commission from a number that is allowed to be wrong is how a platform quietly under- or overcharges
     * every routed sale. So the amount is read from the price the buyer will actually be charged against.
     *
     * That is one extra provider call on a path that already opens a hosted session, and it is not a hot
     * path. It buys the guarantee that the fee and the payment are computed from the same number.
     *
     * ## A flat component works here, unlike the subscription lane
     *
     * `application_fee_percent` cannot express a flat part, which is why the subscription lane refuses one.
     * An absolute amount can, so the full fee — rate plus flat — is honored, computed by the same splitter
     * the ledger uses.
     *
     * ## But the basis is the price the buyer pays, and the configured rate is documented as a net rate
     *
     * Stated here because it cannot be answered here. An absolute fee has to be final when the session opens
     * — before the buyer has paid, so before anything about them is known, so before their place of supply
     * is evidenced and their rate exists. The rate is not merely un-plumbed at that moment: it is not yet a
     * fact.
     *
     * This paragraph used to end "and this lane sets no `automatic_tax`, so the price IS the gross and the
     * commission runs on it". That premise is gone: the lane now sets `automatic_tax` under a provider mode,
     * because leaving it unset made the SAME installation-wide setting tax subscriptions and not add-ons.
     *
     * What the basis is now depends on the Stripe price's own `tax_behavior`, which this package does not
     * own. On an EXCLUSIVE price the buyer pays the unit amount plus tax, so the unit amount this fee is
     * computed from is the NET — which is the rate as documented, and the same answer the routed money path
     * gives. On an INCLUSIVE price the unit amount is still the gross and the old divergence stands.
     *
     * So the routed money path's 10.00 on a 119.00 sale at 19% and this lane's 11.90 now agree or differ
     * according to a setting made in the Stripe dashboard. That is better than diverging unconditionally and
     * still not a decision this method can make — it remains open, and it is now a question about price
     * configuration rather than about a missing flag.
     *
     * ## What it returns, and why it is not just the payload
     *
     * The gross and the fee are computed here and were, until now, handed to Stripe and forgotten. The
     * ledger row needs exactly those two numbers, and a second computation after the session exists would
     * mean a second `prices->retrieve` and a second chance for the payment and the record to disagree about
     * what was sold. So the facts come back beside the payload fragment and the caller writes both from one
     * answer.
     *
     * @return array{
     *     merchant: Model,
     *     chargeType: ChargeType,
     *     intent: array{application_fee_amount?: int, transfer_data?: array{destination: string}},
     *     gross: Money,
     *     platformFee: Money,
     *     policy: PlatformFee,
     * }
     */
    private function routing(Model $merchant, string $priceId): array
    {
        $chargeType = $this->context->chargeType();

        // The charge type and the seller-of-record posture are independent axes that have to agree, and the
        // check happens BEFORE anything is assembled — the only point at which refusing is still free.
        $this->context->assertRoutingCompatible($chargeType);

        if (! $this->receiving->check($merchant)) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        $account = $this->accounts->accountFor($merchant);

        if (! $account instanceof MerchantAccountReference) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        $price = $this->stripe->prices->retrieve($priceId);
        $unitAmount = $price->unit_amount;

        // A price with no fixed unit amount — metered, or tiered — cannot fund an absolute commission, and
        // guessing one would be inventing a number the buyer never agreed to.
        if (! is_int($unitAmount)) {
            throw new InvalidArgumentException(
                "The price '{$priceId}' has no fixed unit amount, so a routed one-off purchase cannot compute ".
                'its commission. Use a fixed price, or route the sale through a subscription.'
            );
        }

        $gross = new Money($unitAmount, strtoupper((string) $price->currency));
        $policy = $this->fees->feeFor($merchant);

        [$platformFee] = $policy->splitOf($gross);

        return [
            // Carried rather than re-derived by the caller: it is the merchant this sale was priced FOR, and
            // asking the resolver a second time could answer differently within one request.
            'merchant' => $merchant,
            'chargeType' => $chargeType,
            'intent' => $this->intentFor($chargeType, $platformFee, $account),
            'gross' => $gross,
            'platformFee' => $platformFee,
            'policy' => $policy,
        ];
    }

    /**
     * What the PaymentIntent carries for the lane this sale takes.
     *
     * A destination charge names the merchant's account and the platform's fee, and the provider moves the rest
     * as the payment settles. A separate transfer carries neither: the platform takes the whole payment, the
     * pending row written beside the session names the merchant and the net, and the share moves in a second
     * call once `payment_intent.succeeded` confirms the payment, the same call a sale through `RoutedPayment`
     * makes.
     *
     * The hosted lanes used to refuse a separate transfer outright, because nothing could make that second call
     * a webhook away. The confirmation now makes it, so a platform whose seller-of-record posture allows only this
     * lane can sell through a hosted checkout at all.
     *
     * @return array{application_fee_amount?: int, transfer_data?: array{destination: string}}
     */
    private function intentFor(ChargeType $chargeType, Money $platformFee, MerchantAccountReference $account): array
    {
        if ($chargeType === ChargeType::SeparateTransfer) {
            return [];
        }

        return [
            'application_fee_amount' => $platformFee->minorUnits,
            'transfer_data' => ['destination' => $account->accountId],
        ];
    }

    /**
     * Whether the seller's own small-value rules apply to this buyer, or null where nobody could say.
     *
     * Two STATED countries and nothing else: the buyer's as the caller gave it, the seller's from
     * configuration. Both halves have to be there — a buyer country with no configured home answers nothing,
     * because "is this domestic" has no meaning without a home, and defaulting to false would state a
     * cross-border supply on an installation that never said where it sells from.
     *
     * Upper-cased on both sides and nowhere else: a configuration written `de` and a caller passing `DE`
     * describe one country, and reading them as two would make a domestic sale cross-border on a keystroke.
     */
    private function buyerIsDomestic(?string $buyerCountry): ?bool
    {
        $seller = $this->config->get('billing.company.country');

        $buyer = $buyerCountry === null || $buyerCountry === '' ? null : mb_strtoupper($buyerCountry);
        $home = is_string($seller) && $seller !== '' ? mb_strtoupper($seller) : null;

        return $buyer !== null && $home !== null ? $buyer === $home : null;
    }

    /**
     * Write the routed sale down as PENDING, before the buyer has paid.
     *
     * ## Why here and not from the webhook
     *
     * `payment_intent.succeeded` is already mapped to a `RoutedChargeConfirmed`, and that event CONFIRMS a
     * row — `SettleRoutedChargeOnConfirmation` looks the reference up and returns when there is none. So a
     * lane that writes nothing here settles into silence: the money moves, the merchant is paid, and the
     * table every reversal cap, earnings count and small-business verdict is computed from says the sale
     * never happened.
     *
     * Writing it from `checkout.session.completed` instead does not work, and the reason is worth keeping:
     * that payload carries `amount_total` but NOT `transfer_data` — the routing lives on the PaymentIntent,
     * and the session names it only as an id. A webhook payload cannot be expanded.
     *
     * ## Why it is keyed on the PaymentIntent and not the session
     *
     * Because the confirmation arrives under the PaymentIntent's id, and Stripe guarantees no ordering
     * between `checkout.session.completed` and `payment_intent.succeeded`. A row keyed on the session and
     * re-keyed later would be missed by every confirmation that overtook the re-keying. Keyed this way the
     * row exists before any webhook can fire, so the two halves cannot race.
     *
     * ## Why a missing id refuses instead of carrying on
     *
     * A payment-mode session names its PaymentIntent the moment it is created. If that is ever absent, the
     * sale cannot be recorded — and handing back a checkout URL for a routed sale nothing can track is the
     * exact defect this method exists to end. The session goes unused and expires.
     *
     * @param  array{
     *     merchant: Model,
     *     chargeType: ChargeType,
     *     intent: array{application_fee_amount?: int, transfer_data?: array{destination: string}},
     *     gross: Money,
     *     platformFee: Money,
     *     policy: PlatformFee,
     * }  $routed
     */
    private function recordPendingSale(array $routed, mixed $paymentIntent, ?FeeLine $buyerFee): void
    {
        // Stripe hands this back as an id, and as an expanded object when something asked it to. Both are
        // answered; anything else is the refusal below rather than a silent null.
        $reference = match (true) {
            is_string($paymentIntent) => $paymentIntent,
            $paymentIntent instanceof PaymentIntent => $paymentIntent->id,
            default => null,
        };

        if (! is_string($reference) || $reference === '') {
            throw new RuntimeException(
                'Stripe opened a routed one-off checkout session without naming its PaymentIntent, so the '.
                'sale cannot be recorded. Refusing rather than returning a checkout URL for a routed sale '.
                'that nothing would be able to reverse, count or attribute afterwards.'
            );
        }

        $this->ledger->record(
            $routed['merchant'],
            'stripe',
            $reference,
            $routed['gross'],
            $routed['platformFee'],
            // Derived here rather than taken from a third computation: net is what is left, by definition.
            $routed['gross']->minus($routed['platformFee']),
            $routed['policy'],
            // The lane this sale took, as the routing resolved it, so a refund reads what happened rather than
            // today's configuration. On a separate transfer it is also what the confirmation reads to know that
            // the share still has to move.
            $routed['chargeType'],
            // Zero, and stated rather than left null. The commission was taken on the price with no tax rate
            // separating a net from a gross, and null on this column means "written before this was
            // recorded" — a description of old rows, which this is not.
            //
            // What it does NOT claim: whether that price was tax-exclusive or tax-inclusive. That is the
            // Stripe price's own `tax_behavior`, this package does not own it, and the divergence it causes
            // is the open question `routing()` states above. Zero is the honest record of what was computed
            // here; it is not an assertion that the basis was the net.
            0,
            // The fee is frozen onto the sale AT THE MOMENT IT IS CHARGED, which is the only moment the
            // figure is a fact. Without it a withdrawal would have to recompute the fee from whatever the
            // configuration says on the day it happens — and the withdrawal is precisely the event where an
            // old sale meets a changed setting.
            //
            // Its net and tax carry the same caveat as `commission_tax_bps` two arguments above: this lane
            // hands tax to the provider and holds no rate, so the line records a gross with no split rather
            // than asserting one. The gross and the place are exact, and those are what a return needs.
            $buyerFee,
            // Who sold, from the same context that checked the charge type against it above, so the small-business
            // turnover counts this sale on the basis its seller actually had.
            sellerPosture: $this->context->posture(),
        );
    }

    /** A configured hosted-checkout return URL, or a loud error — Stripe cannot open checkout without it. */
    private function returnUrl(string $key): string
    {
        $url = $this->config->get("billing.checkout.{$key}");

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("billing.checkout.{$key} must be configured to open a hosted checkout.");
        }

        return $url;
    }
}
