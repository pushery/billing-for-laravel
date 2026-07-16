<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\UsageHistoryProvider;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;

/**
 * The account-hub usage-history screen: an owner's usage across finished billing periods plus the add-on
 * top-up timeline, read column-authoritatively from the persisted counters (never a provider call). An
 * owner with no recorded usage sees a plain "nothing yet" note rather than an empty scaffold. Each read
 * sits behind the panel error boundary, so a project's own history binding failing degrades this screen
 * to a notice instead of 500-ing the whole hub.
 */
final class UsageHistory extends AccountScreen
{
    use DegradesGracefully;

    public function render(): View
    {
        $owner = $this->owner();
        $history = app(UsageHistoryProvider::class);

        $periods = $this->orDegrade(fn () => $history->periods($owner), []);
        $topups = $this->orDegrade(fn () => $history->topups($owner), []);

        // Group the flat per-meter rows into per-period cards; periods() is already newest-period-first, so
        // insertion order is preserved by the string keys.
        $byPeriod = [];

        foreach ($periods as $row) {
            $byPeriod[$row->period][] = $row;
        }

        return $this->view('billing::livewire.usage-history', [
            'byPeriod' => $byPeriod,
            'topups' => $topups,
        ]);
    }
}
