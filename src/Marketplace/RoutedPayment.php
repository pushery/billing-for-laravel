<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\SellerOfRecordResolver;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\ReceiveEligibilityDenied;
use Pushery\Billing\Exceptions\TaxStandingUnestablished;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\ArchetypeClassification;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;
use Pushery\Billing\ValueObjects\TaxonomyCell;

/**
 * Take a routed payment AND write down what was routed, as one operation.
 *
 * ## Why this exists at all
 *
 * The two halves were both present and never joined. `PaymentRails::charge()` can route a payment, and
 * `RoutedChargeLedger::record()` can write down that one was routed — and nothing called the second. The
 * result was a package that could move a merchant's money and then had no idea it had: no row, no totals to
 * cap a later reversal against, and three readers of that table answering "nothing" forever, one of which
 * decides whether a creator has crossed a small-business threshold.
 *
 * Leaving the recording to the caller looks reasonable and is not. It is not a bookkeeping nicety that can
 * be added later by whoever remembers: the reversal caps, the earnings count and the threshold verdict are
 * all computed FROM this table, so a payment that skipped it is not merely unlogged — it is invisible to
 * every rule that money is supposed to obey afterwards. One caller forgetting once is a merchant who can be
 * refunded past what they were paid.
 *
 * So the two are one operation here. A consumer that wants the raw rails still has them; this is the path
 * that keeps its own promises.
 *
 * ## What is recorded, and when
 *
 * On a charge that succeeded OR is still resolving — an intent awaiting 3-D Secure, a bank debit still
 * processing. Both are payments that may yet settle on a merchant's behalf, and a row that appeared only
 * after settlement would leave the most interesting window unrecorded: the one where an operator asks what
 * is in flight. The row starts `pending` and is moved by the settlement path, never by this method.
 *
 * A FAILED charge writes nothing. There is no payment to attribute, and a failed row would be counted by
 * every reader that looks at the table without also filtering a state it has no reason to expect.
 *
 * The split is recorded as it was decided HERE, not recomputed later: the fee policy can change, and a
 * reversal that recomputed the merchant's share from today's rate would claw back a figure the sale was
 * never made under.
 */
