<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Contracts\ReportsMerchantCapabilities;
use Pushery\Billing\Marketplace\MerchantCapabilities;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * Ask the provider what a merchant's capabilities are, for when the webhook that would have said never came.
 *
 * ## The failure this exists for
 *
 * The three capability flags had one writer and one production caller: the webhook effect on
 * `MerchantAccountUpdated`. A delivery goes missing — endpoint down, wrong secret, retry window closed —
 * and the merchant sits at "cannot receive" while their account is fully enabled at the provider. No money
 * is routed to them, and nothing in the tree could answer whether we never heard or heard and were told no.
 *
 * Subscriptions have had `billing:sync` for exactly this, with "use it to backfill after a webhook outage"
 * in its own docblock. The receiving side had nothing. That asymmetry — one ordinary operational failure,
 * planned for on one side and unhandled on the other — is what this closes.
 *
 * ## A maintenance command, never the money path
 *
 * One provider call per merchant, so it belongs where `billing:sync` belongs and nowhere near a checkout.
 * It is deliberately NOT scheduled: a timer that calls the provider for every merchant is a cost and
 * rate-limit decision an operator makes, and this package already carries findings about commands that
 * ended up in no schedule and about ones that reach out unasked. Manual first, scheduled separately if at
 * all.
 *
 * ## It writes through the one writer
 *
 * The report goes to `MerchantCapabilities::apply()` — the same seam the webhook uses — so the rule that
 * only a provider report lifts a flag stays one rule in one place. A second write path here would be a
 * second place that rule can be broken, and it would look identical from outside.
 */
final class RefreshMerchantCapabilitiesCommand extends Command
{
    protected $signature = 'billing:merchant:refresh
        {--type= : Only this merchant morph type}
        {--id= : Only this merchant id (needs --type)}';

    protected $description = 'Ask the provider for merchant capabilities, after a missed webhook';

    public function handle(MerchantOnboarding $onboarding, MerchantCapabilities $capabilities): int
    {
        if (! $onboarding instanceof ReportsMerchantCapabilities) {
            // Named, not fatal. A driver may legitimately be unable to answer — the null driver cannot, and
            // a consumer's own may not — and a command that died here would read as a broken installation
            // rather than as a capability this driver does not have.
            $this->components->warn(
                'The configured onboarding driver ('.$onboarding::class.') cannot report capabilities, so '
                .'nothing was asked. Only a driver implementing ReportsMerchantCapabilities can.'
            );

            return self::SUCCESS;
        }

        $accounts = $this->accounts();

        if ($accounts === []) {
            $this->components->info('No merchant accounts match; nothing to refresh.');

            return self::SUCCESS;
        }

        $refreshed = 0;

        foreach ($accounts as $account) {
            $merchant = $this->merchantOf($account);

            if (! $merchant instanceof Model) {
                // The morph target is the consumer's model and it can be gone — deleted, renamed, never
                // migrated. Saying which one beats skipping in silence: an account nobody can resolve is a
                // merchant nobody can pay, and that is worth a line.
                $this->components->warn(
                    $account->account_reference.' points at '.$account->merchant_type.' #'
                    .$account->merchant_id.', which does not resolve; skipped.'
                );

                continue;
            }

            $reported = $onboarding->capabilitiesFor($merchant);

            if (! $reported instanceof MerchantAccountReference) {
                $this->components->warn($account->account_reference.' has no account at the provider; skipped.');

                continue;
            }

            $capabilities->apply($reported);
            $refreshed++;

            $this->line(sprintf(
                '%s → charges %s · payouts %s · details %s',
                $account->account_reference,
                $reported->chargesEnabled ? 'yes' : 'no',
                $reported->payoutsEnabled ? 'yes' : 'no',
                $reported->detailsSubmitted ? 'yes' : 'no',
            ));
        }

        $this->components->info("Refreshed {$refreshed} merchant account(s) from the provider.");

        return self::SUCCESS;
    }

    /** @return list<MerchantAccount> */
    private function accounts(): array
    {
        $query = MerchantAccount::query();

        $type = $this->option('type');
        $id = $this->option('id');

        if (is_string($type) && $type !== '') {
            $query->where('merchant_type', $type);
        }

        if (is_string($id) && $id !== '') {
            $query->where('merchant_id', $id);
        }

        return array_values($query->orderBy('id')->get()->all());
    }

    /**
     * The merchant behind an account row, resolved explicitly rather than through the relation.
     *
     * Two failures make the relation the wrong tool here, and each one crashes a whole sweep for one bad
     * row. Reading `$account->merchant` lazy-loads, which throws outright in an application that has turned
     * lazy loading off — an ordinary production setting. Eager-loading instead moves the crash earlier: a
     * morph type whose CLASS no longer exists (deleted, renamed, never migrated) throws inside the load, so
     * the null check below would never be reached.
     *
     * Resolved by hand, both are ordinary answers: an unknown class is a named skip, and so is a row whose
     * merchant has been deleted.
     */
    private function merchantOf(MerchantAccount $account): ?Model
    {
        $class = Relation::getMorphedModel($account->merchant_type) ?? $account->merchant_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $merchant = $class::query()->find($account->merchant_id);

        return $merchant instanceof Model ? $merchant : null;
    }
}
