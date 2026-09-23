<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Events\DisputeOpened;
use Pushery\Billing\Models\Dispute;

/**
 * Keeps every dispute a provider opened, so the rate a card network measures can be read.
 *
 * The networks count a dispute when it is opened, not when it is decided, and the package used to keep only
 * the decided ones. Recorded once per dispute: the claim is on the provider's own reference for it, which is
 * the one identifier a redelivery is guaranteed to repeat.
 *
 * The merchant is resolved from the reference the event names, and a reference no local merchant answers to
 * leaves the row unlinked rather than dropping it. A dispute on a sale nobody can be named for still counts
 * toward the platform's own rate.
 */
final readonly class RecordOpenedDispute
{
    public function __construct(private MerchantAccountDirectory $accounts) {}

    public function __invoke(DisputeOpened $event): void
    {
        $merchant = $event->merchantReference === null
            ? null
            : $this->accounts->merchantForReference($event->merchantReference);

        Dispute::model()::query()->firstOrCreate(
            ['provider' => 'stripe', 'dispute_reference' => $event->disputeReference],
            [
                'payment_reference' => $event->paymentReference,
                'merchant_type' => $merchant?->getMorphClass(),
                'merchant_id' => $merchant?->getKey(),
                'account_reference' => $event->accountReference,
                'currency' => $event->amount->currency,
                'amount_minor' => $event->amount->minorUnits,
                'reason' => $event->reason,
                'reason_code' => $event->reasonCode,
                'evidence_due_by' => $event->evidenceDueBy,
                'opened_at' => Carbon::now(),
            ],
        );
    }
}
