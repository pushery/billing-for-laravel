<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Marketplace\FanReceiptIssuer;
use Pushery\Billing\Marketplace\FanReceiptTierResolver;
use Pushery\Billing\Marketplace\ProductClassifier;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\SupplyTaxCharacteristics;

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
 * place through `customer_details.address` — WHERE THE BUYER SAID THEY WERE, rather than a country somebody
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
 * - **A tax the provider computed** — a positive figure means the rate came from the buyer's address, and
 *   this package then holds amounts and no rate. What a document may state there is an open decision rather
 *   than a derivation, and stating a rate nobody supplied is the defect this class exists to end, one field
 *   over. Zero is a different answer: it says tax was computed and there was none.
 * - **A buyer this application does not own** — the same silence `PersistInvoice` keeps.
 * - **An add-on nobody classified** — the classifier refuses one, and refusing is right: a treatment is
 *   not one unknown but five guesses. A document about a supply whose treatment nobody established would
 *   be the same defect as one stating a rate nobody supplied.
 *
 * ## Once, however often the provider redelivers
 *
 * Through `issue()`, which goes to the repeat guard keyed on the charge reference. A second delivery finds
 * the document that exists instead of drawing a second number out of a gapless series — the one failure a
 * repeat cannot heal.
 */
final readonly class IssueDocumentForRoutedHostedPurchase
{
    public function __construct(
        private CustomerDirectory $directory,
        private AddonCatalog $addons,
        private ProductClassifier $classifier,
        private FanReceiptIssuer $receipts,
        private FanReceiptTierResolver $tiers,
        private Repository $config,
    ) {}

    public function __invoke(AddonPurchased $event): void
    {
        $charge = $this->routedCharge($event);

        if (! $charge instanceof MerchantCharge) {
            return;
        }

        // Read off the ROW, which froze it when the session opened — not from today's configuration, which
        // on a bank debit can be days younger than the sale.
        if ($charge->postureOr(SellerOfRecordPosture::PlatformDeemedSupplier) !== SellerOfRecordPosture::PlatformDeemedSupplier) {
            return;
        }

        // Null and zero are not the same answer here. Zero says the provider computed the tax and there was
        // none; null says the payload carried no breakdown, and a document dated off a figure nobody
        // reported would be this lane's own guess.
        if ($event->taxMinor !== 0) {
            return;
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        $archetype = $this->archetypeOf($event->addonKey);

        // A FIFTH refusal, and it was very nearly a throw inside a queued job. The classifier does not take
        // null — it raises `ProductNotClassified`, and its message says why: "the alternative is not one
        // unknown but five guesses, each of which would be right often enough to look fine". So an
        // unclassified add-on gets no document rather than a document about a treatment nobody established,
        // and the sale stays exactly as visible as it was.
        if (! $archetype instanceof TaxArchetype) {
            return;
        }

        $classification = $this->classifier->classify($archetype);

        // Read ONCE and reused, the same way the synchronous path does it: two calls to now() can land either
        // side of a second boundary, and the document would then state a sale date and a delivery date that
        // disagree about when the same instant was.
        $soldOn = CarbonImmutable::now();

        $this->receipts->issue(
            buyerOwner: $owner,
            tier: $this->tiers->tierFor($event->amount, $this->buyerIsDomestic($event->buyerCountry), false),
            gross: $event->amount,
            // Zero, and stated rather than derived. This branch is only reached where the provider computed
            // no tax, so the price IS the gross with nothing separately stated — the same honest record the
            // ledger row makes of the commission on this lane.
            taxRateBps: 0,
            soldOn: $soldOn,
            // The anchor the settlement side already uses, so "the same sale" means one thing in both places
            // and a redelivery returns the document it already wrote.
            chargeReference: $event->paymentReference,
            characteristics: new SupplyTaxCharacteristics(
                archetype: $archetype,
                placeOfSupply: $classification->placeOfSupply->fixedAnswerOf(PlaceOfSupplyRule::class),
                rateCategory: $classification->rateCategory->fixedAnswerOf(TaxRateCategory::class),
                // BT-72, and absent where the treatment defers the supply: a multi-purpose voucher has been
                // paid for and nothing has been supplied, so dating a delivery would date something that has
                // not happened.
                deliveredOn: $classification->placeOfSupply->isDeferred() ? null : $soldOn,
                destinationCountry: $event->buyerCountry,
            ),
            provider: $event->provider,
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
        // BOTH halves of the key. The table's uniqueness is on the pair, so a lookup on the reference alone
        // is asking a question the index cannot answer — and the answer it would give is another provider's
        // row.
        if ($event->provider === null || $event->paymentReference === null) {
            return null;
        }

        return MerchantCharge::query()
            ->where('provider', $event->provider)
            ->where('charge_reference', $event->paymentReference)
            ->first();
    }

    /**
     * Whether the seller's own small-value rules apply, from two stated countries.
     *
     * The buyer's is what they entered at the provider; the seller's is configured. Absent on either side
     * reads as NOT domestic, and that is the conservative direction rather than an answer: a domestic flag
     * switches a relief ON, so guessing it true would claim one nobody is entitled to.
     */
    private function buyerIsDomestic(?string $buyerCountry): bool
    {
        $seller = $this->config->get('billing.company.country');

        return $buyerCountry !== null && $buyerCountry !== ''
            && is_string($seller) && $seller !== ''
            && mb_strtoupper($buyerCountry) === mb_strtoupper($seller);
    }
}
