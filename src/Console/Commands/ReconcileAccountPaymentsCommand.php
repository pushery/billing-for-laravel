<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\ListsPaymentAccounts;
use Pushery\Billing\Contracts\PaymentAccounts;
use Pushery\Billing\Drivers\Mollie\MollieClientFactory;
use Pushery\Billing\Drivers\Mollie\MollieMoneyBackOutcomes;
use Pushery\Billing\Events\RefundFailed;
use Pushery\Billing\Support\AccountPaymentReconciler;

/**
 * Picks up the payments of the Mollie payment accounts that changed where Mollie calls no webhook.
 *
 * Mollie calls no webhook for a refund that is canceled before it is paid out, and documents none for a chargeback the
 * bank reverses. The payment then stands somewhere its account's effects never heard of. So the refunds and the
 * chargebacks of each Mollie account are read back with the account's own credential, newest first, as far as their
 * windows reach, and every payment with a refund that failed or was canceled, or a chargeback that was reversed, is
 * recorded where it stands now, as its ping would have been. A standing already recorded is skipped, so a daily run
 * announces each change once.
 *
 * It needs the accounts: the application's PaymentAccounts implements ListsPaymentAccounts. Without that it says so and
 * reads nothing.
 */
final class ReconcileAccountPaymentsCommand extends Command
{
    protected $signature = 'billing:accounts:reconcile
        {--refund-days=30 : How far back to read the refunds of each account, counted from the day each was created}
        {--chargeback-days=180 : How far back to read the chargebacks of each account, counted from the day each was created}';

    protected $description = 'Pick up the payments of the Mollie payment accounts that changed where Mollie calls no webhook';

    public function handle(Container $container, AccountPaymentReconciler $reconciler, PaymentAccounts $accounts): int
    {
        $refundDays = $this->days('refund-days');
        $chargebackDays = $this->days('chargeback-days');

        if ($refundDays === null || $chargebackDays === null) {
            $this->components->error('--refund-days and --chargeback-days each take a whole number of days, from 1 up.');

            return self::INVALID;
        }

        if (! $accounts instanceof ListsPaymentAccounts) {
            $this->components->info('The application\'s PaymentAccounts does not list its accounts (ListsPaymentAccounts), so there is nothing to reconcile.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $clients = $container->make(MollieClientFactory::class);
        $read = 0;
        $recorded = 0;

        foreach ($accounts->all('mollie') as $account) {
            // The list was asked for Mollie's accounts. One of another provider would build a Mollie client with a
            // credential Mollie does not know.
            if ($account->provider !== 'mollie') {
                continue;
            }

            $read++;
            $payments = [];
            $outcomes = new MollieMoneyBackOutcomes($clients->forAccount($account));

            foreach ($outcomes->outcomesSince($now->subDays($refundDays), $now->subDays($chargebackDays)) as $outcome) {
                // The reader yields these two kinds and no other. An outcome that names no payment keys no ping, and
                // the reconciler records nothing for it.
                $payments[$outcome instanceof RefundFailed ? $outcome->paymentReference : $outcome->reference] = true;
            }

            foreach (array_keys($payments) as $payment) {
                foreach ($reconciler->reconcile($account, (string) $payment) as $change) {
                    $recorded++;
                    $this->components->twoColumnDetail($account->key.': '.$change->paymentReference, $change->status->value);
                }
            }
        }

        $this->components->info($recorded === 0
            ? "Nothing new across {$read} Mollie account(s), {$refundDays} days of refunds and {$chargebackDays} days of chargebacks."
            : "{$recorded} change(s) recorded and dispatched across {$read} Mollie account(s).");

        return self::SUCCESS;
    }

    /** A window option as whole days from 1 up, or null for anything else. */
    private function days(string $option): ?int
    {
        $value = $this->option($option);

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int) $value >= 1 ? (int) $value : null;
    }
}
