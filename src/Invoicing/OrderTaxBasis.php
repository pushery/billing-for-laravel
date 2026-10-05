<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxIdVerificationStatus;
use Pushery\Billing\Enums\VoucherInstrumentType;
use Pushery\Billing\Marketplace\CreditTopUpVolume;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Models\TaxIdVerification;
use Pushery\Billing\Tax\DistanceSaleThresholdMonitor;
use Pushery\Billing\Tax\PlaceEvidenceStore;
use Pushery\Billing\Tax\SaleTaxDecision;
use Pushery\Billing\Tax\SupplyPlaceDecision;
use Pushery\Billing\Tax\UnionMembership;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ServicePeriod;
use Pushery\Billing\ValueObjects\SupplyTaxCharacteristics;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * Determines the tax of one locally billed subscription cycle — or answers that it cannot.
 *
 * ## The hole this fills
 *
 * A provider-driven driver has its tax determined for it and the package copies the result. A local engine
 * has no such source, so `OrderInvoiceIssuer` left `tax_minor` null on every document it ever raised. That
 * was honest and incomplete at once: an invoice with no tax is not a WRONG document for a cycle that
 * triggers VAT, it is merely not a complete one.
 *
 * Everything needed to complete it was already here and none of it was wired: `SaleTaxDecision` had one
 * caller on the marketplace path, `SupplyPlaceDecision` had none at all, and no cycle in this package has
 * ever recorded where it was supplied. This is the caller.
 *
 * ## It refuses far more often than it answers, and that is the design
 *
 * Four things must be established before a numbered document may state a figure, and a null from any of
 * them ends the determination:
 *
 * 1. the cycle belongs to a subscription with a tier — a loose order is not a cycle;
 * 2. the tier carries an archetype — see below;
 * 3. the subscription's place of supply was established and recorded — see below;
 * 4. the cycle covers a period — a document with no period states no supply.
 *
 * A one-time purchase the engine sold answers the same four questions its own way, see `forPurchase()`: the
 * add-on catalog classifies it, the buyer's subscription places it, and the day it was paid dates it.
 *
 * A null answer leaves `tax_minor` null, which is exactly the state before this class existed. Null says
 * "nobody established this"; zero says "none was due". They are different claims and only one of them is
 * defensible without a basis.
 *
 * ## Why the archetype comes from the TIER and an unset one refuses
 *
 * A subscription is not automatically an electronically supplied service. One that includes a live
 * one-to-one session, a shipped item or a membership is placed under a different rule, and those are
 * precisely the tiers an operator configures differently. So the question is asked of the product —
 * `billing.tiers.<key>.archetype`, through {@see SuppliesProductArchetypes} — and the taxonomy behind
 * {@see SaleTaxDecision} turns the answer into a place rule, a band and an exemption.
 *
 * An UNSET archetype answers null and this refuses. Defaulting it to a subscription would be a guessed tax
 * treatment frozen onto an immutable document, and it would be right often enough that nobody would find
 * the times it was not.
 *
 * ## Why the place is READ and never re-derived
 *
 * The evidence for where a buyer is — what they declared, where their instrument is issued, where their
 * connection was — exists only at the moment of the sale. A cycle is billed by a scheduler weeks later,
 * with no connection open and nothing fresh to read, so there is nothing to derive from except a stored
 * address. Deriving from that would let a customer who has since moved change what an OLD cycle was taxed
 * under, retroactively and invisibly, which is the one thing {@see SupplyPlaceDecision} was built to
 * prevent.
 *
 * So the standard is the same one the marketplace path uses, unchanged and unweakened: the application
 * records the evidence once, when the subscription is established and the signals genuinely exist, under
 * {@see self::placeReferenceFor()}. Every cycle afterwards reads that record. An application that never
 * recorded it gets a null here and documents with no tax — the same documents it gets today.
 */
