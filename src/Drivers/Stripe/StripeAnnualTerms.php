<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\ArrangesAnnualTerms;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * Arranges a consumer's annual Stripe subscription to go on monthly, through a subscription schedule.
 *
 * The schedule has two phases: the year that was paid for, exactly as the subscription stands in it, and then the
 * tier's monthly price for one month, after which the schedule lets go and the subscription goes on monthly by
 * itself. Stripe moves the subscription to the monthly price at the moment the year ends, so no renewal for a year is
 * ever raised.
 *
 * ## The current phase is written back as it stands
 *
 * Writing the phases of a schedule replaces them, and whatever a phase does not name it does not keep. So the year is
 * written back with what Stripe put into it from the subscription: its trial end, its discounts, its tax rates and
 * whether Stripe computes the tax. The monthly phase starts without the year's discounts: a coupon on the annual price
 * was granted for that price.
 *
 * ## A twelfth, where the monthly price was never named
 *
 * A subscription sold before its terms named a monthly price goes on at a twelfth of the price of its year, rounded down,
 * as a price of the same product made for the purpose. A higher price agreed by staying silent would not bind the
 * customer, and rounding down keeps the month at no more than its share of the year.
 *
 * ## One item only
 *
 * Every item of a phase recurs at one interval, and the package knows the monthly price of the tier and of nothing
 * else on the subscription. A subscription with a second item, a metered component or one the application added, is
 * not arranged, and that is logged as an error: its year would renew as a year.
 */
