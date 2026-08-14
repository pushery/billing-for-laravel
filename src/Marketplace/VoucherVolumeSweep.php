<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Pushery\Billing\Enums\VoucherVolumeLevel;
use Pushery\Billing\Events\VoucherVolumeThresholdApproaching;
use Pushery\Billing\Events\VoucherVolumeThresholdBreached;
use Pushery\Billing\Models\Voucher;
use Pushery\Billing\Models\VoucherVolumeNotice;
use Pushery\Billing\ValueObjects\Money;

/**
 * Announces the voucher-volume levels, once each, before an operator learns them from a letter.
 *
 * ## What the monitor could not do on its own
 *
 * {@see VoucherVolumeMonitor} computed the rolling figure correctly and was called by no line of production
 * code — its only occurrence in `src/` was its own declaration. So the supervisory threshold was passed in
 * silence: the package knew, and the person who has to file did not.
 *
 * A monitor answers when asked. Nobody was asking, and nothing told them to.
 *
 * ## The currencies come from the vouchers, not from a list
 *
 * There is no configured currency list here, and adding one would be the wrong shape: it would be a second
 * hand-kept list beside the vouchers themselves, and the failure of such a list is silent — a currency
 * somebody sells in but forgot to add is a currency nobody is counting, and it looks exactly like a currency
 * under the threshold. Asking the ledger which currencies actually carry vouchers cannot drift.
 *
 * ## Once per level, per currency, per year
 *
 * The command runs daily. Without a marker it would repeat the same warning every morning, and a channel
 * that repeats is one nobody reads on the day it finally means something. Not once ever either: the window
 * is rolling, so a figure can fall back and cross again years later under a genuinely new obligation, which
 * a permanent marker would swallow. {@see VoucherVolumeNotice} carries the reasoning for the year.
 *
 * ## It announces; nothing here files
 *
 * This package notifies no authority and holds credentials for none. Being told in time is the whole of
 * what it can offer.
 */
final readonly class VoucherVolumeSweep
{
    public function __construct(
        private Dispatcher $events,
        private VoucherVolumeMonitor $monitor,
    ) {}

    /**
     * Announce every level reached that has not been announced this year.
     *
     * @return int how many announcements were made
     */
    public function announce(CarbonImmutable $now): int
    {
        $announced = 0;

        foreach ($this->currencies() as $currency) {
            $level = $this->levelFor($now, $currency);
            if (! $level instanceof VoucherVolumeLevel) {
                continue;
            }
            if ($this->alreadyAnnounced($currency, $level, $now->year)) {
                continue;
            }

            $volume = $this->monitor->volume($now, $currency);
            $threshold = Money::of($this->monitor->thresholdMinor(), $currency);

            $this->events->dispatch($level === VoucherVolumeLevel::Breached
                ? new VoucherVolumeThresholdBreached($volume, $threshold, $now)
                : new VoucherVolumeThresholdApproaching($volume, $threshold, $now));

            // Marked after dispatching. A crash between the two announces once more, which somebody can live
            // with; the other order loses the announcement, which is what this class exists to prevent.
            VoucherVolumeNotice::query()->create([
                'currency' => $currency,
                'level' => $level,
                'announced_for_year' => $now->year,
                'volume_minor' => $volume->minorUnits,
                'announced_at' => $now,
            ]);

            $announced++;
        }

        return $announced;
    }

    /**
     * The higher of the two levels this currency has reached, or null for neither.
     *
     * Breached wins outright rather than both firing: a recipient told "you are close" in the same breath as
     * "you are past it" learns nothing from the first, and the approaching marker for the year is left
     * unset on purpose — an operator whose volume later falls back into the warning band has already had the
     * message that matters more.
     */
    private function levelFor(CarbonImmutable $now, string $currency): ?VoucherVolumeLevel
    {
        if ($this->monitor->breached($now, $currency)) {
            return VoucherVolumeLevel::Breached;
        }

        return $this->monitor->approaching($now, $currency) ? VoucherVolumeLevel::Approaching : null;
    }

    private function alreadyAnnounced(string $currency, VoucherVolumeLevel $level, int $year): bool
    {
        return VoucherVolumeNotice::query()
            ->where('currency', $currency)
            ->where('level', $level->value)
            ->where('announced_for_year', $year)
            ->exists();
    }

    /**
     * Every currency vouchers have actually been issued in.
     *
     * @return list<string>
     */
    private function currencies(): array
    {
        return array_values(array_filter(
            Voucher::query()->distinct()->pluck('currency')->all(),
            is_string(...),
        ));
    }
}
