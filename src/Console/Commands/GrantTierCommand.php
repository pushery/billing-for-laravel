<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Support\BillingAdmin;

/**
 * Comp an owner onto a tier out of band — the terminal form of {@see BillingAdmin::comp()}, for the
 * support case an admin panel is not (yet) wired for. It writes the tier column directly and records the
 * grant on the billing audit trail, so a comp is always traceable to when and why it happened.
 *
 * Two guards make it hard to shoot yourself in the foot: an unknown tier key is refused before anything
 * is written (a typo would otherwise comp an owner onto a tier that does not exist), and granting a tier
 * that is NOT in `billing.untouchable_tiers` warns — because the next provider webhook is free to
 * overwrite such a grant, which is rarely what a support comp intends.
 */
final class GrantTierCommand extends Command
{
    protected $signature = 'billing:tier:grant
        {owner : The owner\'s primary key}
        {tier : The tier key to grant}
        {--reason= : Why the tier was granted — recorded on the billing audit trail}';

    protected $description = 'Comp an owner onto a tier out of band, recording the grant';

    public function handle(Repository $config, BillingAdmin $admin): int
    {
        $model = $config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->components->error('billing.customer.model is not configured; there is no owner to grant a tier to.');

            return self::FAILURE;
        }

        $tier = (string) $this->argument('tier');

        // Existence, not resolvability: a free tier is a valid comp target even though it has no price and
        // so no resolvable Plan. The guard's job is only to catch a typo'd key that no tier config declares.
        $tiers = $config->get('billing.tiers');

        if (! is_array($tiers) || ! array_key_exists($tier, $tiers)) {
            $this->components->error("No tier '{$tier}' is configured in billing.tiers.");

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

        $admin->comp($owner, $tier, $reason);

        $this->components->info("Granted tier '{$tier}' to owner '{$ownerKey}'.");

        $untouchable = $config->get('billing.untouchable_tiers');
        if (! is_array($untouchable) || ! in_array($tier, $untouchable, true)) {
            $this->components->warn("'{$tier}' is not in billing.untouchable_tiers — the next provider webhook may overwrite this grant.");
        }

        return self::SUCCESS;
    }
}
