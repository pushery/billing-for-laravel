<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Contracts\EscalatesMissingSellerData;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\MerchantStatus;
use Pushery\Billing\Enums\SellerDataEscalationStage as Stage;
use Pushery\Billing\Enums\SellerDataMeasure;
use Pushery\Billing\Enums\SellerRecordCompleteness;
use Pushery\Billing\Events\SellerDataMeasureChanged;
use Pushery\Billing\Events\SellerDataReminderDue;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\Models\SellerDataEscalationEpisode;
use Pushery\Billing\ValueObjects\SellerRecordGaps;

/**
 * Moves every seller's escalation over missing data forward, by one step a day at most.
 *
 * The order is fixed: a first reminder, a second one, then a measure. Each step keeps its distance from the
 * step before, counted from when that step actually happened, so a sweep that did not run for a month sends
 * the first reminder and not a measure on the same morning. The seller always gets the time a reminder
 * promised them.
 *
 * A measure needs a field that is required of this seller. When that stops being so (the record was
 * completed, or the seller turned out not to fall under the duty), the measure ends on the same run, the way
 * it began: a suspension made here is lifted, and nothing another reason put in place is touched. A
 * withholding needs no lifting. The shares it held move as soon as the payout gate stops holding them.
 */
final readonly class SellerDataEscalationSweep
{
    /** The reason a suspension made here carries, so ending the measure lifts that suspension and no other. */
    public const string SUSPENSION_REASON = 'seller_data_outstanding';

    public function __construct(
        private ReportingProfile $profile,
        private SellerRecordAssessment $assessment,
        private SellerDataEscalation $escalation,
        private MerchantLifecycle $lifecycle,
        private MarketplaceSaleContext $sale,
        private Dispatcher $events,
    ) {}

    /** Whether this installation escalates at all: the active profile asks for it and a record source is bound. */
    public function applies(): bool
    {
        return $this->profile instanceof EscalatesMissingSellerData && $this->assessment->canAssess();
    }

    /** @return array{assessed: int, reminded: int, measures: int, resolved: int} */
    public function run(CarbonImmutable $now): array
    {
        $outcome = ['assessed' => 0, 'reminded' => 0, 'measures' => 0, 'resolved' => 0];

        if (! $this->applies()) {
            return $outcome;
        }

        $accounts = MerchantAccount::model()::query()
            ->where('status', '!=', MerchantStatus::Terminated->value)
            ->lazyById();

        foreach ($accounts as $account) {
            $merchant = $this->merchantOf($account);
            $gaps = $merchant instanceof Model ? $this->assessment->of($merchant, $now) : null;

            if (! $merchant instanceof Model || ! $gaps instanceof SellerRecordGaps) {
                continue;
            }

            $outcome['assessed']++;
            $step = $this->step($account, $merchant, $gaps, $now);

            if ($step !== null) {
                $outcome[$step]++;
            }
        }

        return $outcome;
    }

    /**
     * What a measure over this installation's money rail can be.
     *
     * A withholding needs a moment at which the package holds the share, and a destination charge has none:
     * the provider moves the money with the payment. Configured there, it would be a measure that never
     * holds anything, so the suspension applies instead, the one measure every rail can carry.
     */
    public function measure(): SellerDataMeasure
    {
        $configured = $this->escalation->measure();

        return $configured === SellerDataMeasure::WithholdPayout && $this->sale->chargeType() === ChargeType::Destination
            ? SellerDataMeasure::SuspendSales
            : $configured;
    }

    /** @return 'reminded'|'measures'|'resolved'|null */
    private function step(MerchantAccount $account, Model $merchant, SellerRecordGaps $gaps, CarbonImmutable $now): ?string
    {
        $episode = SellerDataEscalationEpisode::model()::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->whereNull('resolved_at')
            ->first();

        if ($gaps->complete()) {
            if (! $episode instanceof SellerDataEscalationEpisode) {
                return null;
            }

            $this->endMeasure($episode, $account, $merchant, $now, 'record_completed');
            $episode->forceFill(['stage' => Stage::Clear, 'missing_fields' => [], 'missing_required' => false, 'resolved_at' => $now])->save();

            return 'resolved';
        }

        $episode ??= SellerDataEscalationEpisode::model()::query()->make([
            'merchant_type' => $merchant->getMorphClass(),
            'merchant_id' => $merchant->getKey(),
            'stage' => Stage::Clear,
            'incomplete_since' => $now,
        ]);
        $episode->forceFill(['missing_fields' => $gaps->asked, 'missing_required' => $gaps->missingRequired()])->save();

        $target = $this->escalation->stageFor(
            SellerRecordCompleteness::Incomplete,
            $gaps->missingRequired(),
            $this->daysSince($episode->incomplete_since, $now),
        );

        return match ($episode->stage) {
            Stage::Clear => $this->reached($target, Stage::FirstReminder)
                ? $this->remind($episode, $merchant, Stage::FirstReminder, $gaps, $now)
                : null,
            Stage::FirstReminder => $this->reached($target, Stage::SecondReminder) && $this->spaced($episode->first_reminded_at, Stage::FirstReminder, Stage::SecondReminder, $now)
                ? $this->remind($episode, $merchant, Stage::SecondReminder, $gaps, $now)
                : null,
            Stage::SecondReminder => $target === Stage::MeasureActive && $this->spaced($episode->second_reminded_at, Stage::SecondReminder, Stage::MeasureActive, $now)
                ? $this->applyMeasure($episode, $account, $merchant, $this->measure(), $now)
                : null,
            Stage::MeasureActive => $target === Stage::MeasureActive
                ? $this->convertIfExhausted($episode, $account, $merchant, $now)
                : $this->liftMeasure($episode, $account, $merchant, $now),
        };
    }

    /** @return 'reminded' */
    private function remind(SellerDataEscalationEpisode $episode, Model $merchant, Stage $reminder, SellerRecordGaps $gaps, CarbonImmutable $now): string
    {
        $episode->forceFill([
            'stage' => $reminder,
            $reminder === Stage::FirstReminder ? 'first_reminded_at' : 'second_reminded_at' => $now,
        ])->save();

        $this->events->dispatch(new SellerDataReminderDue(
            merchant: $merchant,
            episodeId: $episode->id,
            reminder: $reminder,
            missingFields: $gaps->asked,
            measureFrom: $gaps->missingRequired() ? $this->measureFrom($episode, $reminder, $now) : null,
            measure: $gaps->missingRequired() ? $this->measure() : null,
        ));

        return 'reminded';
    }

    /**
     * The earliest moment the measure can apply, as the reminder going out now has to promise it.
     *
     * Counted the way the steps are taken: the next step waits its full distance from this one, and never
     * comes before its day counted from when the record became incomplete.
     */
    private function measureFrom(SellerDataEscalationEpisode $episode, Stage $reminder, CarbonImmutable $now): CarbonImmutable
    {
        $since = CarbonImmutable::instance($episode->incomplete_since);
        $second = $this->escalation->daysAfter(Stage::SecondReminder);
        $measure = $this->escalation->daysAfter(Stage::MeasureActive);

        $secondAt = $reminder === Stage::FirstReminder
            ? $this->later($since->addDays($second), $now->addDays($second - $this->escalation->daysAfter(Stage::FirstReminder)))
            : $now;

        return $this->later($since->addDays($measure), $secondAt->addDays($measure - $second));
    }

    /** @return 'measures' */
    private function applyMeasure(SellerDataEscalationEpisode $episode, MerchantAccount $account, Model $merchant, SellerDataMeasure $measure, CarbonImmutable $now): string
    {
        $episode->forceFill(['stage' => Stage::MeasureActive, 'measure' => $measure, 'measure_started_at' => $now])->save();

        if ($measure === SellerDataMeasure::SuspendSales) {
            $this->lifecycle->suspend($account, self::SUSPENSION_REASON);
        }

        $this->events->dispatch(new SellerDataMeasureChanged($merchant, $measure, inForce: true));

        return 'measures';
    }

    /**
     * A measure whose basis went away ends on the run that sees it; the reminders already sent stand.
     *
     * @return 'measures'
     */
    private function liftMeasure(SellerDataEscalationEpisode $episode, MerchantAccount $account, Model $merchant, CarbonImmutable $now): string
    {
        $this->endMeasure($episode, $account, $merchant, $now, 'no_longer_required');
        $episode->forceFill(['stage' => Stage::SecondReminder])->save();

        return 'measures';
    }

    /**
     * A withholding that has run as long as it may becomes a suspension, where the platform chose that.
     *
     * The money it held moves on its own, share by share, when each reaches the limit. What is decided here
     * is only whether the seller stays held to the duty afterwards.
     *
     * @return 'measures'|null
     */
    private function convertIfExhausted(SellerDataEscalationEpisode $episode, MerchantAccount $account, Model $merchant, CarbonImmutable $now): ?string
    {
        if ($episode->measure !== SellerDataMeasure::WithholdPayout
            || ! $episode->measure_started_at instanceof CarbonInterface
            || ! $this->escalation->convertsExhaustedWithholding()
            || ! $this->escalation->withholdingExhausted($this->daysSince($episode->measure_started_at, $now))) {
            return null;
        }

        $this->endMeasure($episode, $account, $merchant, $now, 'withholding_exhausted');

        return $this->applyMeasure($episode, $account, $merchant, SellerDataMeasure::SuspendSales, $now);
    }

    private function endMeasure(SellerDataEscalationEpisode $episode, MerchantAccount $account, Model $merchant, CarbonImmutable $now, string $reason): void
    {
        $measure = $episode->measure;

        if (! $measure instanceof SellerDataMeasure) {
            return;
        }

        // Only the suspension this sweep made. A merchant suspended for another reason stays suspended, and
        // the lifecycle keeps the first reason, so a suspension made elsewhere never carries this one.
        if ($measure === SellerDataMeasure::SuspendSales
            && $account->status === MerchantStatus::Suspended
            && $account->status_reason === self::SUSPENSION_REASON) {
            $this->lifecycle->reinstate($account);
        }

        $episode->endMeasure($now, $reason);
        $episode->save();

        $this->events->dispatch(new SellerDataMeasureChanged($merchant, $measure, inForce: false, reason: $reason));
    }

    private function reached(Stage $target, Stage $stage): bool
    {
        return $this->rank($target) >= $this->rank($stage);
    }

    private function rank(Stage $stage): int
    {
        return match ($stage) {
            Stage::Clear => 0,
            Stage::FirstReminder => 1,
            Stage::SecondReminder => 2,
            Stage::MeasureActive => 3,
        };
    }

    /** Whether the step after `$from` keeps its distance from when `$from` actually happened. */
    private function spaced(?CarbonInterface $fromAt, Stage $from, Stage $to, CarbonImmutable $now): bool
    {
        return ! $fromAt instanceof CarbonInterface
            || $this->daysSince($fromAt, $now) >= $this->escalation->daysAfter($to) - $this->escalation->daysAfter($from);
    }

    private function daysSince(CarbonInterface $since, CarbonImmutable $now): int
    {
        return max(0, (int) $since->diffInDays($now));
    }

    private function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    /**
     * The merchant the account belongs to, or null where that model is gone.
     *
     * Resolved from the morph columns and checked before touching them, the way the transfer paths do it: a
     * stored class that no longer exists is an ordinary answer here and must not stop the run for one row.
     */
    private function merchantOf(MerchantAccount $account): ?Model
    {
        $class = Relation::getMorphedModel($account->merchant_type) ?? $account->merchant_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $merchant = $class::query()->find($account->merchant_id);

        return $merchant instanceof Model ? $merchant : null;
    }
}
