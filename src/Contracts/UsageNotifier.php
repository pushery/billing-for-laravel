<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Warns the owner that a metered allowance is running out — BEFORE the meter's policy acts on it. Without
 * this the first thing a customer learns about their limit is being refused by it; the gauge only helps
 * someone who happens to be looking at the account screen.
 *
 * The once-per-meter-per-period guarantee lives in the caller (UsageRecorder claims the counter row's
 * warned_at in a single conditional update), so an implementation simply delivers.
 */
interface UsageNotifier
{
    /**
     * @param  string  $meterKey  the meter running out (e.g. "emails")
     * @param  string  $label  its human label, already resolved (e.g. "Emails sent")
     * @param  int  $used  units used so far this period
     * @param  int  $included  the included allowance this is measured against
     */
    public function quotaWarning(Model $owner, string $meterKey, string $label, int $used, int $included): void;
}
