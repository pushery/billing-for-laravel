<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Consumer\GermanWithdrawalPeriod;
use Pushery\Billing\Consumer\GermanWithdrawalPolicy;
use Pushery\Billing\Contracts\ConsumerWithdrawalPolicy;

/**
 * Extends the withdrawal windows the German reading froze before it counted whole days.
 *
 * The German reading used to end a window fourteen days after provision at the time of day the work was provided,
 * so a withdrawal on the evening of the last day was refused as late, and a last day on a weekend or a public holiday
 * did not move to the next working day. A window it froze that way lies exactly fourteen days after the grant's
 * `acquired_at`, and only such a window is rewritten, to the end {@see GermanWithdrawalPeriod} states for it. A
 * window another reading wrote, or one set by hand, stays as it is, and so does every window while a reading other
 * than the German one is bound.
 *
 * Nothing is shortened. Fourteen days counted across a change to summer time can end later on the German clock than
 * the German calendar day does, and then the old end stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->boundReading() instanceof GermanWithdrawalPolicy) {
            return;
        }

        DB::table('billing_access_grants')
            ->whereNotNull('withdrawal_window_ends_at')
            ->chunkById(500, $this->extend(...));
    }

    public function down(): void
    {
        // The windows stay extended: each was the statutory window of its sale all along, and the shorter end
        // refused buyers who were in time.
    }

    /** The reading bound now, as the contract it is bound under: a host may bind its own in place of the German one. */
    private function boundReading(): ConsumerWithdrawalPolicy
    {
        return Container::getInstance()->make(ConsumerWithdrawalPolicy::class);
    }

    /** @param  Collection<int, stdClass>  $grants */
    private function extend(Collection $grants): void
    {
        foreach ($grants as $grant) {
            $provided = is_string($grant->acquired_at) ? CarbonImmutable::parse($grant->acquired_at) : null;
            $frozen = is_string($grant->withdrawal_window_ends_at) ? CarbonImmutable::parse($grant->withdrawal_window_ends_at) : null;

            if (! $provided instanceof CarbonImmutable || ! $frozen instanceof CarbonImmutable || ! $frozen->equalTo($provided->addDays(14))) {
                continue;
            }

            $ends = GermanWithdrawalPeriod::endsAfter($provided);

            if ($ends->greaterThan($frozen)) {
                DB::table('billing_access_grants')->where('id', $grant->id)->update(['withdrawal_window_ends_at' => $ends]);
            }
        }
    }
};
