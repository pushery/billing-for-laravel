<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\ChargebackReversed;
use Pushery\Billing\Support\AddonRefunds;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * Gives back the add-on purchase a chargeback reversed, once the bank takes the chargeback back.
 *
 * A provider that reports a chargeback when it is raised reverses the purchase at once, through `AddonRefunded` and
 * {@see ReverseAddonPurchase}: the credit comes off the balance, or the unused units are clawed back. When the bank
 * reverses the chargeback, the customer has paid after all, and the purchase goes back to what the payment still has
 * refunded or charged back. A refund that still stands stays reversed.
 *
 * Not switchable, like the reversal it undoes: the balance follows the money. The credit note is answered by
 * {@see ReinstateLocalInvoiceOnChargebackReversal}, which charges the credited amount again on an invoice of its own.
 */
final readonly class RestoreAddonPurchaseOnChargebackReversal implements DedupesOnReference
{
    public function __construct(private AddonRefunds $refunds) {}

    public function __invoke(ChargebackReversed $event): void
    {
        if (! $event->standingRefunded instanceof Money) {
            // Without the payment's standing total, the share to give back is a guess, and a guess gives back money that is
            // still owed. The purchase stays as it is, and the log names the payment so it can be put right by hand.
            Log::warning('billing: a reversed chargeback named no standing total for its payment, so no add-on purchase was restored', [
                'payment' => $event->reference,
                'chargeback' => $event->disputeReference,
            ]);

            return;
        }

        $this->refunds->restore($event->reference, $event->standingRefunded, $event->disputeReference);
    }

    /**
     * Once per reversed chargeback, as the effects on `ChargebackReceived` act once per dispute.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof ChargebackReversed) {
            throw new RuntimeException('RestoreAddonPurchaseOnChargebackReversal only handles ChargebackReversed events.');
        }

        return 'reversed:'.$event->disputeReference;
    }
}
