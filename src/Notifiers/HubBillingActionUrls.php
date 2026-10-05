<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifiers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Pushery\Billing\Contracts\BillingActionUrls;
use Pushery\Billing\Enums\BillingAction;

/**
 * The account hub's screen for every owner, and no button where the application has not mounted the hub.
 *
 * The hub registers its routes only when Livewire is installed, and Livewire is a suggestion rather than a
 * requirement, so a route can be missing. A notice then goes out with its text and without a button.
 */
final class HubBillingActionUrls implements BillingActionUrls
{
    public function for(Model $owner, BillingAction $action): ?string
    {
        return Route::has($action->route()) ? URL::route($action->route()) : null;
    }
}
