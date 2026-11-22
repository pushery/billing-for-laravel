<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Models\DiscountAllowance;
use Pushery\Billing\Models\DiscountAllowanceUse;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Support\UniqueRow;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\OrderItemDraft;

/**
 * A fixed discount an owner's next cycles use up, before tax, until nothing of it is left.
 *
 * A coupon takes the same amount or share off every cycle it runs for. An allowance is an amount: the welcome discount
 * of a referred customer, say, granted once and spent over as many cycles as it takes. Each cycle the local engine
 * bills takes off at most what it comes to after its coupon, and the rest waits for the next one.
 *
 * ## Why before tax, and why it is not credit
 *
 * Credit is payment the customer already made, so it pays an invoice that states the full price and the full tax. An
 * allowance lowers the price of the supply itself, so the invoice states the reduced price as a discount line and the
 * tax is computed from it. Nothing of an allowance is ever paid out.
 *
 * ## What is left is derived, never stored
 *
 * A grant is a row of its own, and each order that took something off it records the amount it took. What is left is
 * the grant less the uses of orders that did not fail. A cycle priced again, as a dunning retry is, rewrites the use
 * of its own order, and a cycle that was never collected stops counting, so the remainder is right without anything
 * booking it back.
 *
 * ## An allowance meant before tax
 *
 * An application that prices net grants a net amount ({@see self::grantNet()}). Each cycle then takes it off the net
 * of its open lines, at the rate the cycle is taxed at, and the line takes off the gross that net carries, so the
 * amount means the same whatever the rate. What a cycle took is recorded as net, and what is left stays net.
 */
