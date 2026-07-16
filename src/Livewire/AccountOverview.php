<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Support\Navigation;

/**
 * The account-hub landing screen: the config-driven navigation to the hub sections plus a one-line
 * summary of the owner's current tier. The nav is whatever config('billing.navigation') lists, so a
 * consumer adds, reorders or removes sections without touching the package.
 */
final class AccountOverview extends AccountScreen
{
    public function render(): View
    {
        return $this->view('billing::livewire.account-overview', [
            'items' => app(Navigation::class)->items(),
            'tierLabel' => app(TierCatalog::class)->label($this->currentTierKey()),
        ]);
    }
}
