<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\Money;

/**
 * Grants a customer a reward for bringing in another customer, as credit on their balance.
 *
 * The application decides when a referral has earned its reward and how much it is, and calls grant() with
 * a record of this package that stands for the referral, usually the subscription of the customer who was
 * brought in. The reward lands on the referrer's balance with the reason
 * {@see CreditReason::ReferralReward}, and the next cycles spend it like any other credit: after the
 * invoice, which keeps its full price and tax, and never paid out.
 *
 * In tax terms the reward pays for the referral, a service the referrer renders the seller. It does not
 * reduce the price of the seller's supplies (Abschn. 10.3 Abs. 2 UStAE), so it is not a coupon. Whether the
 * referrer owes tax on that service, and whether the seller may deduct it, depends on the referrer and on
 * the invoice the referrer or the seller issues for it, which is the application's to arrange.
 */
final readonly class ReferralRewards
{
    public function __construct(private CreditLedger $ledger) {}

    /**
     * Credit the referrer once for the referral, and answer the new balance, or null when this referral was
     * already rewarded.
     *
     * The cause of every ledger movement is a row of this package, kept in an unsigned integer column, so the
     * referral is named by one as well: the subscription of the customer who was brought in is the one every
     * paying referral has. A record of the application, whose key may be a UUID, is refused here, before its
     * key reaches the column.
     *
     * @throws InvalidArgumentException when the referral's key is not an unsigned integer, or the amount is not positive
     */
    public function grant(Model $referrer, Money $amount, CreditSource $referral): ?Money
    {
        if (! ctype_digit((string) $referral->id)) {
            throw new InvalidArgumentException('A referral is named by a record of this package, such as the subscription of the customer who was brought in.');
        }

        return $this->ledger->creditOnce($referrer, $amount, CreditReason::ReferralReward, $referral);
    }
}
