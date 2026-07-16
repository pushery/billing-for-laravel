<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\InvoiceUpcoming;
use Pushery\Billing\Support\UsageFlusher;

/**
 * Drains a customer's usage outbox onto the invoice that is about to close.
 *
 * Metered usage is recorded locally and flushed on a schedule, which is right for a cycle that ends at
 * some later time but wrong for one ending NOW: the provider is finalizing this customer's next invoice,
 * and any usage still pending would miss it and be billed a whole cycle late. So when the upcoming-invoice
 * signal arrives, this force-flushes THAT owner immediately — backoff and the scheduled cadence ignored —
 * so what they used lands on the invoice they are about to receive.
 *
 * Scoped to the one owner on the event: a global flush here would report every customer's usage on one
 * customer's invoice signal. A reference this app does not own resolves to no owner and is a no-op.
 */
final readonly class FlushUpcomingUsage
{
    public function __construct(
        private CustomerDirectory $directory,
        private UsageFlusher $flusher,
    ) {}

    public function __invoke(InvoiceUpcoming $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own (another app on the same provider account)
        }

        $this->flusher->flushOwner($owner);
    }
}
