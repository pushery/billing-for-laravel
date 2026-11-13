<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;

/**
 * The next invoice of a subscription the local engine bills, previewed from that engine.
 *
 * The next invoice is the engine's next charge ({@see LocalBillingEngine::previewNextCharge()}): the day of the
 * run that collects it and what that run collects, assembled the way the cycle will assemble it and written
 * nowhere. Collected in arrears, that run closes the running period; collected in advance, it opens the next
 * one, at the tier a plan change scheduled to its start asked for. A free trial collected in arrears charges
 * nothing at its end, so there the next invoice is the period after it, dated at that period's end.
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

        if (! $subscription instanceof Subscription) {
            return null;
        }

        return $this->engine->previewNextCharge($subscription);
    }
}