final readonly class StripeAnnualTerms implements ArrangesAnnualTerms
{
    public function __construct(private StripeClient $stripe) {}

    public function provider(): string
    {
        return 'stripe';
    }

    public function arrangeMonthlyAfterTerm(string $subscriptionReference, ?string $monthlyPrice, bool $replace = false): ?string
    {
        return $this->arrange($subscriptionReference, [$this->priced($monthlyPrice)], $replace);
    }

    public function arrangeFurtherYear(string $subscriptionReference, string $yearlyPrice, ?string $monthlyPrice): ?string
    {
        return $this->arrange($subscriptionReference, [['price' => $yearlyPrice], $this->priced($monthlyPrice)], true);
    }

    public function arrangeChangeAtTermEnd(string $subscriptionReference, string $price, ?string $thenMonthlyPrice = null): ?string
    {
        $after = [['price' => $price]];

        if ($thenMonthlyPrice !== null) {
            $after[] = ['price' => $thenMonthlyPrice];
        }

        return $this->arrange($subscriptionReference, $after, true);
    }

    /**
     * A price by its id, or null for a twelfth of the price of the year, which is worked out from the subscription.
     *
     * @return array{price: string}|null
     */
    private function priced(?string $price): ?array
    {
        return $price === null ? null : ['price' => $price];
    }

    /**
     * The schedule: the period as it stands, then each phase of `$after` for one period, after which it lets go.
     *
     * A schedule that holds the subscription already is the answer, unless it is to be replaced: for a further year,
     * which sits between the two phases it has, for another monthly price, or for a plan change at the end of the
     * period. Stripe names the schedule that holds the subscription, so a row that has not learned of it yet does not
     * keep the old one in place.
     *
     * @param  list<array{price: string}|null>  $after  the phases after the current one; null is a twelfth of the year
     */
    private function arrange(string $subscriptionReference, array $after, bool $replace): ?string
    {
        $subscription = $this->stripe->subscriptions->retrieve($subscriptionReference);
        $held = $subscription->schedule ?? null;
        $held = $held instanceof StripeObject ? ($held->id ?? null) : $held;

        if (is_string($held) && $held !== '') {
            if (! $replace) {
                return $held;
            }

            $this->release($held);
        }

        $items = $subscription->items->data ?? [];

        if (count($items) !== 1) {
            Log::error('billing: a subscription carries more than its tier item, so it cannot be arranged at the end of its '
                .'period, and Stripe will renew it as it stands', [
                    'subscription' => $subscriptionReference,
                    'items' => count($items),
                ]);

            return null;
        }

        $later = [];

        foreach ($after as $price) {
            $price ??= $this->twelfthOf($items[0], $subscriptionReference);

            if ($price === null) {
                return null;
            }

            $later[] = $price;
        }

        $schedule = $this->stripe->subscriptionSchedules->create(['from_subscription' => $subscriptionReference]);
        $current = $schedule->phases[0] ?? null;

        if (! $current instanceof StripeObject) {
            return null;
        }

        $written = $this->asItStands($current);
        $routing = $this->routingOf($subscription);
        // What every later phase repeats: the tax of the subscription, and for a merchant's where its money goes. A phase
        // that names neither would begin without them. A discount on the current phase is not repeated.
        $carried = [...array_intersect_key($written, ['default_tax_rates' => true, 'automatic_tax' => true]), ...$routing];
        $phases = [[...$written, ...$routing]];
        $quantity = $written['items'][0]['quantity'] ?? 1;

        foreach ($later as $price) {
            $phases[] = ['items' => [[...$price, 'quantity' => $quantity]], 'iterations' => 1, 'proration_behavior' => 'none', ...$carried];
        }

        $this->stripe->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'release',
            'proration_behavior' => 'none',
            'phases' => $phases,
        ]);

        return $schedule->id;
    }

    public function release(string $arrangementReference): void
    {
        try {
            $this->stripe->subscriptionSchedules->release($arrangementReference);
        } catch (RateLimitException $limited) {
            // A 429 is transient and only lands here because the SDK makes RateLimitException a subclass of
            // InvalidRequestException. Swallowing it would file "try again" as "nothing to release".
            throw $limited;
        } catch (InvalidRequestException $refusal) {
            if (! $this->hasLetGo($arrangementReference, $refusal)) {
                throw $refusal;
            }
        }
    }

    /**
     * Whether the schedule holds the subscription no longer, the one reason a refused release is no news: it was
     * released already, it ran its course, or it was canceled.
     */
    private function hasLetGo(string $reference, InvalidRequestException $refusal): bool
    {
        if ($refusal->getHttpStatus() === 404) {
            return true;
        }

        $status = $this->stripe->subscriptionSchedules->retrieve($reference)->status ?? null;

        return in_array($status, ['released', 'completed', 'canceled'], true);
    }

    /**
     * A twelfth of the price of the year, rounded down, as the price of a month of the same product.
     *
     * The tax behavior goes along, so a price that includes tax goes on including it. A price with no amount of its own,
     * a tiered one, has no twelfth, and that is logged: its year would renew as a year.
     *
     * @return array{price_data: array{currency: string, product: string, unit_amount: int, recurring: array{interval: string}, tax_behavior?: string}}|null
     */
    private function twelfthOf(StripeObject $item, string $subscriptionReference): ?array
    {
        $price = $item->price ?? null;

        if (! $price instanceof StripeObject) {
            $price = null;
        }

        $amount = $price->unit_amount ?? null;
        $currency = $price->currency ?? null;
        $product = $price->product ?? null;
        $product = $product instanceof StripeObject ? ($product->id ?? null) : $product;

        if (! is_int($amount) || ! is_string($currency) || ! is_string($product)) {
            Log::error('billing: the price of a consumer\'s year names no amount to take a twelfth of, so the year cannot be '
                .'arranged to go on monthly, and Stripe will renew it for a year', ['subscription' => $subscriptionReference]);

            return null;
        }

        $data = ['currency' => $currency, 'product' => $product, 'unit_amount' => intdiv($amount, 12), 'recurring' => ['interval' => 'month']];
        $taxBehavior = $price->tax_behavior ?? null;

        if (in_array($taxBehavior, ['inclusive', 'exclusive'], true)) {
            $data['tax_behavior'] = $taxBehavior;
        }

        return ['price_data' => $data];
    }

    /**
     * Where a merchant's subscription sends its money: the merchant's account and the share of each invoice, as the
     * subscription carries them. Empty for a platform subscription, and for one whose share moves by separate transfer:
     * that one names its merchant in metadata, which a phase that names no metadata leaves as it is.
     *
     * @return array{transfer_data?: array{destination: string, amount_percent?: float|int}, application_fee_percent?: float|int}
     */
    private function routingOf(StripeObject $subscription): array
    {
        $routing = [];
        $transfer = $subscription->transfer_data ?? null;

        if ($transfer instanceof StripeObject) {
            $destination = $transfer->destination ?? null;
            $destination = $destination instanceof StripeObject ? ($destination->id ?? null) : $destination;
            $percent = $transfer->amount_percent ?? null;

            if (is_string($destination) && $destination !== '') {
                $routing['transfer_data'] = ['destination' => $destination];

                if (is_int($percent) || is_float($percent)) {
                    $routing['transfer_data']['amount_percent'] = $percent;
                }
            }
        }

        $fee = $subscription->application_fee_percent ?? null;

        if (is_int($fee) || is_float($fee)) {
            $routing['application_fee_percent'] = $fee;
        }

        return $routing;
    }

    /**
     * The current phase as the parameters that write it back unchanged.
     *
     * Every read goes through `??`: a Stripe object logs a notice for a key its response does not carry, and `??` asks
     * `__isset` first.
     *
     * @return array{items: list<array{price: string, quantity: int}>, start_date: int, end_date: int, trial_end?: int, discounts?: list<array{coupon?: string, discount?: string, promotion_code?: string}>, default_tax_rates?: list<string>, automatic_tax?: array{enabled: true}}
     */
    private function asItStands(StripeObject $phase): array
    {
        $items = [];

        $listed = $phase->items ?? [];

        foreach (is_iterable($listed) ? $listed : [] as $item) {
            if (! $item instanceof StripeObject) {
                continue;
            }

            $price = $item->price ?? null;
            $price = $price instanceof StripeObject ? ($price->id ?? null) : $price;

            if (is_string($price)) {
                $quantity = $item->quantity ?? 1;
                $items[] = ['price' => $price, 'quantity' => is_int($quantity) ? $quantity : 1];
            }
        }

        $start = $phase->start_date ?? null;
        $end = $phase->end_date ?? null;

        $written = ['items' => $items, 'start_date' => is_int($start) ? $start : 0, 'end_date' => is_int($end) ? $end : 0];

        $trialEnd = $phase->trial_end ?? null;

        if (is_int($trialEnd)) {
            $written['trial_end'] = $trialEnd;
        }

        $discounts = [];

        $listed = $phase->discounts ?? [];

        foreach (is_iterable($listed) ? $listed : [] as $discount) {
            if (! $discount instanceof StripeObject) {
                continue;
            }

            foreach (['discount', 'coupon', 'promotion_code'] as $kind) {
                $id = $discount->{$kind} ?? null;
                $id = $id instanceof StripeObject ? ($id->id ?? null) : $id;

                if (is_string($id) && $id !== '') {
                    $discounts[] = [$kind => $id];

                    break;
                }
            }
        }

        if ($discounts !== []) {
            $written['discounts'] = $discounts;
        }

        $rates = [];

        $listed = $phase->default_tax_rates ?? [];

        foreach (is_iterable($listed) ? $listed : [] as $rate) {
            $id = $rate instanceof StripeObject ? ($rate->id ?? null) : $rate;

            if (is_string($id)) {
                $rates[] = $id;
            }
        }

        if ($rates !== []) {
            $written['default_tax_rates'] = $rates;
        }

        $tax = $phase->automatic_tax ?? null;

        if ($tax instanceof StripeObject && ($tax->enabled ?? false) === true) {
            $written['automatic_tax'] = ['enabled' => true];
        }

        return $written;
    }
}
