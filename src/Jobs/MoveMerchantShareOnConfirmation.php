<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Marketplace\BuyerProtectionClock;
use Pushery\Billing\Marketplace\UnmovedMerchantShares;
use Pushery\Billing\Models\BuyerProtectionHold;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\Money;

/**
 * Pays the merchant of a separate-transfer sale whose payment the provider confirmed after the fact.
 *
 * ## The sale this exists for
 *
 * A card that demands 3-D Secure and a bank debit that clears days later both leave `RoutedPayment` with a
 * payment that has not succeeded yet, so it writes the row `pending` and moves nothing. On a destination charge
 * that is the whole story: the provider moves the share as the payment settles. On a separate transfer the
 * platform took the whole payment, and the share moves only if somebody here makes the second call.
 *
 * Nobody did. The confirmation effect settled the row without a transfer, the retry only reads rows that are
 * still pending and have failed, and the merchant was never paid while the row said `settled`.
 *
 * ## Why a job, and why after commit
 *
 * The confirmation arrives as a webhook effect, which runs inside a database transaction. A provider call made
 * there cannot be taken back when the transaction rolls back, so the effect only names the row, and this job
 * makes the call once the transaction has committed. It re-reads the row, because by then a retry or a second
 * delivery may have moved it.
 *
 * ## What it does with the row
 *
 * Exactly what the synchronous path does after a successful payment. Under buyer protection a hold opens and
 * the share stays with the provider. Otherwise the share moves under the sale's own idempotency key, and the row
 * settles with the provider's reference, or records the refusal where `billing:marketplace:retry-transfers`
 * finds it. A share this installation cannot move at all is recorded the same way, so it is counted rather than
 * left as a pending row nothing looks at.
 */
final class MoveMerchantShareOnConfirmation implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    /** How often the transfer is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    public function __construct(public readonly int $chargeId)
    {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(UnmovedMerchantShares $shares, BuyerProtectionClock $protection, Repository $config): void
    {
        $charge = MerchantCharge::query()->find($this->chargeId);

        if (! $charge instanceof MerchantCharge
            || $charge->charge_type !== ChargeType::SeparateTransfer
            || $charge->settlement_state !== SettlementState::Pending
            || $charge->transfer_reference !== null) {
            return;
        }

        if ($config->get('billing.marketplace.buyer_protection.enabled', false) === true) {
            $this->hold($charge, $shares, $protection);

            return;
        }

        if ($shares->move($charge) === 'skipped') {
            $shares->recordUnmovable($charge, 'The payment was confirmed, and this installation cannot move the merchant\'s share: there is no transfer driver, or no account on file for the merchant.');
        }
    }

    /** Open the hold the synchronous path opens, once, however often the confirmation is delivered. */
    private function hold(MerchantCharge $charge, UnmovedMerchantShares $shares, BuyerProtectionClock $protection): void
    {
        if (BuyerProtectionHold::query()->where('charge_reference', $charge->charge_reference)->exists()) {
            return;
        }

        $merchant = $shares->merchantOf($charge);

        $protection->hold(
            $charge->charge_reference,
            Money::of($charge->gross_minor, $charge->currency),
            CarbonImmutable::now(),
            $merchant instanceof Model ? $merchant : null,
        );
    }
}
