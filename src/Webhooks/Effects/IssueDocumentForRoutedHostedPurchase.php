<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\ReadsProviderComputedTax;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\DeemedSupplyReceiptWithheld;
use Pushery\Billing\Exceptions\DeemedSupplyOfGoodsRefused;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Exceptions\UnknownTaxCountry;
use Pushery\Billing\Marketplace\FanReceiptIssuer;
use Pushery\Billing\Marketplace\FanReceiptTierResolver;
use Pushery\Billing\Marketplace\ProductClassifier;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Tax\DeemedSupplyOfGoodsTaxDecision;
use Pushery\Billing\Tax\OneStopShopDeclaration;
use Pushery\Billing\ValueObjects\GoodsConsignment;
use Pushery\Billing\ValueObjects\ProviderComputedTax;
use Pushery\Billing\ValueObjects\SupplyTaxCharacteristics;
use Pushery\Billing\Webhooks\RepeatableEffect;

/**
 * The buyer's document for a routed sale that was paid on the provider's own checkout page.
 *
 * ## The gap this closes
 *
 * There are two ways a routed sale happens here, and only one issued anything. `RoutedPayment::charge()`
 * ends in `issueBuyerDocument()`, and its docblock says why in a sentence worth repeating: *the money moved
 * because a supply happened, and a supply that produces no document is the defect this method exists to
 * end.* The hosted lane took payment, wrote the ledger row correctly, and issued nothing. Nothing was red;
 * the absence surfaces at an audit or never.
 *
 * ## Why this event and not the confirmation
 *
 * `checkout.session.completed` carries everything a document needs and carries it better than the payment
 * confirmation does: the buyer through the customer reference, the archetype through the add-on key, the
 * place through `customer_details.address` — where the buyer said they were, rather than a country somebody
 * guessed before the redirect — and the money including the tax, for which a payment intent has no field.
 * The confirmation was tried first and reverted: it would have needed all of it shipped along as metadata,
 * which is machinery for facts the payload already holds.
 *
 * It is not "before the money arrived" either, which is the objection that sends a reader to the
 * confirmation. The mapper only emits this for a session whose `payment_status` says `paid`, so this runs
 * after a payment that happened — which is the property the synchronous lane waits for, arrived at the other
 * way round.
 *
 * ## Four refusals, and every one of them is a fact this cannot invent
 *
 * - **No routed row** — an ordinary single-seller purchase. Nothing about it is this lane's to document.
 * - **A posture other than the platform being the deemed supplier** — the same asymmetry the synchronous
 *   path keeps. Where the merchant is the seller of record the platform is not a party to the supply, and a
 *   document in its own name would name the wrong seller.
 * - **A tax the provider computed that it cannot state** — a positive figure means the rate came from the
 *   buyer's address, and the event carries amounts and never a rate. That is now asked of the provider
 *   rather than guessed at, through {@see ReadsProviderComputedTax}; what survives as a refusal is the case
 *   the provider cannot answer — several rates on one sale, a rate that is not a percentage, one finer than
 *   the document carries, or a read that failed. Zero is a different answer again: it says tax was computed
 *   and there was none, and the event settles it without asking anybody.
 * - **A buyer this application does not own** — the same silence `PersistInvoice` keeps.
 * - **An add-on nobody classified** — the classifier refuses one, and refusing is right: a treatment is
 *   not one unknown but five guesses. A document about a supply whose treatment nobody established would
 *   be the same defect as one stating a rate nobody supplied.
 *
 * ## A supply of goods the platform is treated as making itself
 *
 * Its row names the paragraph and keeps the consignment the sale was classified from, and its document is the one
 * `FanReceiptIssuer::issueDeemedSupplyOfGoods()` writes, with the tax decided now that the provider has reported
 * where the buyer is. Decided from the row rather than the catalog, because the classification was made before the
 * money moved and is never made again. The document is withheld, and {@see DeemedSupplyReceiptWithheld} says why,
 * where the payment contradicts that classification: a tax ID the buyer entered on the page, a destination the
 * supply cannot be taxed in, or a tax the provider computed at another rate.
 *
 * ## Once, however often the provider redelivers
 *
 * Through `issue()`, which goes to the repeat guard keyed on the charge reference. A second delivery finds
 * the document that exists instead of drawing a second number out of a gapless series — the one failure a
 * repeat cannot heal. For the same reason the replay may run it again over a delivery it already handled,
 * named with `--rerun`: a sale it could not document then, because the provider's tax was read without the
 * taxes of the line items, is documented now, and a sale that has its document keeps the one it has.
 */
