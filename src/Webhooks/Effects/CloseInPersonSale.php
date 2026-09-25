<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Enums\InPersonSaleStatus;
use Pushery\Billing\Events\InPersonSaleCanceled;
use Pushery\Billing\Models\InPersonSaleRecord;

/**
 * Closes a sale at the counter whose payment the provider canceled before it was paid.
 *
 * Only a pending sale is closed. A paid one keeps its receipt whatever arrives after it, because a document that
 * was issued is not taken back by a later message about the same payment.
 */
final readonly class CloseInPersonSale
{
    public function __invoke(InPersonSaleCanceled $event): void
    {
        InPersonSaleRecord::model()::query()
            ->where('provider', $event->provider)
            ->where('payment_reference', $event->paymentReference)
            ->where('status', InPersonSaleStatus::Pending)
            ->update(['status' => InPersonSaleStatus::Canceled]);
    }
}
