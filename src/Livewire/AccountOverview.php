<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Pushery\Billing\Account\Navigation;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Support\CatalogLabel;

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
    protected function headingKey(): string
    {
        return 'billing::account.overview.heading';
    }

    public function render(): View
    {
        // ONE reader of the navigation configuration, and it is the layout's. A second parser here would have
        // to re-implement all three gates by hand — route registered, route resolvable without arguments, and
        // `web_only` — and missing the last would leave the account-deletion flow an operator suppressed on a
        // native runtime gone from the sidebar and still on this page, one click from a working deletion.
        return $this->view('billing::livewire.account-overview', [
            'items' => Container::getInstance()->make(Navigation::class)->visibleItems(),
            'tierLabel' => CatalogLabel::translate(Container::getInstance()->make(TierCatalog::class)->label($this->currentTierKey())),
        ]);
    }
}