final readonly class DiscountAllowances
{
    /**
     * Grant an owner a fixed discount that its next cycles use up, once per cause.
     *
     * A second grant with the same cause changes nothing and returns the first, so an application can grant from
     * an event that may arrive twice. `$description` is the line the invoice of a cycle shows for what the grant
     * took off.
     *
     * @throws InvalidArgumentException when the amount is not positive, or the cause or the description is empty
     */
    public function grant(Model $owner, Money $amount, string $cause, string $description): DiscountAllowance
    {
        return $this->granted($owner, $amount, $cause, $description, false);
    }

    /**
     * Grant an owner a discount meant before tax, which its next cycles take off their net until nothing of it is left.
     *
     * Each cycle takes the amount off its net at the rate it is taxed at, and what is left stays net for the next one. A
     * second grant with the same cause changes nothing and returns the first, as `grant()` does.
     *
     * @throws InvalidArgumentException when the amount is not positive, or the cause or the description is empty
     */
    public function grantNet(Model $owner, Money $amount, string $cause, string $description): DiscountAllowance
    {
        return $this->granted($owner, $amount, $cause, $description, true);
    }

    private function granted(Model $owner, Money $amount, string $cause, string $description, bool $net): DiscountAllowance
    {
        if (! $amount->isPositive() || trim($cause) === '' || trim($description) === '') {
            throw new InvalidArgumentException('A discount allowance needs a positive amount, a cause and a description.');
        }

        return UniqueRow::firstOrCreate(
            DiscountAllowance::model()::query(),
            ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(), 'cause' => $cause],
            ['currency' => $amount->currency, 'amount_minor' => $amount->minorUnits, 'net' => $net, 'description' => $description],
        );
    }

    /** What is left of the owner's allowances in a currency, each in its own terms: the net of one meant before tax. */
    public function remainingFor(Model $owner, string $currency): Money
    {
        $left = 0;

        foreach ($this->allowancesOf($owner, $currency) as $allowance) {
            $left += $this->leftOf($allowance, null);
        }

        return Money::of($left, $currency);
    }

    /**
     * The lines with the owner's allowances taken off what they come to, one line per allowance, the oldest first.
     *
     * Writes nothing: a preview prices the cycle the same way, and the claim records the use once it holds the order
     * ({@see self::record()}). `$order` is the order of the cycle being priced, where it already exists: what it took
     * before is not counted against it, so pricing the same cycle again produces the same lines.
     *
     * Each line keeps what it took off its allowance in `taken`: the gross of the line for an allowance off the gross, and
     * the net for one meant before tax, which also keeps the cycle's rate in `tax_rate_bps`.
     *
     * @param  list<OrderItemDraft>  $drafts
     * @param  (Closure(): ?int)|null  $rate  the rate the cycle is taxed at, in basis points, asked only by an allowance meant before tax
     * @return list<OrderItemDraft>
     */
    public function apply(array $drafts, Model $owner, ?int $order, ?Closure $rate = null): array
    {
        $gross = $this->grossOf($drafts);

        if (! $gross instanceof Money || ! $gross->isPositive()) {
            return $drafts;
        }

        $open = $gross->minorUnits;

        foreach ($this->allowancesOf($owner, $gross->currency) as $allowance) {
            $left = $this->leftOf($allowance, $order);

            if ($left < 1 || $open < 1) {
                continue;
            }

            $rateBps = $allowance->net && $rate instanceof Closure ? $rate() : null;
            [$line, $taken] = $allowance->net
                ? $this->takenBeforeTax(Money::of($open, $gross->currency), $left, $rateBps ?? 0)
                : [min($open, $left), min($open, $left)];

            if ($line < 1 || $taken < 1) {
                continue;
            }

            $drafts[] = new OrderItemDraft(
                $allowance->description,
                -$line,
                1,
                $gross->currency,
                OrderItemType::Discount,
                [
                    'discount_allowance' => $allowance->id,
                    'cause' => $allowance->cause,
                    'taken' => $taken,
                    ...($allowance->net ? ['tax_rate_bps' => $rateBps] : []),
                ],
            );

            $open -= $line;
        }

        return $drafts;
    }

    /**
     * What an allowance meant before tax takes off the open lines: the gross of its line, and the net it counts against
     * the allowance.
     *
     * It takes up to what is left of it from the net of the open gross, at the cycle's rate, and the line takes off the
     * gross whose split leaves the rest of that net with its tax.
     *
     * @return array{int, int}
     */
    private function takenBeforeTax(Money $open, int $left, int $rateBps): array
    {
        [$net] = $open->baseFromMarkup($rateBps);
        $taken = min($left, $net->minorUnits);
        $rest = $net->minus(Money::of($taken, $net->currency));

        return [$open->minus($rest->plus($rest->proportion($rateBps, 10_000)))->minorUnits, $taken];
    }

    /**
     * Whether every allowance line of the drafts is still covered, read under a lock that holds until the claim commits.
     *
     * Two runs that price cycles of the same owner at once both see the same remainder. The first to claim records its
     * use, and the second finds less left than it priced: its claim does not stand, so the cycle is priced again on the
     * next run instead of being discounted twice out of one allowance.
     *
     * @param  list<OrderItemDraft>  $drafts
     */
    public function stillCovers(array $drafts, ?int $order): bool
    {
        $taken = $this->takenFrom($drafts);

        if ($taken === []) {
            return true;
        }

        $allowances = DiscountAllowance::model()::query()
            ->whereKey(array_keys($taken))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($allowances as $allowance) {
            if (($taken[$allowance->id] ?? 0) > $this->leftOf($allowance, $order)) {
                return false;
            }
        }

        return $allowances->count() === count($taken);
    }

    /**
     * Record what the order's lines take off each allowance, replacing whatever an earlier pricing of the order recorded.
     *
     * @param  list<OrderItemDraft>  $drafts
     */
    public function record(Order $order, array $drafts): void
    {
        $taken = $this->takenFrom($drafts);
        $uses = DiscountAllowanceUse::model();

        $uses::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('discount_allowance_id', array_keys($taken))
            ->delete();

        foreach ($taken as $allowance => $amount) {
            UniqueRow::updateOrCreate(
                $uses::query(),
                ['discount_allowance_id' => $allowance, 'order_id' => $order->getKey()],
                ['amount_minor' => $amount],
            );
        }
    }

    /** @return Collection<int, DiscountAllowance> */
    private function allowancesOf(Model $owner, string $currency): Collection
    {
        return DiscountAllowance::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('currency', $currency)
            ->orderBy('id')
            ->get();
    }

    /** What is left of an allowance: the grant less every use but those of `$order` and of orders that failed. */
    private function leftOf(DiscountAllowance $allowance, ?int $order): int
    {
        $used = (int) DiscountAllowanceUse::model()::query()
            ->where('discount_allowance_id', $allowance->id)
            ->when($order !== null, static fn (Builder $query): Builder => $query->where('order_id', '!=', $order))
            ->whereHas('order', static fn (Builder $query): Builder => $query->where('status', '!=', OrderStatus::Failed->value))
            ->sum('amount_minor');

        return max(0, $allowance->amount_minor - $used);
    }

    /**
     * The amount each allowance line of the drafts takes off its allowance, by allowance: what the line keeps in `taken`,
     * which is net for an allowance meant before tax, and else the line's own amount.
     *
     * @param  list<OrderItemDraft>  $drafts
     * @return array<int, int>
     */
    private function takenFrom(array $drafts): array
    {
        $taken = [];

        foreach ($drafts as $draft) {
            $allowance = $draft->metadata['discount_allowance'] ?? null;
            $amount = $draft->metadata['taken'] ?? null;

            if ($draft->type === OrderItemType::Discount && is_int($allowance)) {
                $taken[$allowance] = ($taken[$allowance] ?? 0) + (is_int($amount) ? $amount : -$draft->totalMinor());
            }
        }

        return $taken;
    }

    /**
     * What the lines come to, floored at zero, in the currency of the first.
     *
     * @param  list<OrderItemDraft>  $drafts
     */
    private function grossOf(array $drafts): ?Money
    {
        $sum = 0;
        $currency = null;

        foreach ($drafts as $draft) {
            $sum += $draft->totalMinor();
            $currency ??= $draft->currency;
        }

        return $currency === null ? null : Money::of(max(0, $sum), $currency);
    }
}
