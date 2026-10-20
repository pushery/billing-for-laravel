<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\RefundsPlatformFee;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * Brings a destination sale's commission down to the net, after the payment withheld it on the gross.
 *
 * ## Which sales need it
 *
 * The configured rate is a net rate. Two hosted payments can only state the fee before the buyer's tax is known: the
 * first cycle of a destination subscription, paid as its checkout completes, takes its percentage of the invoice total,
 * and a one-off purchase at a tax-inclusive price takes its fee on the price. A renewal whose draft could not be set
 * in time ends up the same way. Each of them withheld the commission on the tax as well, 11.90 on 119.00 at 19% with a
 * 10% rate where the commission is 10.00.
 *
 * ## What it does
 *
 * Recomputes the fee from the terms frozen on the row on the net the buyer's rate separates, gives the difference back
 * at the provider as a part of the fee, and then writes the fee, the merchant's share and the rate onto the row
 * together. The provider goes first: a row that said 10.00 while the provider still held 11.90 would be a drift, and
 * the give-back is found again under its key when a retry follows a failure in between.
 *
 * Nothing happens to a row that was refunded or reversed before this ran, to one already taken on the net, or where
 * the provider gives back less than the difference. A row left as it was still says what moved, with its zero.
 */
final class TakeCommissionOnNet implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use InteractsWithQueue;
    use Queueable;

    /** How often the correction is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    public function __construct(
        public readonly int $chargeId,
        /** The one tax rate the buyer paid, in basis points, as the provider stated it. */
        public readonly int $taxBps,
    ) {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    /** The key a correction gives the fee back under, one per sale, so a retry finds it rather than repeating it. */
    public static function keyFor(int $chargeId): string
    {
        return 'billing_commission_on_net_'.$chargeId;
    }

    public function handle(BillingManager $manager, RoutedChargeLedger $ledger): void
    {
        $charge = MerchantCharge::model()::query()->find($this->chargeId);

        if (! $charge instanceof MerchantCharge || $charge->charge_type !== ChargeType::Destination || $this->taxBps <= 0) {
            return;
        }

        if (($charge->commission_tax_bps ?? 0) !== 0 || $charge->refunded_minor > 0 || $charge->fee_refunded_minor > 0 || $charge->transfer_reversed_minor > 0) {
            return;
        }

        $terms = $charge->frozenFee();

        if (! $terms instanceof PlatformFee) {
            return;
        }

        $fee = $terms->of($charge->gross()->baseFromMarkup($this->taxBps)[0]);
        $difference = $charge->fee_minor - $fee->minorUnits;

        if ($difference <= 0) {
            return;
        }

        $rails = $manager->driver($charge->provider)->rails();

        if (! $rails instanceof RefundsPlatformFee) {
            return;
        }

        $given = $rails->refundPlatformFee($charge->charge_reference, new Money($difference, $charge->currency), self::keyFor((int) $charge->id));

        if ($given?->minorUnits !== $difference) {
            return;
        }

        $ledger->takeCommissionOnNet($charge, $fee, $this->taxBps);
    }
}
