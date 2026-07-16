<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Support\Navigation;
use Pushery\Billing\ValueObjects\NavItem;

/**
 * The account-hub landing screen: the config-driven navigation to the hub sections plus a one-line
 * summary of the owner's current tier. The nav is whatever config('billing.navigation') lists, so a
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
        // Route gate: surface a nav entry only when its route is registered AND resolvable without arguments,
        // so a foreign/ancillary route the consumer has not built yet — or one that needs parameters the hub
        // cannot supply — is hidden instead of throwing on route() and 500-ing the whole landing page.
        $items = array_values(array_filter(
            app(Navigation::class)->items(),
            static function (NavItem $item): bool {
                if (! Route::has($item->route)) {
                    return false;
                }

                try {
                    route($item->route);

                    return true;
                } catch (UrlGenerationException) {
                    return false;
                }
            },
        ));

        return $this->view('billing::livewire.account-overview', [
            'items' => $items,
            'tierLabel' => app(TierCatalog::class)->label($this->currentTierKey()),
        ]);
    }
}
