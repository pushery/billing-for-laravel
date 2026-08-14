<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Support\BillingAdmin;

/**
 * End an owner's subscription immediately — the terminal form of {@see BillingAdmin::cancel()}.
 *
 * The console has this action too; this is the half that works without a browser, for a support case handled
 * over SSH or from a runbook. It exists for the same reason the comp command does: an operation only
 * reachable through a UI is unreachable exactly when the UI is what is broken.
 *
 * An unknown owner FAILS rather than succeeding quietly. A cancel that reports success while canceling
 * nothing is the one outcome worse than an error here: the agent moves on believing the subscription is
 * ended, and it bills again next cycle.
 */
final class CancelSubscriptionCommand extends Command
{
    protected $signature = 'billing:subscription:cancel
        {owner : The owner\'s primary key}
        {--reason= : Why it was canceled — recorded on the billing audit trail}';

    protected $description = 'End an owner\'s subscription immediately, recording the reason';

    public function handle(Repository $config, BillingAdmin $admin): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->error('billing.customer.model is not configured; there is no owner to cancel for.');

            return self::FAILURE;
        }

        $ownerKey = (string) $this->argument('owner');
        $owner = $model::query()->find($ownerKey);

        if (! $owner instanceof Model) {
            $this->components->error("No owner with key '{$ownerKey}'.");

            return self::FAILURE;
        }

        $reason = $this->option('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        $admin->cancel($owner, $reason);

        $this->components->info("Canceled the subscription of owner '{$ownerKey}'.");

        return self::SUCCESS;
    }
}
