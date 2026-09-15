<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * What has gone into paid money credit over a window — the half of the voucher figure that is not a voucher.
 *
 * ## Why this belongs in the same number as a voucher
 *
 * A voucher and a paid credit top-up are the same instrument wearing two table names: prepaid value, held by
 * the issuer, redeemed later against something the issuer supplies. A supervisory threshold asks for the
 * total value of the payment transactions over a rolling window — not for which of this package's tables the
 * value happens to sit in.
 *
 * So an installation that sells credit and issues vouchers only incidentally could cross the threshold while
 * {@see VoucherVolumeMonitor} counted calmly on. That is precisely the state the monitor was written
 * against: finding out at an audit that the line was passed eleven months ago and nobody was counting.
 *
 * ## What counts as money credit, decided in ONE place
 *
 * An add-on that grants usage units is a purchase of something; an add-on that grants none credits the
 * owner's money balance at face value, which is the instrument. {@see self::isMoneyCredit()} holds that
 * distinction, and the hosted one-time checkout asks it too — a second reading of the same question is a
 * second reading free to disagree, and the two answers decide different things about the same sale (whether
 * it is taxed at the till, and whether it counts toward the threshold).
 *
 * ## Refunds come off, and the reason is the same as the voucher side's
 *
 * A reversed top-up is money that never stayed in the instrument, so counting it would report a figure the
 * operator cannot defend. The reversal is cumulative on the row, so subtracting it covers a partial refund,
 * a second partial refund and a full one alike — and a row reversed in full nets to zero without needing a
 * second condition.
 *
 * ## Currencies are not converted
 *
 * The threshold is an amount in one currency, and a rate this class picked would put a number nobody
 * published under a supervisory figure. A top-up in another currency is counted under that currency and is
 * simply not part of this answer.
 */
final readonly class CreditTopUpVolume
{
    public function __construct(private AddonCatalog $addons) {}

    /**
     * Whether this add-on credits money rather than granting usage units.
     *
     * The absence of a grant is the signal, not a flag of its own: the catalog already says what an add-on
     * hands over, and a second declaration would be a second thing to keep in step with it.
     */
    public static function isMoneyCredit(AddonCatalog $addons, string $key): bool
    {
        return ! $addons->grantsFor($key) instanceof UnitGrant;
    }

    /**
     * Every currency money credit has actually been sold in.
     *
     * The sweep needs this for the same reason it reads the voucher table for its half: a currency somebody
     * sells in is a currency that has to be counted, and the only list that cannot drift out of step with
     * the sales is the one derived from them. Reading only the vouchers left an installation that sells
     * credit and issues no vouchers with an empty list, so the figure was computed correctly for currencies
     * nobody asked about and the sweep announced nothing.
     *
     * Not windowed, deliberately, and the voucher half is not either: the window belongs to the FIGURE, and
     * a currency dropped from this list because its last top-up aged out would take its own rolling total
     * with it — the answer would fall to nothing at the moment it stopped being asked, which is the shape of
     * a counter that goes quiet rather than down.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        $keys = $this->moneyCreditKeys();

        // Same refusal as `since()`: no credit add-on configured is an installation that does not sell
        // credit, not an empty answer arrived at by matching nothing.
        if ($keys === []) {
            return [];
        }

        return array_values(array_filter(
            AddonPurchase::query()->whereIn('addon_key', $keys)->distinct()->pluck('currency')->all(),
            is_string(...),
        ));
    }

    /**
     * The catalog keys that credit money rather than granting units.
     *
     * @return list<string>
     */
    private function moneyCreditKeys(): array
    {
        return array_values(array_filter(
            $this->addons->all(),
            fn (string $key): bool => self::isMoneyCredit($this->addons, $key),
        ));
    }

    /** What was paid into money credit over a window, net of what was refunded. */
    public function since(CarbonInterface $since, string $currency): Money
    {
        $keys = $this->moneyCreditKeys();

        // No credit add-on configured is not a zero somebody measured — it is an installation that does not
        // sell credit at all, and `whereIn` over an empty list would answer zero by matching nothing, which
        // is the same number arrived at by accident.
        if ($keys === []) {
            return Money::of(0, $currency);
        }

        $total = AddonPurchase::query()
            ->where('currency', $currency)
            ->whereIn('addon_key', $keys)
            ->where('created_at', '>=', $since)
            ->sum(DB::raw('amount_minor - reversed_minor'));

        // A reversal is capped at the purchase amount when it is written, so the difference cannot go
        // negative row by row. The floor is here anyway because the sum is a database answer about rows
        // this class did not write, and a negative supervisory figure is worse than a wrong one.
        return Money::of(max(0, (int) $total), $currency);
    }
}
