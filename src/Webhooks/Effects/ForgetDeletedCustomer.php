<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\CustomerDeleted;
use Pushery\Billing\Support\CardOnFileColumns;

/**
 * Forgets a customer the provider deleted: the owner's customer reference, and the copy of its card where the owner
 * keeps one.
 *
 * A reference to a deleted customer fails every call made with it. The provider's portal was the measured case: an
 * owner whose customer was deleted in the dashboard could not open it any more. With the reference gone, the next
 * checkout creates a new customer, the way it does for an owner that never had one. Nothing at the provider is asked or
 * changed here, so the effect writes the owner's row and nothing else.
 */
final readonly class ForgetDeletedCustomer
{
    public function __construct(
        private CustomerDirectory $directory,
        private Repository $config,
    ) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        $column = $this->config->get('billing.customer.column', 'stripe_id');
        $cleared = [is_string($column) && $column !== '' ? $column : 'stripe_id' => null];

        if (CardOnFileColumns::presentOn($owner)) {
            $cleared = [...$cleared, ...CardOnFileColumns::of(null)];
        }

        $owner->forceFill($cleared)->save();
    }
}
