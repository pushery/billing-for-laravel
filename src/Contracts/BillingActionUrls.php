<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\BillingAction;

/**
 * Where the button of a billing notice to one owner points.
 *
 * A notice to a billing owner carries a button to the screen where its reader acts on it, and by default that is a
 * screen of the account hub. The hub shows the account of whoever is signed in, so it cannot serve an owner the
 * application manages on a screen of its own, such as a company account its administrators run. Bind an
 * implementation that answers with that screen for such an owner and with the hub's for every other.
 *
 * It is asked while the notice is rendered, with the model the notice goes to. The package's own notifier sends
 * every notice to the billing owner, so that model is the owner.
 */
interface BillingActionUrls
{
    /** The URL where this owner acts on the notice, or null to send the notice without a button. */
    public function for(Model $owner, BillingAction $action): ?string;
}
