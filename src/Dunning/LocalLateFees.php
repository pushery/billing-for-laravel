<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\OrderItem;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\LocalBillingEngine;
use Pushery\Billing\ValueObjects\Money;

/**
 * The LateFees of a driver whose cycle the package bills itself: the fee becomes an open order of its own, and the
 * engine collects it once a cycle of the owner has been paid ({@see LocalBillingEngine::collectLateFees()}).
 *
 * ## An order of its own, not a line on the next cycle
 *
 * A late fee is compensation for the delay and buys nothing, so its document is outside the scope of VAT. EN 16931
 * lets a document stating that category state no other (BR-O-11), and the tax of a cycle is decided on the order's
 * whole gross. A fee on the cycle's order would be taxed with it, and its document could not be filed.
 *
 * ## Idempotent on the reference, in the database
 *
 * The advance passes `dunning:<subscription>:<rung>`, and the order carries it in its unique `reference` column. A
 * second run for the same rung loses the insert and is told nothing, which is the answer it needs: the fee is
 * already open. A lookup before the insert would leave the two runs a moment to both find nothing.
 *
 * ## Owned by the owner, not by the subscription
 *
 * The order has no subscription, because an order with one is a cycle: settling it would advance the period. The
 * fee is the owner's debt, collected with whichever of their cycles is paid next.
 */
final readonly class LocalLateFees implements LateFees
{
    public function __construct(private string $provider) {}

    public function apply(Model $owner, Money $fee, string $reference, string $description, ?Subscription $subscription = null): void
    {
        if (! $fee->isPositive()) {
            return;
        }

        try {
            DB::transaction(function () use ($owner, $fee, $reference, $description): void {
                $order = Order::model()::query()->create([
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'provider' => $this->provider,
                    'total_minor' => $fee->minorUnits,
                    'currency' => $fee->currency,
                    'status' => OrderStatus::Open,
                    'reference' => $reference,
                ]);

                OrderItem::model()::query()->create([
                    'order_id' => $order->id,
                    'description' => $description,
                    'unit_price_minor' => $fee->minorUnits,
                    'quantity' => 1,
                    'total_minor' => $fee->minorUnits,
                    'currency' => $fee->currency,
                    'type' => OrderItemType::LateFee,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The advance ran again for a rung whose fee is already open.
        }
    }
}
