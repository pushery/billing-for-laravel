<?php

declare(strict_types=1);

namespace Pushery\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Contracts\NamesPaymentTransfer;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\MerchantCharge;

/**
 * Writes onto a destination charge the transfer its share went out on, when the confirmation could not name it.
 *
 * ## Why it is needed
 *
 * A destination charge confirmed later, a hosted checkout or a card that asked for 3-D Secure, is settled from a
 * `payment_intent.succeeded` delivery. That payload is the PaymentIntent, and the transfer is a field of the
 * payment's charge, not of the intent. The mapper read `transfer` off the intent and always found nothing, so these
 * rows settled with no transfer reference: the join a reconciliation against the provider makes, and the reference
 * a lost dispute reverses.
 *
 * ## Why a job
 *
 * The answer takes a provider call, and the confirmation runs inside the webhook's transaction. The effect names the
 * row, and this job asks once that transaction has committed. It re-reads the row and does nothing when a
 * reference is already there.
 */
final class NameTransferOnConfirmation implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    /** How often the lookup is retried before the job is marked failed. The webhook setting, shared. */
    public int $tries;

    public function __construct(public readonly int $chargeId)
    {
        $config = Config::array('billing.webhooks', []);

        $this->tries = is_int($config['tries'] ?? null) ? $config['tries'] : 5;
        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(Container $container, RoutedChargeLedger $ledger): void
    {
        $charge = MerchantCharge::model()::query()->find($this->chargeId);

        if (! $charge instanceof MerchantCharge || $charge->transfer_reference !== null) {
            return;
        }

        $driver = $container->bound(MovesMerchantShare::class) ? $container->make(MovesMerchantShare::class) : null;

        if (! $driver instanceof NamesPaymentTransfer) {
            return;
        }

        $transfer = $driver->transferOfPayment($charge->charge_reference);

        if ($transfer !== null) {
            $ledger->nameTransfer($charge, $transfer);
        }
    }
}
