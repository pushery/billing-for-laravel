<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\WithPagination;
use Pushery\Billing\Contracts\SuppliesUsageMovements;
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

    // Supplies $page and the query-string binding, so a deep-linked page survives a reload. Only the
    // movement stream pages; periods and top-ups are bounded by their own limits.
    use WithPagination;

    public function render(): View
    {
        $owner = $this->owner();
        $history = Container::getInstance()->make(UsageHistoryProvider::class);

        $periods = $this->orDegrade(fn () => $history->periods($owner), []);
        $topups = $this->orDegrade(fn () => $history->topups($owner), []);

        // Group the flat per-meter rows into per-period cards; periods() is already newest-period-first, so
        // insertion order is preserved by the string keys.
        $byPeriod = [];

        foreach ($periods as $row) {
            $byPeriod[$row->period][] = $row;
        }

        // The movement stream, and ONLY when a project has bound something that can account for one.
        //
        // Asked of the container rather than of `$history`, for two reasons. It decouples: a project can
        // supply movements without also replacing the history provider, which is the more common shape —
        // the two answer different questions from different tables. And this package's own
        // DatabaseUsageHistory deliberately does NOT implement it: `billing_usage_events` records
        // consumption, but grants land in the prepaid ledger as a BALANCE (`balance`, `granted_total`),
        // with no row per credit. A stream that showed spending and hid the top-ups would raise exactly
        // the question it exists to answer, so the honest default is to show none at all.
        $container = Container::getInstance();

        // getPage() is typed loosely by the trait; normalize once so the contract sees a real int.
        $rawPage = $this->getPage();
        $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;

        $movements = $container->bound(SuppliesUsageMovements::class)
            ? $this->orOmit(fn (): LengthAwarePaginator => $container->make(SuppliesUsageMovements::class)
                ->movements($owner, page: $page))
            : null;

        return $this->view('billing::livewire.usage-history', [
            'byPeriod' => $byPeriod,
            'topups' => $topups,
            'movements' => $movements,
        ]);
    }
}
