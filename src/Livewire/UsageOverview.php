<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\UsageProvider;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\Support\PrepaidLedger;
use Pushery\Billing\Support\TrialCallouts;
use Pushery\Billing\ValueObjects\QuotaSnapshot;

/**
 * The account-hub usage screen: the owner's current metered usage per dimension, read from the
 * project's UsageProvider. An unmetered tier reports an empty snapshot, so the screen shows a plain
 * "nothing metered" note rather than a misleading zeroed gauge. While the owner is on a trial the
 * snapshot already reflects the trial tier's meters (the resolver unlocks it), and a note says so.
 */
final class UsageOverview extends AccountScreen
{
    use DegradesGracefully;

    public function render(): View
    {
        $state = $this->currentState();
        $owner = $this->owner();
        // A failing UsageProvider (a project's own code, or a metering backend it calls) degrades to an
        // inline notice instead of 500-ing the whole usage screen — the customer can still reach the rest.
        $snapshot = $this->orDegrade(fn (): QuotaSnapshot => app(UsageProvider::class)->snapshot($owner), new QuotaSnapshot([]));
        $ledger = app(PrepaidLedger::class);

        // The prepaid balance per metered dimension, shown alongside the cycle usage — only where the owner
        // actually bought units. `included` resets each cycle; prepaid rolls over, so the customer can see
        // the units they paid for and still hold.
        $prepaid = [];
        foreach ($snapshot->dimensions as $dimension) {
            $balance = $ledger->balance($owner, $dimension->key);

            if ($balance > 0) {
                $prepaid[$dimension->key] = $balance;
            }
        }

        return $this->view('billing::livewire.usage-overview', [
            'snapshot' => $snapshot,
            'prepaid' => $prepaid,
            // A read-only trial note: the usage shown is the trial tier's entitlement while the trial runs.
            'trial' => app(TrialCallouts::class)->for($owner, $state, $this->subscription()?->trial_ends_at),
        ]);
    }
}