final readonly class OrderTaxBasis
{
    public function __construct(
        private SaleTaxDecision $taxes,
        private SupplyPlaceDecision $places,
        private PlaceEvidenceStore $evidence,
        private TierCatalog $tiers,
        private AddonCatalog $addons,
        private Repository $config,
        private ?DistanceSaleThresholdMonitor $thresholds = null,
    ) {}

    /**
     * The reference a subscription's place evidence is recorded under.
     *
     * Published as a method rather than left to each side to spell, because a reader and a writer that
     * agree on a string by convention are two strings that eventually differ — and the failure is silent
     * in the expensive direction: every cycle simply finds no evidence and issues a document with no tax,
     * which is indistinguishable from an application that never recorded any.
     *
     * The SUBSCRIPTION, not the order. The place is established once, when the customer signs up and the
     * signals exist; every cycle after that inherits it rather than re-establishing something nobody can
     * re-observe.
     */
    public static function placeReferenceFor(Subscription $subscription): string
    {
        // The declared `id`, not `getKey()`. They are the same value and only one of them has a type — a
        // reference built out of `mixed` is a reference that stringifies whatever it is handed.
        return 'subscription:'.$subscription->id;
    }

    /** The tax of this cycle and the basis behind it, or null where the basis is not establishable. */
    public function for(Order $order): ?DeterminedOrderTax
    {
        $subscription = $order->subscription;

        if (! $subscription instanceof Subscription) {
            return $this->forPurchase($order);
        }

        $archetype = $this->archetypeOf($subscription);
        $period = $this->periodOf($order);
        $reference = self::placeReferenceFor($subscription);
        $country = $this->places->countryFor($reference);

        if (! $archetype instanceof TaxArchetype || ! $period instanceof ServicePeriod || $country === null) {
            return null;
        }

        return $this->determined($order, $archetype, $period, $country, $reference);
    }

    /**
     * The tax of a one-time purchase this engine sold, or null where its basis is not establishable.
     *
     * A purchase is not a cycle, so three of the four questions are asked differently. The archetype comes from
     * the add-on catalog, which classifies what it sells as the tier catalog does. The place is the one recorded
     * for the buyer's own subscription: the evidence says where the customer is, it was collected when they signed
     * up, and a purchase made later has nothing fresher to read. The supply is the day the payment was confirmed,
     * because an add-on is supplied when it is paid for. Any of the three missing refuses, as for a cycle.
     *
     * A MONEY CREDIT IS A VOUCHER, and under a multi-purpose instrument no tax falls when it is sold: it falls on
     * what the balance later pays for. Stating a rate here would tax the same money twice, so that case refuses
     * rather than claiming either a figure or zero. `billing.marketplace.vouchers.instrument_type` decides it, the
     * setting the provider-driven checkout reads for the same question.
     *
     * A loose order that carries no add-on line is still not a sale this package can place, and refuses.
     */
    private function forPurchase(Order $order): ?DeterminedOrderTax
    {
        $key = $this->addonKeyOf($order);
        $processed = $order->processed_at;

        if ($key === null || $processed === null || ! $this->addons instanceof SuppliesProductArchetypes) {
            return null;
        }

        if (CreditTopUpVolume::isMoneyCredit($this->addons, $key)
            && ! VoucherInstrumentType::fromConfigured($this->config->get(VoucherInstrumentType::CONFIG_KEY))->taxedAtIssue()) {
            return null;
        }

        $archetype = $this->addons->archetypeFor($key);
        $reference = $this->placedReferenceOf($order);
        $country = $reference === null ? null : $this->places->countryFor($reference);

        if (! $archetype instanceof TaxArchetype || $reference === null || $country === null) {
            return null;
        }

        $day = CarbonImmutable::parse($processed->toDateString());

        return $this->determined(
            $order,
            $archetype,
            new ServicePeriod($day, $day, Money::of($order->total_minor, $order->currency)),
            $country,
            $reference,
        );
    }

    /** The add-on a purchase order sold, read off its add-on line, or null where it has none. */
    private function addonKeyOf(Order $order): ?string
    {
        $line = $order->items()->where('type', OrderItemType::Addon)->first();
        $key = $line?->metadata['addon_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The place reference of the buyer's newest subscription that has one recorded, or null where none has.
     *
     * Newest first, because a customer who subscribed again did so from where they are now.
     */
    private function placedReferenceOf(Order $order): ?string
    {
        $subscriptions = Subscription::model()::query()
            ->where('owner_type', $order->owner_type)
            ->where('owner_id', $order->owner_id)
            ->orderByDesc('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            $reference = self::placeReferenceFor($subscription);

            if ($this->places->countryFor($reference) !== null) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * The determination itself, the same for a cycle and a purchase once each has answered its own questions.
     *
     * One method rather than two copies, because the gross, the buyer and the document fields are the half that
     * must not differ between the two: an invoice for a purchase and one for a cycle are read by the same reader.
     */
    private function determined(Order $order, TaxArchetype $archetype, ServicePeriod $period, string $country, string $reference): DeterminedOrderTax
    {
        $gross = Money::of($order->total_minor, $order->currency);
        $buyer = $this->buyerOf($order, $country);

        $paidOn = $order->processed_at === null ? null : CarbonImmutable::parse($order->processed_at->toIso8601String());

        // Priced GROSS, because that is how the cycle was charged: the customer paid the order total and
        // the tax is the part of it that belongs to the state. Determining from a net would mean inventing
        // a net nobody charged and then adding tax on top of it, producing a total the customer never paid.
        $facts = $this->taxes->decideOnGross($archetype, $gross, $buyer, $period, $paidOn);
        $destination = $facts->country;

        // Below the distance-sale threshold, a cross-border sale to a consumer is taxed where the seller is
        // (Art. 59c of the VAT Directive), up to the sale that takes the year past it. The monitor answered that
        // for the recorded evidence alone, and every cycle was taxed at the destination and reported there
        // whatever the operator had configured. Asked with this sale's own net, the crossing sale is already
        // over the line, as the monitor's rule has it.
        if ($facts->reportableUnderOneStopShop() && $this->thresholds instanceof DistanceSaleThresholdMonitor && $this->thresholds->watched()) {
            $atTheSeller = $this->taxes->decideOnGross($archetype, $gross, $buyer, $period, $paidOn, belowDistanceSaleThreshold: true);
            $year = ($paidOn ?? CarbonImmutable::now())->year;

            if ($this->thresholds->ruleForSale($year, $order->currency, $gross->minus($atTheSeller->tax)->minorUnits) === PlaceOfSupplyRule::Domestic) {
                $facts = $atTheSeller;
                // The customer's country stays on the document although the tax is the seller's: the column
                // names where the customer is, and the threshold counts the sale by it. Left empty, the sale
                // would drop out of the count, and the limit would never be reached.
                $destination = UnionMembership::territoryOf($buyer->countryCode);
            }
        }

        return new DeterminedOrderTax(
            net: $gross->minus($facts->tax),
            tax: $facts->tax,
            rateBps: $facts->rateBps,
            reverseCharge: $facts->reverseCharge(),
            exempt: $facts->exempt(),
            oneStopShop: $facts->reportableUnderOneStopShop(),
            characteristics: new SupplyTaxCharacteristics(
                archetype: $archetype,
                placeOfSupply: $facts->placeRule(),
                rateCategory: $facts->rateCategory,
                exemptionReason: $facts->exemption,
                // Deliberately no `deliveredOn`. A cycle billed in advance has not been supplied yet, and
                // stating a delivery date in the future on a document dated before it is worse than
                // stating none — the period columns already say what is covered.
                destinationCountry: $destination,
                destinationSubdivision: $this->evidence->subdivisionFor($reference),
            ),
            period: $period,
            recipient: $facts->placement->recipient,
            // The ID the placement was decided on, and its country, travel to the document together. A
            // reverse charge names the buyer's ID on the invoice, and the ID stated there has to be the one
            // the zero rate rests on rather than whatever the customer record says later.
            buyerVatId: $buyer->vatIdValid ? $buyer->vatId : null,
            buyerCountry: $buyer->vatIdValid ? $buyer->countryCode : null,
            appliedRateBps: $facts->appliedRateBps,
        );
    }

    /** What kind of supply this tier sells, or null where nobody classified it. */
    private function archetypeOf(Subscription $subscription): ?TaxArchetype
    {
        $key = $subscription->tier_key;

        if ($key === null || $key === '' || ! $this->tiers instanceof SuppliesProductArchetypes) {
            return null;
        }

        return $this->tiers->archetypeFor($key);
    }

    /**
     * The cycle as a period a document may state, or null where it covers none.
     *
     * ## The end moves by a day, and it has to
     *
     * A subscription's `current_period_end` is the next period's START — `advanceCycle()` carries it
     * straight over — while a {@see ServicePeriod} ends on the last day COVERED. Copied across unchanged,
     * every document would claim a day the following document also claims, and two invoices lined up would
     * overlap by one day each month with every total still adding up.
     */
    private function periodOf(Order $order): ?ServicePeriod
    {
        $start = $order->period_start;
        $end = $order->period_end;

        if ($start === null || $end === null) {
            return null;
        }

        $from = CarbonImmutable::parse($start->toDateString());
        $to = CarbonImmutable::parse($end->toDateString())->subDay();

        if ($to->lessThan($from)) {
            return null; // a cycle that covers no whole day states no period, and inventing one would date a supply
        }

        return new ServicePeriod($from, $to, Money::of($order->total_minor, $order->currency));
    }

    /**
     * What is established about the customer's tax status — and `Consumer` wherever nothing is.
     *
     * The reverse charge zero-rates a supply, so it may never rest on an id that was merely present. The
     * package holds exactly one dated, recorded fact that clears that bar: a {@see TaxIdVerification} a
     * register answered `verified` for. Absent one, the customer is treated as a consumer, which is the
     * safe direction — over-charging is corrected with a credit note, under-charging VAT is a liability.
     *
     * Read across providers on purpose. The row records which provider ASKED, but the answer is about the
     * buyer and the register: an id the register confirmed is confirmed however the question reached it,
     * and scoping the read to the local driver would mean an install that verifies through one provider
     * and bills through another reverse-charges nothing it should.
     *
     * A `verified` answer counts only while it is the latest word on its id. A register that later answers
     * `unverified`, or a customer who removed the number, ends it; reading the latest `verified` row alone
     * reverse-charged every cycle after either.
     */
    private function buyerOf(Order $order, string $country): TaxContext
    {
        $verified = $this->standingVerification($order);

        if (! $verified instanceof TaxIdVerification) {
            return new TaxContext(countryCode: $country);
        }

        $registered = $this->registrationCountryOf($verified->type, $verified->value);

        // An id whose country this package cannot read proves a business somewhere, and "somewhere" is not a
        // place of supply. It stays unproven, which charges tax rather than dropping it.
        if ($registered === null) {
            return new TaxContext(countryCode: $country, vatId: $verified->value, business: true);
        }

        return new TaxContext(
            countryCode: $registered,
            vatId: $verified->value,
            business: true,
            vatIdValid: true,
        );
    }

    /**
     * The verification the reverse charge may rest on: of the customer's tax ids, the most recent whose latest
     * deciding answer is `verified`.
     *
     * An answer decides when it is `verified`, `unverified` or `removed`. A `pending` or `unavailable` one says
     * the register was not heard, so it neither confirms an id nor takes a confirmation back. An id that turns
     * `verified` again after an `unverified` answer has a row for that answer too, and the reverse charge rests
     * on it again.
     */
    private function standingVerification(Order $order): ?TaxIdVerification
    {
        $answers = TaxIdVerification::model()::query()
            ->where('owner_type', $order->owner_type)
            ->where('owner_id', $order->owner_id)
            ->whereIn('status', array_values(array_filter(
                TaxIdVerificationStatus::cases(),
                static fn (TaxIdVerificationStatus $status): bool => $status->decides(),
            )))
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get();

        $decided = [];

        foreach ($answers as $answer) {
            $id = $answer->provider.'|'.$answer->tax_id_reference;

            if (isset($decided[$id])) {
                continue;
            }

            $decided[$id] = true;

            if ($answer->status === TaxIdVerificationStatus::Verified) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * The country a verified tax id registers its holder in, or null where the id does not say.
     *
     * A business supply is placed where the business receives it, and the id the business gives names that
     * establishment (Article 22(1) of Implementing Regulation (EU) No 282/2011). So the country comes from
     * the id, not from the place evidence, which answers where a CONSUMER is: a German business whose
     * evidence reads AT is still a German business buying from a German seller.
     *
     * A union VAT id carries its country as a prefix, read as itself except `EL` as `GR` and `XI` as `GB`,
     * because a Northern Irish id is a United Kingdom business for a service. Every other type names its
     * country before the underscore, such as `gb_vat` or `ch_vat`. A type that names no single country,
     * such as `eu_oss_vat`, gives null.
     */
    private function registrationCountryOf(string $type, string $value): ?string
    {
        if ($type === 'eu_vat') {
            $prefix = strtoupper(substr(trim($value), 0, 2));

            return match (true) {
                $prefix === 'EL' => 'GR',
                $prefix === 'XI' => 'GB',
                preg_match('/^[A-Z]{2}$/', $prefix) === 1 => $prefix,
                default => null,
            };
        }

        return preg_match('/^([a-z]{2})_[a-z]+$/', $type, $matches) === 1 ? strtoupper($matches[1]) : null;
    }
}
