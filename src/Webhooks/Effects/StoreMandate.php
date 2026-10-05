<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Models\PaymentMandate;
use Pushery\Billing\Models\PaymentMandateAnchor;
use Pushery\Billing\Support\LockedRow;
use Pushery\Billing\Support\UniqueRow;

/**
 * Persists a granted mandate so the billing engine can charge it off-session.
 *
 * This is what makes a Mollie subscriber billable at all: the mandate is created by the customer's first
 * payment, and until it is stored the local engine finds no chargeable method and hands them to dunning
 * for a card they successfully added.
 *
 * Two properties carry the weight, and both are about repetition rather than the happy path:
 *
 * **Idempotent.** The effect is queued and the provider redelivers, so the same establishment arriving
 * twice is ordinary. `firstOrCreate` on `(provider, mandate_reference)` — the pair the table already holds
 * unique — means the second arrival returns the first row instead of giving the owner two payment methods
 * they added once.
 *
 * **The default is claimed only when there is no default.** The first mandate becomes it because there is
 * nothing else to charge; a later one must not take over, because the customer ADDED a method rather than
 * choosing to switch to it, and a silent switch bills the wrong card. Choosing is `makeDefault()`, and it
 * is a deliberate act with a screen behind it.
 *
 * Two mandates stored for one owner at once take turns on the owner's anchor row for the provider, which the
 * storing creates before the owner's first mandate exists, and the question whether a default exists is asked
 * under that lock with a locking read. Asked before it, both found none and both claimed it; on MySQL a plain read
 * after the wait would still answer from a snapshot older than the other mandate.
 */
final readonly class StoreMandate
{
    public function __construct(private CustomerDirectory $directory) {}

    public function __invoke(MandateEstablished $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            // A customer belonging to somebody else's install, or one deleted since. Resolving to nobody
            // is the answer here rather than an exception: the delivery is genuine, it is simply not ours.
            return;
        }

        DB::transaction(function () use ($owner, $event): void {
            LockedRow::take(
                PaymentMandateAnchor::model()::query()
                    ->where('owner_type', $owner->getMorphClass())
                    ->where('owner_id', $owner->getKey())
                    ->where('provider', $event->provider),
                ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(), 'provider' => $event->provider],
            );

            $holdsDefault = PaymentMandate::model()::query()
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())
                ->where('provider', $event->provider)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first() instanceof PaymentMandate;

            $this->store($owner, $event, $holdsDefault);
        }, LockedRow::ATTEMPTS);
    }

    private function store(Model $owner, MandateEstablished $event, bool $holdsDefault): void
    {
        UniqueRow::firstOrCreate(
            PaymentMandate::model()::query(),
            ['provider' => $event->provider, 'mandate_reference' => $event->mandateId],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'customer_reference' => $event->customerReference,
                'method' => $event->method,
                'status' => PaymentMandate::CHARGEABLE,
                'is_default' => ! $holdsDefault,
                // The card behind a card mandate, which the expiring-card warning reads; null for any other.
                'card_brand' => $event->card?->brand,
                'card_last4' => $event->card?->last4,
                'card_exp_month' => $event->card?->expMonth,
                'card_exp_year' => $event->card?->expYear,
            ],
        );
    }
}
