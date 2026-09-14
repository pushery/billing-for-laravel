<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\Money;

/**
 * How much money has gone into vouchers over a rolling window, and whether that figure has grown into
 * something somebody has to act on.
 *
 * It decides nothing. Crossing a supervisory threshold is a fact about a number; what follows from it is a
 * filing a person makes, and a package that tried to make it would be wrong about which jurisdiction, which
 * authority and which form. What a package CAN do is produce a defensible figure and say when it has been
 * passed — because the alternative is finding out at an audit that the threshold was crossed eleven months
 * ago and nobody was counting.
 *
 * The warning level exists for the same reason: a threshold you learn about on the day you cross it leaves
 * no time to do anything about it.
 */
final readonly class VoucherVolumeMonitor
{
    public function __construct(
        private Repository $config,
        private VoucherLedger $vouchers,
        private CreditTopUpVolume $credits,
    ) {}

    /**
     * What has gone into prepaid value over the window ending now — BOTH instruments.
     *
     * A voucher and a paid credit top-up are the same thing in two tables: prepaid value, held by the
     * issuer, redeemed later. The threshold asks for the total value of the payment transactions over the
     * window, not for which table this package keeps them in — so an installation that sells credit and
     * issues vouchers only incidentally used to cross the line while this counted calmly on.
     *
     * The two are added rather than reconciled: they are different rows about different sales, and nothing
     * can appear in both.
     */
    public function volume(CarbonInterface $now, string $currency): Money
    {
        $since = $now->copy()->subMonthsNoOverflow($this->windowMonths());

        return Money::of(
            $this->vouchers->issuedVolumeSince($since, $currency)->minorUnits
            + $this->credits->since($since, $currency)->minorUnits,
            $currency,
        );
    }

    /** Past the figure at which a filing is expected. */
    public function breached(CarbonInterface $now, string $currency): bool
    {
        return $this->volume($now, $currency)->minorUnits >= $this->threshold();
    }

    /** Close enough that there is still time to do something about it. */
    public function approaching(CarbonInterface $now, string $currency): bool
    {
        $volume = $this->volume($now, $currency)->minorUnits;

        return ! $this->breached($now, $currency)
            && $volume >= intdiv($this->threshold() * $this->warnAtPercent(), 100);
    }

    private function windowMonths(): int
    {
        $months = $this->config->get('billing.marketplace.vouchers.volume_window_months');

        return is_int($months) && $months > 0 ? $months : 12;
    }

    /**
     * The figure at which a filing is expected, in minor units.
     *
     * Public because an announcement has to carry it: a recipient told "you are close" without being told
     * close to WHAT has to go and look it up in configuration to phrase a single sentence, and the sweep
     * would otherwise re-read the same key this class already reads.
     */
    public function thresholdMinor(): int
    {
        return $this->threshold();
    }

    private function threshold(): int
    {
        $threshold = $this->config->get('billing.marketplace.vouchers.volume_threshold_minor');

        return is_int($threshold) && $threshold > 0 ? $threshold : 100_000_000;
    }

    private function warnAtPercent(): int
    {
        $percent = $this->config->get('billing.marketplace.vouchers.volume_warn_at_percent');

        return is_int($percent) && $percent > 0 && $percent < 100 ? $percent : 80;
    }
}
