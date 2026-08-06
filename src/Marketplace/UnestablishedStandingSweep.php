<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Events\CreatorPlacedOnTaxHold;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Models\TaxHoldWarning;

/**
 * Tells the merchants who have never declared that the deadline is coming — BEFORE it arrives.
 *
 * ## The gap this closes
 *
 * The configuration says what to do and nothing did it. Beside `enforce_from` stands the instruction, in as
 * many words: *"pick a date far enough out to collect declarations, tell the merchants who are missing one,
 * and let the date arrive."* The date arrives on its own. The telling had no code.
 *
 * So the first a merchant heard of it was a refused sale. They had done nothing, been asked for nothing,
 * and the failure arrived at the till — which is both the worst moment and the one where they can do least
 * about it, because a declaration takes longer than a checkout.
 *
 * ## Why a sweep, and why this one cannot reuse the other marker
 *
 * Same reason as the lapsed-attestation sweep: nothing to observe. A merchant who never declared performs
 * no action, so there is no write to watch — only a date approaching. But that sweep marks the status
 * record it announced, and these merchants HAVE no status record. Never declaring is precisely what puts
 * them in the blocking state. Writing a placeholder record to hold a flag would invent a declaration.
 *
 * ## Who is warned, and who is not
 *
 * Merchants who have taken money — a routed charge is what makes the hold consequential for them — and
 * whose standing today would not carry them past the deadline. A merchant with no sales is not warned:
 * telling somebody about a deadline for an activity they have not started is noise, and they will be told
 * by the sale that would have been their first if they still have not declared.
 *
 * ## Once per deadline, not once ever
 *
 * The warning is sent once, because repeating it nightly turns the one channel a merchant has into noise.
 * But a deadline an operator MOVES is a different deadline: the old warning no longer describes it, and a
 * merchant who was told about March is not thereby told about June. The marker carries the date for exactly
 * that reason.
 */
final readonly class UnestablishedStandingSweep
{
    public function __construct(
        private Repository $config,
        private Dispatcher $events,
        private CreatorTaxStatusHold $hold,
    ) {}

    /**
     * Warn everyone the deadline is about to catch.
     *
     * @return int how many merchants were told
     */
    public function warn(CarbonImmutable $now): int
    {
        $deadline = $this->deadline();

        if (! $deadline instanceof CarbonImmutable) {
            // No date set means the hold does not bite yet, so there is nothing to warn about. This is the
            // shipped state and it stays silent rather than inventing a deadline to warn about.
            return 0;
        }

        // Only inside the lead time, and only before the date. Warning a year out is forgotten by the time
        // it matters; warning after the fact is not a warning, it is a report — and that case is already
        // covered by the refusal the merchant will meet.
        if ($now->greaterThanOrEqualTo($deadline) || $now->lessThan($deadline->subDays($this->leadDays()))) {
            return 0;
        }

        $warned = 0;

        foreach ($this->merchantsAtRisk($deadline) as $merchant) {
            if ($this->alreadyWarned($merchant, $deadline)) {
                continue;
            }

            $this->events->dispatch(new CreatorPlacedOnTaxHold($merchant, 'billing::tax_hold.deadline_approaching'));

            // Marked after dispatching, for the same reason the sibling sweep gives: a crash between the
            // two warns once more, which somebody can live with, while the other order loses the warning
            // entirely, which is what this class exists to prevent.
            TaxHoldWarning::query()->create([
                'merchant_type' => $merchant->getMorphClass(),
                'merchant_id' => $merchant->getKey(),
                'deadline' => $deadline->toDateString(),
                'warned_at' => $now,
            ]);

            $warned++;
        }

        return $warned;
    }

    /**
     * The merchants who have sold and would be held on the day.
     *
     * Asked of the hold rather than reimplemented: whether a standing blocks is its question, and a second
     * copy of that rule here would answer differently the first time either changed.
     *
     * @return list<Model>
     */
    private function merchantsAtRisk(CarbonImmutable $deadline): array
    {
        $atRisk = [];

        $charges = MerchantCharge::query()
            ->whereNull('merchant_erased_at')
            ->orderBy('id')
            ->get(['merchant_type', 'merchant_id'])
            ->unique(fn (MerchantCharge $charge): string => $charge->merchant_type.'|'.$charge->merchant_id);

        foreach ($charges as $charge) {
            $merchant = $charge->merchant()->first();

            if (! $merchant instanceof Model) {
                continue;
            }

            // Asked AS OF THE DEADLINE, which is what makes this a warning rather than a report: the hold
            // does not bite today, so asking about today would find nobody.
            if ($this->hold->blocksSales($merchant, $deadline)) {
                $atRisk[] = $merchant;
            }
        }

        return $atRisk;
    }

    private function alreadyWarned(Model $merchant, CarbonImmutable $deadline): bool
    {
        return TaxHoldWarning::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->whereDate('deadline', $deadline->toDateString())
            ->exists();
    }

    /** How many days before the deadline the warning goes out. */
    private function leadDays(): int
    {
        $days = $this->config->get('billing.marketplace.tax_status_hold.warn_days_before', 30);

        return is_int($days) && $days > 0 ? $days : 30;
    }

    private function deadline(): ?CarbonImmutable
    {
        $configured = $this->config->get('billing.marketplace.tax_status_hold.enforce_from');

        return is_string($configured) && $configured !== ''
            ? CarbonImmutable::parse($configured)
            : null;
    }
}
