<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Pushery\Billing\Account\Navigation;
use Pushery\Billing\Contracts\TierCatalog;

/**
 * The account-hub landing screen: the config-driven navigation to the hub sections plus a one-line
 * summary of the owner's current tier. The nav is the layout's own already-filtered list, so a
 * consumer adds, reorders or removes sections without touching the package.
 *
 * The hub HOSTS ancillary app/auth screens (sessions, connections, set-password, onboarding) without owning
 * them: a consumer registers their route in the navigation config, and it appears here ONLY once that route
 * actually exists. An entry whose route is not (yet) registered is silently dropped rather than crashing the
 * screen — so the same config can name a section the app builds later.
 */
final class AccountOverview extends AccountScreen
{
    public function render(): View
    {
        // ONE reader of the navigation configuration, and it is the layout's. This method used to walk the
        // config through a second parser and re-implement two of its three gates by hand — route registered,
        // route resolvable without arguments — while leaving out the third. The one it left out was
        // `web_only`, so an operator who suppressed the account-deletion flow on a native runtime saw it
        // vanish from the sidebar and stay on this page, one click from a working deletion.
        return $this->view('billing::livewire.account-overview', [
            'items' => Container::getInstance()->make(Navigation::class)->visibleItems(),
            'tierLabel' => Container::getInstance()->make(TierCatalog::class)->label($this->currentTierKey()),
        ]);
    }
}
