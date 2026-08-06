<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pushery\Billing\Marketplace\BuyerProtectionClock;
use Pushery\Billing\Models\BuyerProtectionHold;

/**
 * Moves every waiting payout whose time has come.
 *
 * Both deadlines only mean something if something checks them. Without a run, a buyer's silence never turns
 * into consent and a payout waits until the payment provider stops waiting and sends it anyway — which is
 * the arrangement failing in the direction nobody sees, because the money does arrive and only the promise
 * behind it was empty.
 *
 * Meant to run daily. A missed day is harmless: the deadlines are dates, so a late run moves everything that
 * has come due rather than only what came due today.
 */
final class AdvanceBuyerProtectionCommand extends Command
{
    protected $signature = 'billing:protection:advance {--dry-run}';

    protected $description = 'Release or escalate buyer-protection holds whose deadlines have passed.';

    public function handle(BuyerProtectionClock $clock): int
    {
        $now = Carbon::now();

        if ($this->option('dry-run') === true) {
            $due = BuyerProtectionHold::query()
                ->whereIn('state', ['awaiting_confirmation', 'disputed'])
                ->where(function (Builder $query) use ($now): void {
                    $query->where('confirm_by', '<=', $now)->orWhere('decide_by', '<=', $now);
                })
                ->count();

            $this->components->info("{$due} hold(s) would move.");

            return self::SUCCESS;
        }

        $moved = $clock->advance($now);

        foreach ($moved as $hold) {
            $this->components->info("{$hold->charge_reference} is now {$hold->state->value}.");
        }

        $this->components->info(count($moved).' hold(s) moved.');

        return self::SUCCESS;
    }
}
