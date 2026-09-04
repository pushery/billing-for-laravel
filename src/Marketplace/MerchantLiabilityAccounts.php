<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\DatevAccountResolver;
use Pushery\Billing\Enums\DatevTransaction;
use Pushery\Billing\Exceptions\DatevTransactionUnresolvable;
use Pushery\Billing\Models\MerchantCreditorAccount;

/**
 * Which ledger account a merchant's payable books against.
 *
 * Two arrangements, and the choice is the installation's rather than the package's:
 *
 * **One collective account (the default).** Every merchant books against the same account and the platform
 * keeps the per-merchant detail in its own sub-ledger. The number of merchants is unbounded, so this is the
 * arrangement that stays workable at any size — an accounting firm's master data does not fill with rows
 * nobody there will ever open, and the detail exists in the platform anyway because it has to in order to
 * pay anybody.
 *
 * **An account per merchant.** For the installation with a manageable number of merchants whose accountant
 * expects to see open items per creditor in their own system. The booking logic is identical; only the
 * account the payable side resolves to changes.
 *
 * Switching an install that has already booked is a documented migration, not a flag flip: bookings already
 * made keep pointing at the account they were made against, and the two arrangements disagree about what
 * that account is.
 */
final readonly class MerchantLiabilityAccounts
{
    /** Where an individual creditor range starts when the installation names none. */
    private const int DEFAULT_RANGE_START = 70_000;

    public function __construct(
        private Repository $config,
        private DatevAccountResolver $accounts,
    ) {}

    /** Whether this installation books each merchant to their own account. */
    public function individual(): bool
    {
        return $this->config->get('billing.datev.person_accounts.mode') === 'individual';
    }

    /**
     * The account a merchant's payable books against.
     *
     * In the individual arrangement the number is allocated on first use and kept — a number that moved would
     * leave earlier bookings pointing at an account that now means somebody else.
     */
    public function forMerchant(string $merchantType, int|string $merchantId): string
    {
        if (! $this->individual()) {
            return $this->accounts->resolve(DatevTransaction::CreatorLiabilities)->number;
        }

        return $this->allocate($merchantType, (string) $merchantId);
    }

    /**
     * Every account merchant payables can sit on, for a reconciliation that has to find all of them.
     *
     * @return list<string>
     */
    public function all(): array
    {
        if (! $this->individual()) {
            try {
                return [$this->accounts->resolve(DatevTransaction::CreatorLiabilities)->number];
            } catch (DatevTransactionUnresolvable) {
                // An installation that books no creator liabilities has no payables accounts, and "none" is
                // the honest answer rather than a failure. The resolver refuses because BOOKING to a default
                // account is a silent accounting error — but nothing is being booked here. This reads the
                // accounts back to check a file against them, and refusing would take the single-seller
                // export down over a marketplace setting it has no reason to carry.
                return [];
            }
        }

        /** @var list<string> $numbers */
        $numbers = MerchantCreditorAccount::query()->pluck('number')->all();

        return $numbers;
    }

    /**
     * Get this merchant's number, or take the next free one.
     *
     * Two merchants onboarding at once would otherwise compute the same next number and one of them would
     * silently end up on the other's account. The unique constraint is what actually decides it: the loser of
     * the race gets a violation, ignores it, and tries the next number — so the allocation is settled by the
     * database rather than by who read first.
     */
    private function allocate(string $merchantType, string $merchantId): string
    {
        $existing = MerchantCreditorAccount::query()
            ->where('merchant_type', $merchantType)
            ->where('merchant_id', $merchantId)
            ->value('number');

        if (is_string($existing)) {
            return $existing;
        }

        $start = $this->config->get('billing.datev.person_accounts.range_start');
        $next = (is_int($start) ? $start : self::DEFAULT_RANGE_START) + MerchantCreditorAccount::query()->count();

        while (true) {
            MerchantCreditorAccount::query()->insertOrIgnore([
                'merchant_type' => $merchantType,
                'merchant_id' => $merchantId,
                'number' => (string) $next,
            ]);

            $number = MerchantCreditorAccount::query()
                ->where('merchant_type', $merchantType)
                ->where('merchant_id', $merchantId)
                ->value('number');

            if (is_string($number)) {
                return $number;
            }

            $next++;
        }
    }
}