final readonly class IssueDocumentForRoutedHostedPurchase implements RepeatableEffect
{
    public function __construct(
        private CustomerDirectory $directory,
        private AddonCatalog $addons,
        private ProductClassifier $classifier,
        private FanReceiptIssuer $receipts,
        private FanReceiptTierResolver $tiers,
        private Repository $config,
        /**
         * Where a provider-computed tax is read from.
         *
         * A seam rather than a client, for the reason the contract gives: nothing in this directory reaches
         * a provider, and an effect that did would be untestable without one. It is required rather than
         * optional because the branch that needs it is the branch this class exists for — an effect that
         * constructed without it would look like it works and issue nothing on every taxed sale.
         */
        private ReadsProviderComputedTax $taxes,
    ) {}

    public function __invoke(AddonPurchased $event): void
    {
        $charge = $this->routedCharge($event);

        if (! $charge instanceof MerchantCharge) {
            return;
        }

        // Read off the row, which froze it when the session opened — not from today's configuration, which
        // on a bank debit can be days younger than the sale.
        if ($charge->postureOr(SellerOfRecordPosture::PlatformDeemedSupplier) !== SellerOfRecordPosture::PlatformDeemedSupplier) {
            return;
        }

        if ($charge->deemed_supply instanceof DeemedSupplyOfGoods) {
            $this->documentDeemedSupply($event, $charge, $charge->deemed_supply);

            return;
        }

        // Zero is answered by the event itself: the provider computed the tax and there was none, so the
        // price is the gross with nothing separately stated. Anything else — a figure, or no breakdown at
        // all — is a question the event cannot answer, because it carries amounts and never a rate. That is
        // asked of the provider instead, through a seam, and a sale it cannot answer for gets no document.
        $providerTax = null;

        if ($event->taxMinor !== 0) {
            $providerTax = $this->taxes->forSale($event->reference);

            // The sum is the control, and it is not ceremony. A reading that took the wrong line, lost one,
            // or picked up a second currency still produces a plausible split; only holding it against what
            // the buyer actually paid catches that. A document whose parts do not add up to the payment is
            // the one defect a reader notices immediately.
            if (! $providerTax instanceof ProviderComputedTax || ! $providerTax->accountsFor($event->amount)) {
                return;
            }
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        $archetype = $this->archetypeOf($event->addonKey);

        // A fifth refusal, and it was very nearly a throw inside a queued job. The classifier does not take
        // null — it raises `ProductNotClassified`, and its message says why: "the alternative is not one
        // unknown but five guesses, each of which would be right often enough to look fine". So an
        // unclassified add-on gets no document rather than a document about a treatment nobody established,
        // and the sale stays exactly as visible as it was.
        if (! $archetype instanceof TaxArchetype) {
            return;
        }

        $classification = $this->classifier->classify($archetype);

        // Read once and reused, the same way the synchronous path does it: two calls to now() can land either
        // side of a second boundary, and the document would then state a sale date and a delivery date that
        // disagree about when the same instant was.
        $soldOn = CarbonImmutable::now();
        // Where the document places the sale and whether the cross-border consumer return declares it, as on the direct
        // lane: from the product's placement, the buyer's country, a tax id a register confirmed for the buyer and the
        // installation's distance-sale threshold, counted with this sale's net.
        [$place, $oneStopShop] = Container::getInstance()->make(OneStopShopDeclaration::class)->placed(
            $classification->placeOfSupply->fixedAnswerOf(PlaceOfSupplyRule::class),
            $event->buyerCountry, $owner, $providerTax->net ?? $event->amount, $soldOn,
        );

        $this->receipts->issue(
            buyerOwner: $owner,
            tier: $this->tiers->tierFor($event->amount, $this->buyerIsDomestic($event->buyerCountry), false),
            gross: $event->amount,
            // Stated, never derived — and now stated from one of two places. Zero where the provider
            // computed no tax, so the price is the gross with nothing separately stated; otherwise the
            // whole position the provider computed, which carries its own rate and its own amounts. Handing
            // over the position rather than the rate alone is what keeps the document's figures the
            // provider's: deriving them again from the rate can land a cent away.
            taxRateBps: $providerTax ?? 0,
            soldOn: $soldOn,
            // The anchor the settlement side already uses, so "the same sale" means one thing in both places
            // and a redelivery returns the document it already wrote.
            chargeReference: $event->paymentReference,
            characteristics: new SupplyTaxCharacteristics(
                archetype: $archetype,
                placeOfSupply: $place,
                rateCategory: $classification->rateCategory->fixedAnswerOf(TaxRateCategory::class),
                // BT-72, and absent where the treatment defers the supply: a multi-purpose voucher has been
                // paid for and nothing has been supplied, so dating a delivery would date something that has
                // not happened.
                deliveredOn: $classification->placeOfSupply->isDeferred() ? null : $soldOn,
                destinationCountry: $event->buyerCountry,
                oneStopShop: $oneStopShop,
            ),
            provider: $event->provider,
        );
    }

    /**
     * The buyer's document of a supply of goods the platform is treated as making itself, or the reason it has none.
     *
     * The tax is decided now, from the consignment the row kept and the destination the buyer entered, at the rate
     * band the classification settles for the archetype the row recorded. A tax the provider computed has to be at the
     * decided rate and add up to the payment, or the document would state figures the buyer was not charged.
     */
    private function documentDeemedSupply(AddonPurchased $event, MerchantCharge $charge, DeemedSupplyOfGoods $supply): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);
        $consignment = $charge->goodsConsignment();

        if (! $owner instanceof Model || ! $consignment instanceof GoodsConsignment) {
            return;
        }

        if ($event->buyerTaxId !== null) {
            $this->withhold($owner, $charge, $supply, $event->buyerTaxId, 'The buyer entered a tax ID on the provider\'s page, so the sale may have been to a business, which no deemed supply is. The sale was classified as one to somebody who is not a business before the page asked.');

            return;
        }

        $category = $this->classifier->classify($charge->tax_archetype ?? TaxArchetype::ConsumerGoods)
            ->rateCategory->fixedAnswerOf(TaxRateCategory::class) ?? TaxRateCategory::Standard;

        try {
            $tax = Container::getInstance()->make(DeemedSupplyOfGoodsTaxDecision::class)->decide(
                $supply, $consignment, $consignment->goodsLocatedIn, $event->buyerCountry, $event->amount, CarbonImmutable::now(), $category,
            );
        } catch (DeemedSupplyOfGoodsRefused|RegimeNotPermitted|UnknownTaxCountry $refused) {
            $this->withhold($owner, $charge, $supply, null, $refused->getMessage());

            return;
        }

        if (($event->taxMinor ?? 0) !== 0) {
            $providerTax = $this->taxes->forSale($event->reference);

            if (! $providerTax instanceof ProviderComputedTax || ! $providerTax->accountsFor($event->amount) || $providerTax->rateBps !== $tax->rateBps) {
                $this->withhold($owner, $charge, $supply, null, "The provider computed a tax this sale cannot state: the platform's supply is taxed in [{$tax->taxedIn}] at {$tax->rateBps} basis points.");

                return;
            }
        }

        $this->receipts->issueDeemedSupplyOfGoods(
            buyerOwner: $owner,
            tier: $this->tiers->tierFor($event->amount, ! $tax->distanceSale(), false),
            tax: $tax,
            // The anchor the settlement side already uses, as on every other routed sale's document.
            chargeReference: $event->paymentReference,
            provider: $event->provider,
            goods: $consignment->description,
        );
    }

    /** Say why a deemed supply paid on the provider's page has no document. */
    private function withhold(Model $owner, MerchantCharge $charge, DeemedSupplyOfGoods $supply, ?string $buyerTaxId, string $reason): void
    {
        Container::getInstance()->make(Dispatcher::class)->dispatch(
            new DeemedSupplyReceiptWithheld($owner, $charge->provider, $charge->charge_reference, $supply, $buyerTaxId, $reason),
        );
    }

    /**
     * What kind of thing this add-on is, where the catalog can say.
     *
     * A method rather than a ternary at the call site, and the reason is the coverage floor rather than
     * taste: pcov cannot mark both arms of a multi-line ternary, so the `null` arm of one reads as dead on
     * every run and no test can lift it. The branch itself is real — a consumer's own catalog implements
     * `AddonCatalog` and need not implement {@see SuppliesProductArchetypes}, and one that does not
     * classify answers null, which produces a document that states less rather than one that states a guess.
     */
    private function archetypeOf(string $addonKey): ?TaxArchetype
    {
        if (! $this->addons instanceof SuppliesProductArchetypes) {
            return null;
        }

        return $this->addons->archetypeFor($addonKey);
    }

    /** The routed sale this purchase is, or null where it is an ordinary single-seller one. */
    private function routedCharge(AddonPurchased $event): ?MerchantCharge
    {
        // Both halves of the key. The table's uniqueness is on the pair, so a lookup on the reference alone
        // is asking a question the index cannot answer — and the answer it would give is another provider's
        // row.
        if ($event->provider === null || $event->paymentReference === null) {
            return null;
        }

        return MerchantCharge::model()::query()
            ->where('provider', $event->provider)
            ->where('charge_reference', $event->paymentReference)
            ->first();
    }

    /**
     * Whether the seller's own small-value rules apply, from two stated countries.
     *
     * The buyer's is what they entered at the provider; the seller's is configured. Absent on either side
     * reads as not domestic, and that is the conservative direction rather than an answer: a domestic flag
     * switches a relief on, so guessing it true would claim one nobody is entitled to.
     */
    private function buyerIsDomestic(?string $buyerCountry): bool
    {
        $seller = $this->config->get('billing.company.country');

        return $buyerCountry !== null && $buyerCountry !== ''
            && is_string($seller) && $seller !== ''
            && mb_strtoupper($buyerCountry) === mb_strtoupper($seller);
    }
}
