<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;

/**
 * The next invoice of a subscription the local engine bills, previewed from that engine.
 *
 * The engine bills in arrears: the order that closes the running period is charged when the period ends. This
 * reads that order before it exists, assembled the way the cycle will assemble it and written nowhere
 * ({@see LocalBillingEngine::preview()}), and dates it at the subscription's next processing moment.
 *
 * It reads the owner's own subscription of the default type, the one the subscription screen shows, and only
 * one this engine bills. Null where nothing is upcoming: no such subscription, one with nothing scheduled, or
 * a tier without a price.
 */
final readonly class LocalUpcomingInvoice implements UpcomingInvoice
{
    public function __construct(
        private LocalBillingEngine $engine,
        private string $provider,
    ) {}

    public function preview(Model $billable): ?UpcomingInvoicePreview
    {
        $subscription = Subscription::model()::query()
            ->forOwner($billable)
            ->ofDefaultType()
            ->forMerchant(null)
            ->where('provider', $this->provider)
            ->latest('id')
            ->first();

        $moment = $subscription?->scheduled_processing_at;

        if (! $subscription instanceof Subscription || $moment === null) {
            return null;
        }

        $amount = $this->engine->preview($subscription);

        if (! $amount instanceof Money) {
            return null;
        }

        return new UpcomingInvoicePreview($moment->toDateTimeImmutable(), $amount);
    }
}
