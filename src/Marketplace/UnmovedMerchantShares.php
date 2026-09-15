<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
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
 * until it forgets it, Stripe for 24 hours, so a retry inside that window can fail again for a cause that is
 * already fixed.
 *
 * ## Where the destination comes from
 *
 * The merchant's account as the directory knows it now, which is how `BuyerProtectionClock` resolves it for a
 * release. The row does not keep the account the sale was routed to, and a merchant who moved their account
 * since the sale is paid where they are paid today.
 */
final readonly class UnmovedMerchantShares
{
    public function __construct(
        private RoutedChargeLedger $ledger,
        /** Null where the driver cannot move a share at all, and then nothing here can move one either. */
        private ?MovesMerchantShare $transfers = null,
        /** Null where no directory is bound, and then there is nobody to name as the destination. */
        private ?MerchantAccountDirectory $accounts = null,
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

        try {
            $moved = $this->transfers->transferShare($destination, $charge->net(), $charge->charge_reference, $charge->transferIdempotencyKey());
        } catch (Throwable $failure) {
            $this->ledger->recordTransferFailure($charge, $merchant, $failure);

            return 'failed';
        }

        $this->ledger->settle($charge, $moved->reference, $moved->moved);

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

    /** @return Builder<MerchantCharge> */
    private function unmoved(): Builder
    {
        return MerchantCharge::query()
            ->where('settlement_state', SettlementState::Pending->value)
            ->whereNotNull('transfer_failed_at');
    }

    /**
     * The merchant the row names, or null where that model is gone.
     *
     * Resolved from the morph columns and checked before touching them, the way `BuyerProtectionClock` does it:
     * a stored class that no longer exists is an ordinary answer here and must not stop the run for one row.
     */
    public function merchantOf(MerchantCharge $charge): ?Model
    {
        $class = Relation::getMorphedModel($charge->merchant_type) ?? $charge->merchant_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($charge->merchant_id);
    }
}
