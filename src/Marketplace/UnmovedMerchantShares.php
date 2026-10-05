<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Support\OwnerOfRecord;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\TransferResult;
use RuntimeException;
use Throwable;

/**
 * The paid sales whose merchant share failed to move, and the one way to move them.
 *
 * ## Why the condition lives here once
 *
 * `billing:doctor` counts these rows and `billing:marketplace:retry-transfers` moves them. Two copies of the
 * condition would drift apart while both looked right, so both ask this class.
 *
 * ## Why the retry uses the sale's idempotency key
 *
 * A transfer whose answer was lost may well have happened, and a new key would pay the merchant twice. The
 * price is that a provider which keeps the result of a failed request replays that failure for the same key
 * until it forgets it, Stripe after 24 hours at the earliest, so a retry inside that window can fail again for
 * a cause that is already fixed.
 *
 * ## Where the destination comes from
 *
 * The merchant's account as the directory knows it now, which is how `BuyerProtectionClock` resolves it for a
 * release. The row does not keep the account the sale was routed to, and a merchant who moved their account
 * since the sale is paid where they are paid today.
 */
final readonly class UnmovedMerchantShares
{
    /**
     * How long a requested transfer may go unanswered before it counts as abandoned.
     *
     * A provider answers a transfer in seconds, so a quarter of an hour is far beyond any run still waiting,
     * and short enough that the doctor run after a killed release already names the sale.
     */
    private const int ANSWER_WAIT_MINUTES = 15;

    public function __construct(
        private RoutedChargeLedger $ledger,
        /** Null where the driver cannot move a share at all, and then nothing here can move one either. */
        private ?MovesMerchantShare $transfers = null,
        /** Null where no directory is bound, and then there is nobody to name as the destination. */
        private ?MerchantAccountDirectory $accounts = null,
        /** Null where nothing is asked before a share moves, which is how a hand-built instance behaves. */
        private ?MerchantPayoutGate $payouts = null,
        /** Null where no release waits on a share here, which is how a hand-built instance behaves. */
        private ?BuyerProtectionClock $protection = null,
    ) {}

    public function count(): int
    {
        return $this->unmoved()->count();
    }

    /**
     * Try each unmoved share once.
     *
     * A share that fails again is recorded again, so the row says when it last failed rather than when it
     * first did. A share that cannot be tried at all, for want of a driver, a directory, a merchant or an
     * account, is skipped and stays exactly as it was.
     *
     * @return array{moved: int, failed: int, skipped: int}
     */
    public function retry(): array
    {
        $outcome = ['moved' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->unmoved()->lazyById() as $charge) {
            $outcome[$this->move($charge)]++;
        }

        return $outcome;
    }

    /**
     * Move one sale's share under its own key, and write down what happened.
     *
     * The retry walks the failed rows through here, and so does a separate-transfer sale whose payment the
     * provider confirmed after the fact: both owe the same transfer and have to leave the same row behind. A
     * moved share settles the row with the provider's reference and figure; a refused one records the failure
     * where the retry finds it; a share that cannot be tried at all is left exactly as it was.
     *
     * @return 'moved'|'failed'|'skipped'
     */
    public function move(MerchantCharge $charge): string
    {
        $merchant = $this->merchantOf($charge);

        if (! $merchant instanceof Model) {
            return 'skipped';
        }

        $destination = $this->accounts?->accountFor($merchant);

        if (! $this->transfers instanceof MovesMerchantShare || ! $destination instanceof MerchantAccountReference) {
            return 'skipped';
        }

        // Asked here rather than by each caller, because both callers owe the same answer: a retry must not
        // move a share whose merchant's payouts are withheld, and neither may a late confirmation. The row
        // says why it waits, and it moves on the run after the reason ends.
        $withheld = $this->payouts?->withheldBecause(
            $merchant,
            $charge->created_at instanceof DateTimeInterface ? CarbonImmutable::instance($charge->created_at) : CarbonImmutable::now(),
        );

        if ($withheld !== null) {
            $this->ledger->recordWithholding($charge, $withheld);

            return 'skipped';
        }

        // An earlier attempt may have reached the provider and lost its answer. Its transfer is taken where the
        // provider still has it, rather than made a second time under a key the provider may have dropped.
        try {
            $earlier = $this->ledger->earlierTransfer($charge, $this->transfers, $destination);
        } catch (Throwable $failure) {
            $this->ledger->recordTransferFailure($charge, $merchant, $failure);

            return 'failed';
        }

        if ($earlier instanceof TransferResult) {
            if ($this->ledger->settle($charge, $earlier->reference, $earlier->moved)) {
                $this->protection?->shareMoved($charge->charge_reference);
            }

            return 'moved';
        }

        // Before the provider is asked, for the reason the release gives: a run that stops before the answer leaves
        // only this behind.
        $this->ledger->recordTransferRequested($charge);

        try {
            $moved = $this->transfers->transferShare($destination, $charge->net(), $charge->charge_reference, $charge->transferIdempotencyKey());
        } catch (Throwable $failure) {
            $this->ledger->recordTransferFailure($charge, $merchant, $failure);

            return 'failed';
        }

        // A release that stopped at this transfer left its hold waiting. The sale is settled now, and only the run
        // that settled it finishes the hold, so a retry racing another announces the release once.
        if ($this->ledger->settle($charge, $moved->reference, $moved->moved)) {
            $this->protection?->shareMoved($charge->charge_reference);
        }

        return 'moved';
    }

    /**
     * Record a paid sale whose share this installation cannot move at all, so it is counted and retried.
     *
     * Only for a row that has not failed before, and only where the merchant still exists: the failure is written
     * against the merchant, and a row that already carries a failure is already where the doctor and the retry
     * look.
     */
    public function recordUnmovable(MerchantCharge $charge, string $why): void
    {
        $merchant = $this->merchantOf($charge);

        if ($merchant instanceof Model && $charge->transfer_failed_at === null) {
            $this->ledger->recordTransferFailure($charge, $merchant, new RuntimeException($why));
        }
    }

    /**
     * The pending sales whose share failed to move, or was asked of the provider so long ago that the run asking
     * cannot still be waiting for the answer.
     *
     * The second kind is a run that stopped between asking and hearing back. Whether the transfer arrived is not
     * known, which is the case of a lost answer the retry is built for: it asks again under the sale's key.
     *
     * @return Builder<MerchantCharge>
     */
    private function unmoved(): Builder
    {
        // In UTC, like the column: the builder binds a moment in its own zone without converting it.
        $abandoned = CarbonImmutable::now()->utc()->subMinutes(self::ANSWER_WAIT_MINUTES);

        return MerchantCharge::model()::query()
            ->where('settlement_state', SettlementState::Pending->value)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNotNull('transfer_failed_at')
                ->orWhere('transfer_requested_at', '<=', $abandoned));
    }

    /**
     * The merchant the row names, or null where that model is gone.
     *
     * Resolved from the morph columns and checked before touching them, the way `BuyerProtectionClock` does it:
     * a stored class that no longer exists is an ordinary answer here and must not stop the run for one row. A
     * merchant the application has soft-deleted or scoped away since is still found, because the share is owed for
     * a sale that already happened.
     */
    public function merchantOf(MerchantCharge $charge): ?Model
    {
        return OwnerOfRecord::find($charge->merchant_type, $charge->merchant_id);
    }
}
