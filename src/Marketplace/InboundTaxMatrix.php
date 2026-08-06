<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\ValueObjects\InboundTaxTreatment;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * The tax effect of one creator's supply into a commission-chain sale — a pure function of who the creator
 * is, what the sale was, and the platform's take.
 *
 * The input side is the creator's supply TO the platform (the fiction that lets the platform resell in its
 * own name). It is not the output side: whatever this decides, the buyer's receipt carries the platform's
 * full tax unchanged. A personal attribute of one party in a chain applies to that party's own link and no
 * other.
 *
 * Three things are true across every case and worth stating once. First, the cost the creator's link
 * represents is the PAYOUT, never the fan's net — a platform that expensed the fan net would book a hole
 * the size of its own margin on every transaction. Second, tax may be STATED only for a creator who charges
 * it normally; every other standing yields a document with no tax statement, or no document at all, because
 * a self-billed invoice that wrongly states tax makes its recipient owe that tax. Third, the platform's
 * margin is never a taxable line here — the commission is the difference between two supplies, not a supply
 * of its own, so no reverse-charge and no separate invoice follows from it in this regime.
 *
 * The matrix belongs to the commission chain alone. Applied to an intermediation sale it refuses rather
 * than guesses: there the platform arranges someone else's supply and issues a real fee invoice, a
 * different document set entirely.
 *
 * Everything national — which rate, which statute names the exemption, what the reverse-charge note says —
 * enters as the rate argument or lives downstream in the jurisdiction profile. The matrix itself maps the
 * code's own status vocabulary to the code's own document vocabulary and reads no law.
 */
final class InboundTaxMatrix
{
    /**
     * @param  Money  $transactionNet  the sale's net (the fan's net, before the platform's own output tax)
     * @param  PlatformFee  $commission  the platform's take — the payout is what remains of the net after it
     * @param  int  $supplyRateBps  the rate of the creator's own supply, in basis points (a jurisdiction input)
     */
    public function resolve(
        SupplyRegime $regime,
        CreatorTaxStatus $creatorStatus,
        Money $transactionNet,
        PlatformFee $commission,
        int $supplyRateBps,
    ): InboundTaxTreatment {
        if ($regime !== SupplyRegime::CommissionChain) {
            throw RegimeNotPermitted::intermediationHasNoInboundTaxMatrix($regime);
        }

        $currency = $transactionNet->currency;
        $payout = $commission->netOf($transactionNet);

        return match ($creatorStatus) {
            // Charges tax normally: the one case that states tax. The self-billed invoice carries the rate on
            // the payout, and the creator is paid that tax through on top of it.
            CreatorTaxStatus::DomesticStandardRated => $this->taxed($payout, $supplyRateBps),

            // Taxable, but the tax STATEMENT waits for the registry to confirm the standing. The document
            // issues without it and the net flows now; the tax follows as a correction once confirmed.
            CreatorTaxStatus::DomesticStandardRatedPendingValidation => new InboundTaxTreatment(
                SettlementDocumentType::SelfBilledInvoice, false, Money::zero($currency), false, $payout,
            ),

            // Exempt on their own supply: a self-billed invoice with no tax, and exempt is marked so the
            // document renders EN 16931 category E (with a reason) rather than zero-rated. Both small-business
            // standings share this row — tax-free is tax-free.
            CreatorTaxStatus::DomesticSmallBusiness,
            CreatorTaxStatus::UnionSmallBusinessExempt => new InboundTaxTreatment(
                SettlementDocumentType::SelfBilledInvoice, false, Money::zero($currency), false, $payout, exempt: true,
            ),

            // Not in business: no invoice is written for them at all, only a settlement note, and it states
            // no tax and may show none.
            CreatorTaxStatus::PrivateIndividual => new InboundTaxTreatment(
                SettlementDocumentType::SettlementNote, false, Money::zero($currency), false, $payout,
            ),

            // A business abroad: a net self-billed invoice, and the tax burden reverses onto the recipient
            // (the platform) rather than being stated on the document.
            CreatorTaxStatus::UnionBusiness,
            CreatorTaxStatus::NonUnionBusiness => new InboundTaxTreatment(
                SettlementDocumentType::SelfBilledInvoice, false, Money::zero($currency), true, $payout,
            ),

            // Unestablished: a HOLD. No document, no number, and nothing paid until the standing is
            // recorded. There is no safe guess and that is a symmetry rather than caution — assume the
            // creator charges tax normally and the document states tax a small business does not owe, at
            // which point the recipient owes it merely because a document says so, unless they object in
            // time to a document they never asked for; assume the opposite and the document understates a
            // real liability and forfeits a deduction. The two errors point in opposite directions, so
            // neither default is the cautious one. Not producing the document is.
            //
            // A hold rather than an exception, deliberately, and this is the half that is easy to get
            // wrong. An unestablished standing is a routine, expected state — a creator who has not yet
            // declared — not an error condition. Throwing would also be actively worse than useless in the
            // collective engine, which walks a month of transactions per creator and skips the held ones:
            // one unclarified creator would abort the entire month's document for everybody in it.
            //
            // It lifts on its own when a standing is recorded. There is no override and no key naming a
            // default standing, because either would be the silent default this exists to prevent.
            CreatorTaxStatus::Unclarified => InboundTaxTreatment::hold($currency),
        };
    }

    private function taxed(Money $payout, int $supplyRateBps): InboundTaxTreatment
    {
        $tax = $payout->proportion($supplyRateBps, 10_000);

        return new InboundTaxTreatment(
            SettlementDocumentType::SelfBilledInvoice, true, $tax, false, $payout->plus($tax),
        );
    }
}
