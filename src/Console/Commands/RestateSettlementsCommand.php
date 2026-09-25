<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Enums\SettlementRestatementBlock;
use Pushery\Billing\Enums\SettlementRestatementState;
use Pushery\Billing\Marketplace\SettlementRestater;

/**
 * Issue again the settlements a corrected creator standing has made wrong.
 *
 * A standing recorded with a start date in the past queues every settlement since that date. This works the
 * queue off: each settlement is canceled and issued again under the standing now in force, left alone when
 * it already says what that standing requires, or kept with the reason it cannot be issued again. The
 * blocked ones are tried again on every run, because a new agreement or a later standing can unblock them.
 *
 * Safe to run at any time and as often as wanted. A settlement is canceled at most once.
 */
final class RestateSettlementsCommand extends Command
{
    protected $signature = 'billing:settlements:restate';

    protected $description = 'Issue again the settlements a corrected creator standing has made wrong';

    public function handle(SettlementRestater $restater): int
    {
        $now = CarbonImmutable::now();
        $restated = 0;
        $unchanged = 0;
        /** @var array<string, int> $blocked */
        $blocked = [];

        foreach ($restater->outstanding() as $order) {
            $order = $restater->process($order, $now);

            if ($order->state === SettlementRestatementState::Restated) {
                $restated++;

                continue;
            }

            if ($order->state === SettlementRestatementState::Blocked) {
                $reason = $order->blocked_reason instanceof SettlementRestatementBlock ? $order->blocked_reason->value : 'unknown';
                $blocked[$reason] = ($blocked[$reason] ?? 0) + 1;

                continue;
            }

            $unchanged++;
        }

        $this->components->info("Restated {$restated} settlement(s), {$unchanged} needed no change.");

        foreach ($blocked as $reason => $count) {
            $this->components->warn("{$count} settlement(s) could not be issued again: {$reason}.");
        }

        return self::SUCCESS;
    }
}
