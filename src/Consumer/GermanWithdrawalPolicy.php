<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonInterface;
use Pushery\Billing\Contracts\ConsumerWithdrawalPolicy;
use Pushery\Billing\Contracts\StatesWithdrawalWindow;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * The German reading of the consumer-withdrawal rules for digital goods.
 *
 * For a digital work the right to withdraw ends only when both declarations were made before provision
 * began and were confirmed to the buyer. Without them, every refund inside the window is owed rather than
 * granted — the difference between the platform deciding and the buyer deciding. Because the platform is
 * the seller of record here, that difference is the platform's risk, not the creator's.
 *
 * A subscription is different: the right does not extinguish on delivery, so provision is not gated on the
 * consent, and a withdrawal inside the window is settled by paying for the part already used.
 *
 * The statutes live here and in the notice data, never in the neutral core. A consumer elsewhere binds
 * their own reading and never sees the word "Widerruf".
 */
final readonly class GermanWithdrawalPolicy implements ConsumerWithdrawalPolicy, StatesWithdrawalWindow
{
    public function mayProvide(WithdrawalType $type, ?WithdrawalConsent $consent): bool
    {
        // Only the extinguish-on-delivery kind is gated: for it, beginning provision is the very act that
        // forfeits the buyer's right, so the law requires their informed agreement first.
        if ($type !== WithdrawalType::ExtinguishedOnDelivery) {
            return true;
        }

        return $consent?->isComplete() ?? false;
    }

    /**
     * Fourteen days from PROVISION — § 355 Abs. 2 BGB — where a window exists at all.
     *
     * ## Provision, not purchase and not payment
     *
     * For a pre-ordered work those are three different days, and only one of them can start a clock: the
     * one on which the buyer could first do something with what they bought. A window anchored to the sale
     * would already have expired on the day the work arrived.
     *
     * ## The three kinds that get no date, and they are not the same thing
     *
     * A work whose right EXTINGUISHED on delivery has no window left once the declarations were made and
     * confirmed — that is what those declarations do. Without them the right survives, so the window runs.
     *
     * `NotApplicable` never had one: no consumer contract, nothing for a right to attach to.
     *
     * And an UNCLASSIFIED sale gets none either, which is the one that would be tempting to default. The
     * archetype resolver states plainly that null means unclassified and never "no right applies", so a
     * date computed here would be a statutory-looking answer produced by a guess.
     */
    public function windowEndsFor(WithdrawalType $type, ?WithdrawalConsent $consent, CarbonInterface $providedAt): ?CarbonInterface
    {
        if ($type === WithdrawalType::NotApplicable) {
            return null;
        }

        if ($type === WithdrawalType::ExtinguishedOnDelivery && ($consent?->isComplete() ?? false)) {
            return null;
        }

        return $providedAt->toImmutable()->addDays(14);
    }

    public function valueForUse(WithdrawalType $type, Money $periodGross, int $elapsedDays, int $periodDays): Money
    {
        // A pro-rata charge only exists where the right survives provision — a subscription or a service in
        // progress. For anything whose right extinguished on delivery there is nothing part-used to bill.
        if ($type !== WithdrawalType::ProRataOnCancellation && $type !== WithdrawalType::ServicePerformed) {
            return new Money(0, $periodGross->currency);
        }

        // The value used is the elapsed portion, rounded once; the refund a caller derives is the difference
        // from the payment. Rounding this side and subtracting for the other is what keeps the two summing
        // back to the period exactly — 29.75 over 7 of 30 days is 6.94, refund 22.81. proportion() already
        // clamps every degenerate case to the answer a withdrawal can actually have: a period of zero days
        // and a negative elapsed span both to nothing, an elapsed span past the period to the whole of it —
        // so the fraction is handed over as-is rather than re-guarded here for a second time.
        return $periodGross->proportion($elapsedDays, $periodDays);
    }
}
