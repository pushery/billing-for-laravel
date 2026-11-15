<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\BaseInterestRates;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\OrderItem;
use Pushery\Billing\Support\BillingConnection;
use Pushery\Billing\ValueObjects\Money;
use RoundingMode;

/**
 * Default interest on a cycle paid late, opened as an order of its own once the cycle is paid.
 *
 * It runs from the day after `billing.dunning_interest.after_days` days have passed since the cycle fell due, which is
 * when it was first charged, until the day its payment is recorded, both counted. Each day earns at the rate that held
 * on that day: the base rate of {@see BaseInterestRates} plus the surcharge for the owner's kind, a business or a
 * consumer, in percentage points. A day is a 365th of its year, or a 366th in a leap year. The amount is the one the
 * cycle collected, and the sum is rounded to a minor unit once, half up.
 *
 * ## Why an order of its own
 *
 * Interest for a delay is compensation, not the price of a supply, so its document is outside the scope of VAT as a
 * late fee's is, and it cannot share a document with a cycle. It is collected the way a late fee is: the engine
 * charges it right after the cycle that made it owed.
 *
 * ## Idempotent on the cycle
 *
 * The order carries `interest:<cycle id>` in its unique reference, so a second attempt to open it for the same cycle
 * loses the insert and is told nothing.
 */
final readonly class DefaultInterest
{
    /** The prefix of an interest order's reference; the id of the cycle it is charged for follows it. */
    public const string REFERENCE = 'interest:';

    public function __construct(
        private Repository $config,
        private BaseInterestRates $rates,
        private IdentifiesBusinessBuyers $buyers,
    ) {}

    /**
     * Open the interest owed for a cycle that fell due on $due and was paid on $paidOn. Nothing where the interest is
     * off, where the payment came before the first day that earns it, or where it comes to less than a minor unit.
     */
    public function open(Model $owner, Order $cycle, Money $amount, DateTimeInterface $due, DateTimeInterface $paidOn, string $provider): void
    {
        $afterDays = $this->config->get('billing.dunning_interest.after_days');

        if (! is_numeric($afterDays) || (int) $afterDays < 0 || ! $amount->isPositive()) {
            return;
        }

        $first = CarbonImmutable::instance($due)->startOfDay()->addDays((int) $afterDays + 1);
        $last = CarbonImmutable::instance($paidOn)->startOfDay();

        if ($first->greaterThan($last)) {
            return;
        }

        $surcharge = $this->surchargeFor($owner);
        [$minor, $unknown] = $this->accrued($amount->minorUnits, $first, $last, $surcharge);

        if ($unknown !== []) {
            Log::warning('billing: no base rate is known for days of a late payment, so they earn no interest', [
                'order' => $cycle->getKey(),
                'days' => $unknown,
            ]);
        }

        if ($minor < 1) {
            return;
        }

        $description = sprintf(
            'Interest on the late payment of %s for the days from %s to %s, at %s percentage points above the base rate',
            $amount->format(),
            $first->toDateString(),
            $last->toDateString(),
            rtrim(rtrim(number_format($surcharge, 2, '.', ''), '0'), '.'),
        );

        // The key of a replaceable model is read as the scalar it is, so the reference names this cycle and no other.
        $key = $cycle->getKey();
        $reference = self::REFERENCE.(is_scalar($key) ? (string) $key : '');

        try {
            BillingConnection::connection()->transaction(function () use ($owner, $minor, $amount, $description, $provider, $reference): void {
                $order = Order::model()::query()->create([
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'provider' => $provider,
                    'total_minor' => $minor,
                    'currency' => $amount->currency,
                    'status' => OrderStatus::Open,
                    'reference' => $reference,
                ]);

                OrderItem::model()::query()->create([
                    'order_id' => $order->id,
                    'description' => $description,
                    'unit_price_minor' => $minor,
                    'quantity' => 1,
                    'total_minor' => $minor,
                    'currency' => $amount->currency,
                    'type' => OrderItemType::LateInterest,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The interest of this cycle is open already.
        }
    }

    /** The surcharge over the base rate for this owner, in percentage points: the business one or the consumer one. */
    private function surchargeFor(Model $owner): float
    {
        $kind = $this->buyers->isBusiness($owner) ? 'business' : 'consumer';
        $surcharge = $this->config->get("billing.dunning_interest.surcharge.{$kind}");

        return is_numeric($surcharge) ? (float) $surcharge : 0.0;
    }

    /**
     * The interest on $minor from $first to $last, both counted, in minor units rounded half up, and the days that had
     * no known base rate.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function accrued(int $minor, CarbonImmutable $first, CarbonImmutable $last, float $surcharge): array
    {
        $share = 0.0;
        $unknown = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $base = $this->rates->on($day);

            if ($base === null) {
                $unknown[] = $day->toDateString();

                continue;
            }

            // A negative base rate lowers the rate, as it did from 2013 to 2022; the sum never goes below nothing.
            $share += max(0.0, $base + $surcharge) / 100 / ($day->isLeapYear() ? 366 : 365);
        }

        return [(int) round($minor * $share, 0, RoundingMode::HalfAwayFromZero), $unknown];
    }
}
