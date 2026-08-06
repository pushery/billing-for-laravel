<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pushery\Billing\Models\MerchantBalance;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a merchant owes the platform, and how the next settlement pays it down.
 *
 * A clawback can fail. The merchant's provider balance may hold nothing to take back, and without somewhere
 * for the shortfall to sit that is a loss nobody sees: the reversal simply did not happen and no row says
 * so. So a shortfall is charged here, the balance goes negative, and the next settlement is applied against
 * it before anything leaves.
 *
 * ## Offsetting is a PAYMENT event, never a reduction of consideration
 *
 * This is the distinction the whole class turns on. Withholding a settlement to pay down a debt changes what
 * the merchant RECEIVES; it does not change what they EARNED. The settlement document still states its full
 * amount, the tax base is untouched, and no correcting document is due — the money simply went to the debt
 * instead of to the bank. Treating an offset as a reduction would quietly turn a collection into a tax
 * correction, understating the platform's own turnover by the same amount, and the documents would still all
 * look right.
 *
 * Which is also why the offset works on the settlement's GROSS: the clawback that created the debt took the
 * gross, tax paid through included, so paying it back with a net would leave the tax half of it outstanding
 * forever, shrinking by a fraction of itself on every settlement.
 *
 * ## Deliberately not the buyer credit balance
 *
 * That one is a claim on future invoices held by somebody buying from the platform; this is a debt owed by
 * somebody selling through it. Sharing a table would put a payable and a receivable in one column, and make
 * one person's prepayment offsettable against another's clawback.
 *
 * Concurrency follows the buyer ledger exactly — insert-or-ignore the row, then a row lock for the read and
 * the write — because two settlements landing at once must not both see the same debt and both pay it down.
 */