final readonly class RoutedPayment
{
    /**
     * The DRIVER is taken, not its rails, so the provider name and the rails that produced the reference
     * can never drift apart. A hardcoded provider here would attribute every charge to one processor and
     * make the ledger's own uniqueness claim — provider plus reference — quietly wrong the day a second
     * driver exists.
     */
    public function __construct(
        private BillingDriver $driver,
        private RoutedChargeLedger $ledger,
        /**
         * The receiving-side gate, run before a single provider call.
         *
         * Required, not optional, and that is the whole point of adding it. An optional gate defaults to
         * absent, absent reads as "no objection", and a money gate whose default answer is yes is not a
         * gate. Both sibling paths — `StripeCheckout` and `StripeOneTimeCharge` — take it as a required
         * dependency and refuse on it; this method, the ONLY place in the package that calls
         * `PaymentRails::charge()`, took nothing and refused nothing.
         */
        private CanReceiveMoney $receiving,
        /**
         * The tax-standing gate, run beside the receiving one and before any provider call.
         *
         * Required for the same reason, with one difference worth naming: this gate's shipped default
         * refuses EVERYBODY, because a merchant nobody has declared for is `Unclarified`. What keeps that
         * from stopping an established marketplace on upgrade is the hold's own enforcement date, not an
         * optional dependency here — an optional gate would default to absent, and absent reads as "no
         * objection", which is not a gate at all.
         */
        private CreatorTaxStatusHold $taxStanding,
        /**
         * The charge-type/posture pairing check, run on the routing this method is HANDED.
         *
         * Both sibling paths already do this — `StripeOneTimeCharge` and `StripeCheckout` each call
         * `assertCompatible` before assembling anything — and this path, the only one in `src/` that reaches
         * `PaymentRails::charge()`, did not. Same asymmetry as the receiving gate, same direction.
         *
         * The guard is not new and neither is the rule. What was missing is a moment. The resolver that
         * assembles a routing says in its own docblock that routing "is not constructible on the ordinary
         * path except through here" — but `ChargeRouting`'s constructor is public and this method accepts one
         * from its caller, so the ordinary path went around it. That resolver has no call site in `src/` at
         * all, and it is named here in prose rather than with its class name on purpose: the register that
         * finds unreferenced classes scans the shipped tree for the NAME, so writing it would clear a class
         * that still has no caller.
         *
         * Checked rather than re-derived, deliberately. Deriving would mean taking the destination and the
         * fee away from the caller, which is a different and much larger change to a public method; the
         * pairing is the part that is unsafe to accept on trust, because an incompatible one decides who
         * carries a dispute and the provider will not object.
         */
        private ChargeRoutingConsistencyGuard $pairing,
        /** Which side is seller of record, resolved here rather than accepted, so a caller cannot legalize its own pairing. */
        private SellerOfRecordResolver $postures,
        /** Only to read what this installation sells, which is what the posture turns on. */
        private Repository $config,
        /**
         * The product classifier, required for the same reason as the gates above it.
         *
         * It refused a sale with no archetype from the day it was written, and nothing could reach the
         * refusal: the archetype arrived as an argument, no record in this package carries one, and a
         * consumer who simply never called it sold anyway. A rule that cannot be reached is a comment.
         */
        private ProductClassifier $classifier,
        /**
         * The buyer's document for this sale.
         *
         * Held here rather than left to the consumer because a receipt that a caller may or may not ask for
         * is a receipt most callers will not ask for. The sale and its document are one movement: the money
         * moved because a supply happened, and the supply is what the document states.
         */
        private FanReceiptIssuer $receipts,
        /**
         * Which document tier this sale earns.
         *
         * Asked rather than chosen, because the answer is a threshold rule of the seller's own country and
         * not a property of this lane. Hard-coding a tier here would put the same rule in a second place,
         * and the day the threshold moves the two places would disagree with no test able to see it.
         */
        private FanReceiptTierResolver $tiers,
        /**
         * How the merchant's share is moved on the lane where the provider does not move it.
         *
         * Optional, and the type is what carries the answer: a driver either can do this or it cannot, and
         * a destination-charge install never needs it. Absent, a separate-transfer sale throws BEFORE any
         * money is charged rather than charging the buyer and then discovering there is no way to pay the
         * merchant.
         */
        private ?MovesMerchantShare $transfers = null,
    ) {}

    /**
     * Charge a buyer and record the routed sale in one step.
     *
     * @param  Model  $merchant  who the money is destined for — the recipient, never the payer
     * @param  Model  $buyerOwner  who pays, and who the receipt belongs to. Required, and that is a
     *                             deliberate break: this method took the money and issued nothing for two
     *                             releases because nothing here knew who the buyer was. Made optional, the
     *                             receipt would go on being skipped for every caller not yet updated —
     *                             silently, and for exactly the sales that already work.
     * @param  Money  $gross  what the buyer pays
     * @param  PlatformFee  $fee  the platform's cut, taken on the transaction's NET — see `record()`
     * @param  int  $taxBps  the tax on the buyer's side, in basis points, which is what separates the two.
     *                       Required rather than defaulted: a default of zero would silently keep charging
     *                       the commission on the gross for every caller that had not been updated, which
     *                       is the exact defect this argument exists to end.
     * @param  bool  $buyerIsDomestic  whether the small-value rules of the seller's own country apply, which
     *                                 is what decides the document tier. Supplied rather than derived, the
     *                                 same way `SubscriptionCycleBilling` takes it: the package has no
     *                                 second opinion about where a buyer is, and inventing one here would
     *                                 give the two receipt-issuing lanes two different answers.
     * @param  ?TaxArchetype  $archetype  what is being sold. Required and nullable on purpose — see below.
     * @param  ?TaxArchetype  $soldAlongside  for a voluntary payment, what it was paid ON. Meaningless
     *                                        otherwise, and refused when the archetype needs it and it is
     *                                        missing: a tip on commissioned work and a tip on a file
     *                                        download owe different things, and neither is a safe guess.
     */
    public function charge(
        Model $merchant,
        Model $buyerOwner,
        Money $gross,
        PlatformFee $fee,
        int $taxBps,
        bool $buyerIsDomestic,
        string $token,
        ChargeRouting $routing,
        ?TaxArchetype $archetype,
        ?TaxArchetype $soldAlongside = null,
        ?string $idempotencyKey = null,
    ): ChargeResult {
        // The archetype is REQUIRED and NULLABLE, which looks like a contradiction and is the point. Required
        // so it cannot be forgotten — a default would make "unclassified" the quiet normal case within a
        // release or two. Nullable so that passing nothing raises `ProductNotClassified`, the package's own
        // refusal with its own explanation, rather than a TypeError that says only which argument was the
        // wrong shape.
        //
        // This is where "no sale without a classification" stops being documentation. The classifier refused
        // correctly and unreachably: the archetype arrived as an argument, no record in this package carries
        // one, and nothing obliged a consumer to ask at all. Enforcing it HERE rather than by adding a column
        // is the decided answer (2026-07-28) — the catalog stays the consumer's, and the obligation lives at
        // the seam they already have to come through.
        //
        // It is a GATE first, and it earns that on its own by refusing two distinct states — no archetype at
        // all, and a voluntary payment that never said what it was paid on. A SALE OF NOTHING IS NOT A SALE,
        // and this is the first thing asked because everything after it assumes there is money.
        //
        // What comes back is now KEPT, and the reason is worth stating because this comment used to say the
        // opposite. The classification already works out where the supply is taxed and which rate band it
        // falls in; discarding that answer meant the receipt below either went without those characteristics
        // or had to derive them a second time, from a second source, at a later moment. Two derivations of
        // one fact is the divergence this package keeps paying for — and the second one would run after the
        // sale, when the configuration it reads may already have moved.
        //
        // Kept on the DOCUMENT rather than on the charge row: `billing_merchant_charges` has no column for
        // any of it, and the document is what a buyer and an authority are shown.
        $classification = $this->classifier->classify($archetype, $soldAlongside);
        //
        // The rule was already written and could not be reached: `FanChosenPricing` refuses a zero amount
        // with exactly this reasoning — "everything downstream, the provider call, the document, the
        // reportable inflow, must simply not happen, not happen-with-zeros" — and nothing in `src/` calls
        // that class. So the guard existed as prose while this path, the only one that reaches
        // `PaymentRails::charge()`, sent `amount: 0` to the provider and wrote a row for it.
        //
        // What a zero costs is not an error. It is a provider call that may well succeed, a charge row a
        // creator's earnings counter will read, a settlement document stating nothing, and a reportable
        // transaction in a tax return — all describing a sale nobody made. A fan who leaves the tip field
        // empty is the ordinary way to get there.
        //
        // Negative is refused by the same check rather than a second one: it is the same claim, that this
        // is not an amount somebody can pay.
        if (! $gross->isPositive()) {
            throw new InvalidArgumentException(
                "A routed sale needs a positive amount; got {$gross->minorUnits} {$gross->currency}."
            );
        }

        // Before anything, and before the provider most of all. Both sibling paths already refuse here and
        // this one did not, which is the wrong way round: they assemble a payment, while this is the only
        // place in `src/` that actually calls `PaymentRails::charge()`.
        //
        // The cost of refusing late is not a failed payment. A merchant who cannot receive does not produce
        // a clean rejection — the money settles wherever the provider can reach, usually the platform, while
        // the row this method is about to write says a merchant was paid. Nothing errors, the two records
        // disagree, and the disagreement is found by whoever reconciles, per transaction, by hand.
        //
        // `ReceiveEligibilityDenied` has said so in its own docblock since it was written: "the routed
        // payment was refused before it reached the provider". It names this path, and this path was the
        // one place that never raised it.
        if (! $this->receiving->check($merchant)) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        // The second gate, and it asks about the merchant's TAX standing rather than their ability to be
        // paid. A sale on behalf of somebody whose taxation nobody knows produces a settlement document
        // stating a treatment that nothing supports, and the two ways of guessing fail in opposite
        // directions -- so there is no safe default and the hold is the conservative answer.
        //
        // Unlike the receiving gate, this one arrives with a date on it. Its shipped default refuses
        // everybody, because a merchant nobody has declared for is `Unclarified` and that is exactly the
        // state that blocks -- so it does nothing until an operator sets `enforce_from`, which is the day
        // they have chosen for it to start. That is a rollout control, not a way of leaving it off: the
        // go-live checklist reports an unset date as outstanding.
        if ($this->taxStanding->blocksSales($merchant)) {
            throw TaxStandingUnestablished::forMerchant();
        }

        // Third gate, and the only one about the SHAPE of the payment rather than about the merchant. An
        // incompatible pairing decides who carries a dispute, and the provider accepts it without complaint
        // — so nothing surfaces until a chargeback lands on the wrong party, months later, once.
        // Resolved once and reused below, where it decides which document this sale produces. Asking twice
        // would let the two answers differ within one sale — the pairing checked against one posture and the
        // receipt issued under another — and config is re-readable at any point in a request.
        $posture = $this->postures->resolveFor($this->electronic());

        $this->pairing->assertCompatible($routing->type, $posture);

        $routesSeparately = $routing->type === ChargeType::SeparateTransfer;

        // The provider first. A row written before the charge would describe a payment that may never
        // happen, and the reversal caps would then be willing to give back money nobody ever took.
        //
        // A separate transfer's CHARGE is an ordinary platform charge -- the platform is the merchant of
        // record and takes the whole payment; the merchant's share moves in a second call below. So the
        // routing is deliberately NOT passed there. Handing it to the rails would ask them to serve a lane
        // they cannot serve alone, and they correctly refuse: the transfer can only be made once the payment
        // has actually succeeded, which is after `charge()` has already returned.
        $result = $this->driver->rails()->charge(
            $gross,
            $token,
            $idempotencyKey,
            $routesSeparately ? null : $routing,
        );

        if ($result->failed()) {
            return $result;
        }

        $charge = $this->record($merchant, $result, $gross, $fee, $taxBps, $routing->type);

        // A settled payment is settled NOW, from what the provider just said — not later, from a webhook
        // re-deriving it. The result already carries the transfer reference on a destination charge,
        // because the provider creates the transfer as the payment settles. Waiting for a webhook to
        // restate that would leave every routed sale pending until a delivery that adds nothing.
        //
        // Only an outright success settles. An intent still awaiting the cardholder, or a bank debit still
        // clearing, is genuinely pending: the money has not moved and a settled row would let a reversal
        // be capped against funds nobody has yet. Those resolve asynchronously and are the settlement
        // path's job, not this method's.
        if (! $result->successful) {
            return $result;
        }

        $this->ledger->settle($charge, $routesSeparately
            ? $this->moveMerchantShare($charge, $routing)
            : $result->transferReference);

        $this->issueBuyerDocument($buyerOwner, $posture, $gross, $taxBps, $buyerIsDomestic, $charge, $archetype, $classification, $soldAlongside);

        return $result;
    }

    /**
     * The buyer's document for a settled routed sale.
     *
     * ## Why it is here and not left to the consumer
     *
     * The money moved because a supply happened, and a supply that produces no document is the defect this
     * method exists to end: this lane took payment and issued nothing for two releases. Issued AFTER the
     * settlement deliberately — a document for a payment that has not happened is worse than none, and the
     * provider's answer is the only thing that knows.
     *
     * ## Which document, and why the posture decides
     *
     * A regime and a posture are one decision seen twice, so the posture already settled which document
     * this is. `InvoiceRecord` enforces the pair on creation, which means picking the wrong issuer here
     * throws rather than quietly freezing a regime that contradicts the receipt.
     *
     * - **Platform is the deemed supplier** — the platform resells in its own name, so it issues, and the
     *   document freezes `SupplyRegime::CommissionChain`. This is the shipped default posture.
     * - **The merchant is the seller of record** — the platform is not a party to the supply. It has no
     *   document to issue, and issuing one in its own name would name the wrong seller on a real receipt.
     * - **The platform merely arranges** — its fee is its turnover and the document is an intermediation
     *   receipt, which `issueIntermediated()` writes. Not reached yet, and NOT because it was forgotten.
     *   Two things have to be settled first. That document states the commission's OWN tax rate, and
     *   whether this seam carries one is still an open question — the ledger seam treats it as optional,
     *   and issuing a document that states a rate nobody supplied would put a number on a receipt that no
     *   decision stands behind. And `issueIntermediated()` writes directly rather than through
     *   `issueOnce()`, so a redelivery would draw a second number from a gapless series — the one failure
     *   a repeat cannot heal. Wiring it before both are answered would ship exactly the defect this method
     *   closes, one posture over.
     */
    private function issueBuyerDocument(
        Model $buyerOwner,
        SellerOfRecordPosture $posture,
        Money $gross,
        int $taxBps,
        bool $buyerIsDomestic,
        MerchantCharge $charge,
        ?TaxArchetype $archetype,
        ArchetypeClassification $classification,
        ?TaxArchetype $soldAlongside,
    ): void {
        if ($posture !== SellerOfRecordPosture::PlatformDeemedSupplier) {
            return;
        }

        // Read ONCE and reused below. Two calls to now() can land either side of a midnight or a second
        // boundary, and this document would then state a sale date and a delivery date that disagree about
        // when the same instant was.
        $soldOn = CarbonImmutable::now();

        $this->receipts->issue(
            buyerOwner: $buyerOwner,
            // Asked, never chosen here — see the constructor note. The threshold is a rule of the seller's
            // country, and a tier hard-coded on this lane would be that rule stated a second time.
            tier: $this->tiers->tierFor($gross, $buyerIsDomestic, false),
            gross: $gross,
            taxRateBps: $taxBps,
            // The sale is dated to its settlement, which is the moment this method runs. A one-time sale
            // covers no stretch of time, so there is no earlier date it could honestly carry.
            soldOn: $soldOn,
            // The anchor the settlement side already uses, so "the same sale" means one thing in both
            // places. This is what makes a redelivery return the document it already wrote instead of
            // drawing a second number.
            chargeReference: $charge->charge_reference,
            archetype: $archetype,
            // Where the supply is taxed and which rate band it falls in — carried from the classification
            // that already ran above rather than worked out a second time. See `fixedAnswer()` for why
            // either can legitimately be null.
            placeOfSupply: $this->fixedAnswer($classification->placeOfSupply, PlaceOfSupplyRule::class),
            rateCategory: $this->fixedAnswer($classification->rateCategory, TaxRateCategory::class),
            // BT-72, the date the supply actually happened. For a one-time sale that is the moment it was
            // sold: there is no stretch of time, and the buyer has what they paid for as soon as they pay.
            //
            // Absent where the treatment is DEFERRED, and that is the same answer as the two cells above
            // rather than a separate rule. A multi-purpose voucher has been paid for and nothing has been
            // supplied — stating a delivery date for it would date a supply that has not occurred, and
            // whichever period that lands in is one an authority could be shown.
            deliveredOn: $classification->placeOfSupply->isDeferred() ? null : $soldOn,
            // WHAT the two cells above were derived from, kept beside the answers rather than discarded with
            // the request. The document states where the supply is taxed; only this says why — and the one
            // consequence a tip's reference decides that no document states, whether the seller behind it has
            // to be reported, is asked months later by a run that has nothing but these rows to read.
            soldAlongside: $soldAlongside,
            // WHOSE charge reference the line above is. It is the same driver that produced it — taken from
            // the driver rather than named here, so the reference and the provider recorded beside it can
            // never come from two different places.
            //
            // Both of these arrived on separate branches, appended to the SAME parameter position, and this
            // call passed them positionally. Which value bound to which parameter was going to be decided by
            // whoever resolved this conflict, and two nulls in the wrong slots are caught by nothing. Named
            // arguments take the decision away from the merge.
            provider: $this->driver->name(),
        );
    }

    /**
     * The settled answer in a classification cell, or null where the sale has not settled one.
     *
     * A cell is only readable when it is FIXED, and `TaxonomyCell::value()` REFUSES on the other two kinds
     * rather than handing back a default. That refusal is the point of the type, and it is why this reads
     * the kind before the value instead of catching something.
     *
     * Both other kinds reach this method in ordinary use, so null here is a statement rather than a gap:
     *
     * - **Delegated** — a voluntary payment takes its answers from what it was paid alongside. It does not
     *   actually arrive null: `ProductClassifier::classify()` merges the delegated cells with the
     *   classification of the reference, so a tip on a download is taxed where a download is. That merge is
     *   the whole reason a tip's receipt can state a place of supply at all.
     * - **Deferred** — a multi-purpose voucher has no answer YET, because nothing has been bought with it.
     *   Writing one at issue would state a treatment that redemption may contradict, and a document cannot
     *   be re-stated.
     *
     * The `instanceof` is not defensive padding. `value()` returns `mixed`, and a taxonomy that put the
     * wrong type in a cell would otherwise reach the document as a value the column cannot hold.
     *
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @return T|null
     */
    private function fixedAnswer(TaxonomyCell $cell, string $type): ?object
    {
        if (! $cell->isFixed()) {
            return null;
        }

        $value = $cell->value();

        return $value instanceof $type ? $value : null;
    }

    /**
     * Move the merchant's share on the lane where the provider does not move it for us.
     *
     * On a destination charge the transfer is part of the payment. On a separate transfer it is a second
     * call, and if nobody makes it the merchant is simply never paid while every signal looks healthy -- a
     * successful result, no exception, and a null transfer reference indistinguishable from one still
     * settling. That was this package's actual behavior until now, on the DEFAULT charge type.
     *
     * The idempotency key is the charge ROW's id, not the amount. A retry that recomputed the share even
     * slightly differently would produce a second key and a second transfer; the row id cannot move.
     *
     * The AMOUNT comes off the row for the same reason the key does. It used to be split a second time from
     * the gross here, which was the same arithmetic in a second place — and the day the basis changed, the
     * two places would have had to change together or the merchant would have been transferred a share the
     * ledger says they were not paid. Reading what was written down cannot drift from what was written down.
     *
     * @throws MarketplaceUnsupported when the driver cannot move a share at all
     */
    private function moveMerchantShare(MerchantCharge $charge, ChargeRouting $routing): string
    {
        if (! $this->transfers instanceof MovesMerchantShare) {
            throw MarketplaceUnsupported::cannotMoveMerchantShare($this->driver->name());
        }

        return $this->transfers->transferShare(
            $routing->destination,
            $charge->net(),
            $charge->charge_reference,
            "billing_merchant_charge_{$charge->id}",
        )->reference;
    }

    /**
     * Write the routed sale down, once.
     *
     * Idempotent through the ledger's own claim on the provider reference, so a retried intent that reaches
     * the same charge converges on the row it already wrote rather than starting a second one with all its
     * reversal totals back at zero.
     */
    private function record(Model $merchant, ChargeResult $result, Money $gross, PlatformFee $fee, int $taxBps, ChargeType $chargeType): MerchantCharge
    {
        // THE COMMISSION IS TAKEN ON THE NET. The configuration has said so in as many words since the fee
        // was introduced -- "it is applied to the transaction's net, not to what the buyer paid" -- and the
        // pricing path obeys it. This path did not, and it is the one that decides what is actually kept:
        // the figure goes into the row, and on a destination charge it goes to the provider as the
        // application fee. On 119.00 at 19% with a 10% rate it kept 11.90 instead of 10.00, which is a
        // commission on the buyer's tax.
        //
        // Nothing looked wrong because the two bases coincide exactly when the fee is rate-only AND the
        // creator's inbound rate equals the outbound one -- the case the golden test runs. A flat
        // component, a small-business creator, a reverse-charge creator or a cross-border rate each break
        // the coincidence, and each of them quietly.
        $commissionBase = $gross->baseFromMarkup($taxBps)[0];

        $platformFee = $fee->of($commissionBase);

        // What the merchant receives is still the whole payment less the commission -- the buyer's tax
        // travels WITH the merchant's share, because on this lane it is the merchant who owes it. Computed
        // as the difference rather than as a second split, so the two sides sum back to the payment exactly.
        $merchantNet = $gross->minus($platformFee);

        return $this->ledger->record(
            merchant: $merchant,
            provider: $this->driver->name(),
            chargeReference: $result->reference,
            gross: $gross,
            fee: $platformFee,
            net: $merchantNet,
            // The terms as well as the amounts. Without them a partial clawback later has only today's
            // configuration to work from, so a platform that raises its rate would claw old sales back at
            // the new one — and both figures would look entirely plausible.
            policy: $fee,
            // And WHICH LANE it took, for the same reason and with a sharper edge. The two reverse in
            // completely different ways, and a refund reading the lane off today's configuration would be
            // wrong silently in both directions: a destination charge read as a separate transfer reverses
            // nothing and leaves the merchant a share of a refunded sale, while the reverse reading sends a
            // flag that does nothing and looks like it worked.
            chargeType: $chargeType,
            // And the BASE, which is the third frozen term and needed for exactly the same reason as the
            // first two: a partial clawback recomputes the commission on what remains of the sale, and
            // without the rate it would have to derive the remainder's net from today's configuration.
            commissionTaxBps: $taxBps,
        );
    }

    /**
     * What this installation sells, read from config exactly as `StripeCheckout` reads it.
     *
     * Read rather than assumed, and rather than taken as an argument. It is an installation-level fact, the
     * same level the charge type sits at — so config against config is coherent, while a per-call flag would
     * let one sale claim a nature the rest of the install does not have, which is the loophole the posture
     * being resolved here instead of passed is meant to close.
     */
    private function electronic(): bool
    {
        return (bool) $this->config->get('billing.marketplace.seller_of_record.supplies_are_electronic', true);
    }
}
