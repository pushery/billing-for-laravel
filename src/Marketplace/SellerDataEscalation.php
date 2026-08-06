<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\SellerDataEscalationStage as Stage;
use Pushery\Billing\Enums\SellerDataMeasure;
use Pushery\Billing\Enums\SellerRecordCompleteness;

/**
 * Turns "this seller has not supplied their data" into a wired sequence rather than somebody noticing in
 * January.
 *
 * Two separations carry the design, and both exist because the obvious single rule is wrong.
 *
 * REMINDERS AND MEASURES ARE NOT THE SAME REACH. Everyone with an incomplete record gets reminded — asking
 * is free and the data is wanted. But suspending an account or holding somebody's earnings only follows
 * where the missing data is legally required OF THAT SELLER. Doing it to a seller no reporting duty covers
 * is not compliance; it is withholding a service on the strength of a rule that does not apply to them.
 *
 * A WITHHOLDING CANNOT OUTLAST THE MONEY. "Held until they cooperate" is open-ended, and the rail the money
 * sits on is not: it has a hard limit after which the money must move. Two clocks set independently, never
 * compared — so this one reads the other's limit and stops there rather than running past it and turning a
 * compliance measure into a stuck payment.
 */
final readonly class SellerDataEscalation
{
    public function __construct(private Repository $config) {}

    /**
     * The stage a seller should be at.
     *
     * @param  bool  $missingRequired  whether what is outstanding is legally required of THIS seller
     * @param  int  $daysOutstanding  how long the record has been incomplete
     */
    public function stageFor(SellerRecordCompleteness $record, bool $missingRequired, int $daysOutstanding): Stage
    {
        if ($record === SellerRecordCompleteness::Complete) {
            return Stage::Clear;
        }

        $measureAfter = $this->days('measure_after_days', 60);
        $secondAfter = $this->days('second_reminder_after_days', 30);
        $firstAfter = $this->days('first_reminder_after_days', 7);

        if ($daysOutstanding >= $measureAfter) {
            // The separation that matters. Past the deadline, a seller whose missing data nobody is
            // entitled to demand stays at the last reminder — reminded, not sanctioned.
            return $missingRequired || $this->measuresPrecautionaryGaps()
                ? Stage::MeasureActive
                : Stage::SecondReminder;
        }

        return match (true) {
            $daysOutstanding >= $secondAfter => Stage::SecondReminder,
            $daysOutstanding >= $firstAfter => Stage::FirstReminder,
            default => Stage::Clear,
        };
    }

    /** Which measure applies. */
    public function measure(): SellerDataMeasure
    {
        $configured = $this->config->get('billing.marketplace.seller_data_escalation.measure');

        return is_string($configured)
            ? SellerDataMeasure::tryFrom($configured) ?? SellerDataMeasure::WithholdPayout
            : SellerDataMeasure::WithholdPayout;
    }

    /**
     * How long a withholding may last, counted from when the money arrived.
     *
     * Capped by the money rail's own limit even when the escalation would hold longer. A hold that outran
     * the rail would not be a stricter measure, it would be a payment nobody can complete — and the seller
     * would have gone from "unpaid until they cooperate" to "unpaid, and the platform has a problem too".
     */
    public function withholdingDaysFrom(int $arrivedDaysAgo): int
    {
        $limit = $this->days('payout_deadline_days', 90);
        $wanted = $this->days('withhold_up_to_days', 90);

        return max(0, min($wanted, $limit) - $arrivedDaysAgo);
    }

    /** Whether a withholding started this long ago must now be released or converted. */
    public function withholdingExhausted(int $arrivedDaysAgo): bool
    {
        return $this->withholdingDaysFrom($arrivedDaysAgo) === 0;
    }

    /**
     * Whether measures also apply where only precautionary data is missing.
     *
     * Off by default and deliberately a decision rather than a default: extending a sanction to data no law
     * requires is a contract question between a platform and its sellers, not something a package settles
     * for them.
     */
    private function measuresPrecautionaryGaps(): bool
    {
        return (bool) $this->config->get('billing.marketplace.seller_data_escalation.measure_precautionary_gaps', false);
    }

    private function days(string $key, int $default): int
    {
        $days = $this->config->get('billing.marketplace.seller_data_escalation.'.$key, $default);

        return is_int($days) && $days > 0 ? $days : $default;
    }
}