final readonly class MerchantSubLedger
{
    /** How long a debt may sit untouched before it is a claim to pursue rather than one to wait out. */
    private const int DEFAULT_CLAIM_AFTER_DAYS = 90;

    public function __construct(private ?Repository $config = null) {}

    /** What the merchant owes, as a signed amount. Negative is a debt; zero is the ordinary state. */
    public function balanceFor(Model $merchant, string $currency): Money
    {
        $balance = MerchantBalance::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->where('currency', $currency)
            ->value('balance_minor');

        return Money::of(is_int($balance) ? $balance : 0, $currency);
    }

    /** Whether this merchant is in debt in this currency. */
    public function inDebt(Model $merchant, string $currency): bool
    {
        return $this->balanceFor($merchant, $currency)->isNegative();
    }

    /**
     * Since when — null when nothing is owed.
     *
     * Kept as its own column rather than read from the row's last update, because that answers the opposite
     * question: a debt paid down twice looks NEWER than one nobody has touched, and it is the untouched one
     * that is the receivable.
     */
    public function inDebtSince(Model $merchant, string $currency): ?Carbon
    {
        return MerchantBalance::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->where('currency', $currency)
            ->first()?->in_debt_since;
    }

    /**
     * Every merchant in debt, oldest debt first.
     *
     * A merchant who owes money and never sells again is a receivable, not a balance waiting to be netted
     * off — and one nobody can list is one nobody pursues. Erased rows are excluded: they name no merchant to
     * pursue, and the debt on them is history rather than a claim.
     *
     * @return list<MerchantBalance>
     */
    public function debtors(?string $currency = null): array
    {
        return array_values(
            MerchantBalance::query()
                ->where('balance_minor', '<', 0)
                ->whereNotNull('merchant_id')
                ->when($currency !== null, fn (Builder $query): Builder => $query->where('currency', $currency))
                ->orderBy('in_debt_since')
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    /**
     * The debts old enough to pursue rather than wait out.
     *
     * The threshold is configuration, never a constant in a branch: how long a platform waits before calling
     * a debt a claim is a commercial decision that differs per operator, and one baked into code is one that
     * silently applies somebody else's terms.
     *
     * @return list<MerchantBalance>
     */
    public function claimable(Carbon $now, ?string $currency = null): array
    {
        $cutoff = $now->copy()->subDays($this->claimAfterDays());

        return array_values(array_filter(
            $this->debtors($currency),
            fn (MerchantBalance $balance): bool => $balance->in_debt_since instanceof Carbon
                && $balance->in_debt_since->lessThanOrEqualTo($cutoff),
        ));
    }

    /**
     * Charge a shortfall the platform could not reclaim from the provider.
     *
     * Takes a positive amount and drives the balance DOWN, so a call site cannot pay a debt off by passing a
     * negative number to the method named for owing one.
     */
    public function chargeShortfall(Model $merchant, Money $amount): Money
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException(
                'A shortfall is a positive amount that the merchant comes to owe. Settling a debt is what '
                .'applySettlement() is for; passing a negative here would pay one off through the method '
                .'named for creating one.'
            );
        }

        return $this->apply($merchant, $amount->negated());
    }

    /**
     * Apply a settlement against any outstanding debt, and report what is actually payable.
     *
     * The gross of the settlement, because the clawback that created the debt took the gross. Paying it back
     * with a net would leave the tax portion outstanding forever, shrinking by a fraction of itself on every
     * settlement — a debt that never quite closes and that nobody can explain.
     *
     * @return array{Money, Money} [payable, balanceAfter]
     */
    public function applySettlement(Model $merchant, Money $gross): array
    {
        if ($gross->isNegative()) {
            throw new InvalidArgumentException('A settlement cannot be negative.');
        }

        return DB::transaction(function () use ($merchant, $gross): array {
            $before = $this->lockedBalance($merchant, $gross->currency);

            // Nothing owed: the settlement passes through untouched and no row is written. An offset of zero
            // is not an offset, and writing one would put a payment event in the record that never happened.
            //
            // Offsetting can also be switched off, which is a commercial choice rather than a technical one:
            // an operator may prefer to pay a merchant in full and pursue the debt separately. The debt then
            // stands and shows up as a claim, which is the honest outcome — it does not quietly disappear.
            if (! $before->isNegative() || ! $this->offsetsAgainstPayouts()) {
                return [$gross, $before];
            }

            $owed = $before->absolute();
            $applied = $gross->greaterThan($owed) ? $owed : $gross;

            $after = $this->write($merchant, $before->plus($applied));

            return [$gross->minus($applied), $after];
        });
    }

    /** Adjust the balance by a signed amount under a row lock. */
    private function apply(Model $merchant, Money $amount): Money
    {
        return DB::transaction(function () use ($merchant, $amount): Money {
            $balance = $this->lockedBalance($merchant, $amount->currency);

            return $this->write($merchant, $balance->plus($amount));
        });
    }

    /**
     * The current balance, with the row created if it does not exist and locked for the caller's write.
     *
     * Insert-or-ignore rather than a check-then-insert, because two settlements arriving together would both
     * find no row and both try to create one; the loser of that race would fail on the unique key rather
     * than take the lock it came for.
     */
    private function lockedBalance(Model $merchant, string $currency): Money
    {
        MerchantBalance::query()->insertOrIgnore([
            'merchant_type' => $merchant->getMorphClass(),
            'merchant_id' => $merchant->getKey(),
            'currency' => $currency,
            'balance_minor' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $row = MerchantBalance::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->where('currency', $currency)
            ->lockForUpdate()
            ->firstOrFail();

        return Money::of($row->balance_minor, $currency);
    }

    /**
     * Write the balance, and start or stop the clock the receivable is aged by.
     *
     * The clock starts when the balance crosses INTO debt and is cleared the moment it leaves — so a merchant
     * who runs up a second debt starts a second clock rather than inheriting the age of the first.
     */
    private function write(Model $merchant, Money $balance): Money
    {
        $row = MerchantBalance::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->where('currency', $balance->currency);

        $since = $balance->isNegative()
            ? ($this->inDebtSince($merchant, $balance->currency) ?? Carbon::now())
            : null;

        // No explicit updated_at: Eloquent's builder stamps it for a model that keeps timestamps, and naming
        // it here would be a second place for the two to disagree.
        $row->update(['balance_minor' => $balance->minorUnits, 'in_debt_since' => $since]);

        return $balance;
    }

    private function offsetsAgainstPayouts(): bool
    {
        $configured = $this->config?->get('billing.marketplace.negative_balance.offset_against_payouts');

        return ! is_bool($configured) || $configured;
    }

    private function claimAfterDays(): int
    {
        $configured = $this->config?->get('billing.marketplace.negative_balance.claim_after_days');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_CLAIM_AFTER_DAYS;
    }
}
