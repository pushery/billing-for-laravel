<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\ClassifiesMoneyCredit;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\ValueObjects\ContentReference;
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
 * An add-on that grants usage units is a purchase of something, and so is one its catalog classifies as a
 * product; an add-on its catalog classifies as money credit credits the owner's money balance at face value,
 * which is the instrument. {@see self::isMoneyCredit()} holds that distinction, and the hosted one-time checkout,
 * the local engine's tax basis and the purchase and refund effects ask it too — a second reading of the same
 * question is a second reading free to disagree, and the answers decide different things about the same sale
 * (whether it is taxed at the till, whether it counts toward the threshold, and whether money lands on a balance).
 *
 * ## A purchase is counted by what it was when it was made
 *
 * Each purchase records whether it put money on the buyer's balance, and that answer decides whether it counts.
 * Asked of today's catalog instead, a credit add-on renamed, taken out of sale or changed to grant units took
 * every earlier purchase of it out of the figure, up to a year of volume gone from the number held against the
 * threshold, and in the direction that matters, too low.
 *
 * A purchase recorded before that answer was kept is read against the catalog as it stands, as before, and one
 * whose add-on the catalog no longer names counts. Nobody can say any more what it was, and of the two wrong
 * answers a figure that is too high is the one somebody can still check. Those purchases leave the rolling
 * window within its length.
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
     * Whether this add-on credits money rather than granting usage units or selling a product.
     *
     * Only a catalog that says so makes an add-on money credit. It read the absence of a unit grant as the signal,
     * and that made money credit of everything else as well: a work, a post sold through a catalog of the
     * application's own, a one-off sponsorship. Each was credited back to the buyer at its price, untaxed at the
     * till and counted as voucher volume. A catalog that classifies credit ({@see ClassifiesMoneyCredit}, which the
     * shipped one does for `billing.addons`) answers for its keys; any other states it through the `voucher`
     * archetype, and an add-on it does not classify is no credit.
     */
    public static function isMoneyCredit(AddonCatalog $addons, string $key): bool
    {
        if ($addons->grantsFor($key) instanceof UnitGrant) {
            return false;
        }

        if ($addons instanceof ClassifiesMoneyCredit) {
            return $addons->isMoneyCredit($key);
        }

        return $addons instanceof SuppliesProductArchetypes && $addons->archetypeFor($key) === TaxArchetype::Voucher;
    }

    /**
     * Whether a purchase of this add-on lands on the buyer's balance: money credit by its catalog, and never a work.
     *
     * The register of works is asked as well, because a work bought through an unclassified `billing.addons` entry
     * is still a work: its buyer receives the work, and crediting the price back on top would hand them both.
     */
    public static function creditsMoney(AddonCatalog $addons, AddonContentMap $content, string $key): bool
    {
        return ! $content->contentFor($key) instanceof ContentReference && self::isMoneyCredit($addons, $key);
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
        return array_values(array_filter(
            $this->moneyCreditPurchases()->distinct()->pluck('currency')->all(),
            is_string(...),
        ));
    }

    /**
     * The purchases that went into money credit: by the answer each purchase recorded, and for a purchase
     * recorded without one, by today's catalog, counting a key the catalog no longer names.
     *
     * @return Builder<AddonPurchase>
     */
    private function moneyCreditPurchases(): Builder
    {
        $credit = $this->moneyCreditKeys();
        $named = $this->addons->all();

        return AddonPurchase::model()::query()->where(
            static fn (Builder $purchases): Builder => $purchases->where('money_credit', true)->orWhere(
                static fn (Builder $unrecorded): Builder => $unrecorded->whereNull('money_credit')->where(
                    static fn (Builder $keys): Builder => $keys->whereIn('addon_key', $credit)->orWhereNotIn('addon_key', $named),
                ),
            ),
        );
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
        $total = $this->moneyCreditPurchases()
            ->where('currency', $currency)
            ->where('created_at', '>=', $since)
            ->sum(DB::raw('amount_minor - reversed_minor'));

        // A reversal is capped at the purchase amount when it is written, so the difference cannot go
        // negative row by row. The floor is here anyway because the sum is a database answer about rows
        // this class did not write, and a negative supervisory figure is worse than a wrong one.
        return Money::of(max(0, (int) $total), $currency);
    }
}
