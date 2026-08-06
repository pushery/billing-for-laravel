<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\AccountReconciliation;
use Pushery\Billing\ValueObjects\Money;

/**
 * Reconciles the merchant sub-ledger against the collective account, as the exported batch actually states it.
 *
 * ## It reads the emitted file, not the numbers that produced it
 *
 * This is the whole value of the check. Recomputing the collective balance from the same documents the
 * sub-ledger already read would compare a figure with itself: every rule they share cancels out, and the
 * only errors it could ever find are arithmetic ones. Reading the BATCH means the comparison spans the
 * export — so a document the batch leaves out, an account resolved differently, a direction marker written
 * the wrong way round, all surface here as a difference, and none of them would otherwise surface at all
 * until an accountant asked why the account does not tie out.
 *
 * ## A difference is an error state
 *
 * The two sides are one obligation counted twice. There is no reading under which they legitimately differ,
 * so the report states the difference and the caller treats a non-zero as a failure of the close, not as
 * something to note and carry forward.
 */
final readonly class CollectiveAccountReconciler
{
    /** Where the direction marker and the account sit in a booking row (zero-based). */
    private const int AMOUNT_FIELD = 0;

    private const int MARKER_FIELD = 1;

    private const int ACCOUNT_FIELD = 6;

    private const int CONTRA_ACCOUNT_FIELD = 7;

    public function __construct(
        private MerchantPayablesSubLedger $subLedger,
        private MerchantLiabilityAccounts $liabilities,
    ) {}

    /**
     * @param  iterable<InvoiceRecord>  $documents  the same documents the batch was built from
     * @param  string  $batch  the exported batch, read as the books state it
     */
    public function reconcile(iterable $documents, string $batch, CarbonInterface $periodStart, string $currency): AccountReconciliation
    {
        $balances = $this->subLedger->balancesIn($documents, $periodStart, $currency);

        $total = array_reduce(
            $balances,
            static fn (Money $carry, Money $balance): Money => $carry->plus($balance),
            Money::of(0, $currency),
        );

        return new AccountReconciliation(
            $balances,
            $total,
            $this->collectiveBalanceOf($batch, $currency),
        );
    }

    /**
     * What the payables accounts hold according to the batch.
     *
     * Every row touches two accounts, and a payables account may be either of them — a settlement credits it
     * as the contra account, a payout debits it as the account. Reading only one side would count half the
     * movements and produce a difference that is an artifact of the reader.
     *
     * Plural, because an installation may book each merchant to their own account: the sub-ledger total then
     * stands against the sum of all of them, and the same check works unchanged in both arrangements.
     */
    private function collectiveBalanceOf(string $batch, string $currency): Money
    {
        $payables = $this->liabilities->all();
        $balance = Money::of(0, $currency);

        foreach (array_slice(explode("\r\n", trim($batch)), 2) as $line) {
            $fields = explode(';', $line);
            $amount = $this->amountOf($fields[self::AMOUNT_FIELD], $currency);

            // A debit of the account and a credit of the contra account move the same way; the marker flips
            // both. The payable is a credit balance, so a credit of the collective account raises it.
            $credited = trim($fields[self::MARKER_FIELD] ?? '', '"') === 'H';

            if (in_array($fields[self::ACCOUNT_FIELD] ?? '', $payables, true)) {
                $balance = $credited ? $balance->plus($amount) : $balance->minus($amount);
            }

            if (in_array($fields[self::CONTRA_ACCOUNT_FIELD] ?? '', $payables, true)) {
                $balance = $credited ? $balance->minus($amount) : $balance->plus($amount);
            }
        }

        return $balance;
    }

    /** The row's amount, which the format writes unsigned with a comma. */
    private function amountOf(string $field, string $currency): Money
    {
        return Money::of((int) round((float) str_replace(',', '.', $field) * 100), $currency);
    }
}
