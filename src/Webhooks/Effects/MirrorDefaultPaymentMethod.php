<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\DefaultPaymentMethodChanged;
use Pushery\Billing\Jobs\RefreshCardOnFile;

/**
 * Keeps the copy of the card on the owner current when the customer's default payment method changes outside the
 * package: in the provider's own portal, through the card updater of the bank, or by removing the card that was the
 * default.
 *
 * The account hub shows the card from that copy and never asks the provider, and the trial callouts decide from it
 * whether to ask for a card. Without this effect both went on showing the card the copy was last written with.
 *
 * The provider is read by {@see RefreshCardOnFile} once the run has committed, never from inside the run's
 * transaction. An owner this application does not know resolves to nobody and nothing is read.
 */
final readonly class MirrorDefaultPaymentMethod
{
    public function __construct(private CustomerDirectory $directory) {}

    public function __invoke(DefaultPaymentMethodChanged $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if ($owner instanceof Model) {
            Bus::dispatch(new RefreshCardOnFile($owner));
        }
    }
}
