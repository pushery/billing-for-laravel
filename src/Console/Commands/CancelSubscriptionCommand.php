<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Support\BillingAdmin;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * End an owner's subscription immediately — the terminal form of {@see BillingAdmin::cancel()}.
 *
 * The console has this action too; this is the half that works without a browser, for a support case handled
 * over SSH or from a runbook. It exists for the same reason the comp command does: an operation only
 * reachable through a UI is unreachable exactly when the UI is what is broken.
 *
 * An unknown owner FAILS rather than succeeding quietly, and so does an owner with no running subscription in
 * the scope named. A cancel that reports success while canceling nothing is the one outcome worse than an error
 * here: the agent moves on believing the subscription is ended, and it bills again next cycle. The platform's
 * default contract is canceled unless `--merchant` names a seller's scope by its uid (`m:<type>#<id>`) or
 * `--type` names another contract. A `--merchant` value that is not such a uid fails too, because the scope
 * would otherwise read it as the platform and cancel the platform's contract instead.
 */
final class CancelSubscriptionCommand extends Command
{
    protected $signature = 'billing:subscription:cancel
        {owner : The owner\'s primary key}
        {--reason= : Why it was canceled — recorded on the billing audit trail}
        {--merchant= : The seller\'s scope, as its uid m:<type>#<id>; the platform when omitted}
        {--type= : The contract type; the default contract when omitted}';

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

        $merchantUid = $this->option('merchant');
        $merchant = is_string($merchantUid) && $merchantUid !== '' ? MerchantScope::fromUid($merchantUid) : null;

        if ($merchant instanceof MerchantScope && $merchant->isPlatform()) {
            $this->components->error("'{$merchantUid}' is not a merchant uid; it reads m:<type>#<id>.");

            return self::FAILURE;
        }

        $type = $this->option('type');

        if (! $admin->cancel($owner, $reason, null, $merchant, is_string($type) && $type !== '' ? $type : null)) {
            $this->components->error("Owner '{$ownerKey}' has no running subscription in that scope; nothing was canceled.");

            return self::FAILURE;
        }

        $this->components->info("Canceled the subscription of owner '{$ownerKey}'.");

        return self::SUCCESS;
    }
}
