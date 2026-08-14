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
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\ReceiveEligibilityDenied;
use Pushery\Billing\Marketplace\ChargedBuyerFee;
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

    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null): ClientIntent
    {
        // Defense in depth: refuse to open a paid checkout for an ineligible owner even if a caller
        // bypassed the UI eligibility guard.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

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
        // literal — and so PHPStan can see that it only happens where a routing exists at all.
        $intent = $routed['intent'] ?? null;

        if ($buyerFee instanceof FeeLine && $intent !== null) {
            // THE WHOLE CORRECTNESS OF THIS LANE. The buyer now pays item + fee, and the provider moves
            // everything that is not the application fee to the merchant — so leaving it alone would hand
            // the platform's own intermediation revenue to the seller, on every sale, silently. What must
            // not move is the merchant's share of the ITEM.
            $intent['application_fee_amount'] += $buyerFee->gross->minorUnits;
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
        if ($this->context->providerTax()) {
            $payload['automatic_tax'] = ['enabled' => true];
            $payload['tax_id_collection'] = ['enabled' => true];
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
     *     intent: array{application_fee_amount: int, transfer_data: array{destination: string}},
     *     gross: Money,
     *     platformFee: Money,
     *     policy: PlatformFee,
     * }
     */
    private function routing(Model $merchant, string $priceId): array
    {
        $chargeType = $this->context->chargeType();

        // A hosted session cannot serve a separate transfer, and refusing is the honest answer rather than a
        // gap. On that lane the platform takes the whole payment and the merchant's share moves in a SECOND
        // call — which can only be made once the payment has actually succeeded, and for a hosted session
        // that is a webhook away, long after this method has returned. Injecting a destination anyway would
        // silently turn it into a destination charge and move the merchant of record with it.
        if ($chargeType === ChargeType::SeparateTransfer) {
            throw MarketplaceUnsupported::separateTransferNeedsRoutedPayment();
        }

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
            'intent' => [
                'application_fee_amount' => $platformFee->minorUnits,
                'transfer_data' => ['destination' => $account->accountId],
            ],
            'gross' => $gross,
            'platformFee' => $platformFee,
            'policy' => $policy,
        ];
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
     *     intent: array{application_fee_amount: int, transfer_data: array{destination: string}},
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
            // A hosted session is unconditionally a destination charge — `routing()` refuses the other lane
            // outright — so the row states the lane it actually took rather than reading today's config back
            // when a refund needs to know.
            ChargeType::Destination,
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
