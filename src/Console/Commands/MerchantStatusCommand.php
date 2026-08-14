<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Pushery\Billing\Contracts\ReportsOnboardingRequirements;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\Support\BillingManager;
use Throwable;

/**
 * What each merchant account can do, and what the provider is still waiting for.
 *
 * ## The question this replaces
 *
 * "Where do I switch `charges_enabled` on?" — nowhere. It is not a switch: the provider raises it itself
 * once its own identity and bank review passes, and there is no endpoint and no dashboard control that
 * sets it. So the useful question is the other one, "what is it still waiting for", and that always has a
 * concrete answer in the provider's outstanding requirements.
 *
 * ## Why the flags come from the row and the requirements from the provider
 *
 * The three flags are a SNAPSHOT kept current by the provider's account event, and they are what every
 * routing decision reads — printing a live copy here would show a different answer from the one the money
 * paths use, which is worse than showing a slightly older one. The outstanding requirements are the
 * opposite case: they change every time the merchant touches the provider's form, nothing routes on them,
 * and the local row has never carried them. So each is read where it is true.
 *
 * The live half is also OPTIONAL, through {@see ReportsOnboardingRequirements}. A driver that cannot
 * answer it still gets the whole flag table rather than an error, because "which of my merchants can take
 * money" is the more important of the two questions and it does not depend on the provider being reachable.
 */
final class MerchantStatusCommand extends Command
{
    protected $signature = 'billing:merchant:status
        {--type= : narrow to one merchant morph alias or class}
        {--id= : narrow to one merchant key, with --type}
        {--no-requirements : skip the live provider read and print the stored flags only}';

    protected $description = 'Show what each merchant account may do and what the provider is still waiting for';

    public function handle(BillingManager $manager): int
    {
        try {
            $manager->marketplaceRails();
        } catch (MarketplaceUnsupported $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $accounts = $this->accounts();

        if ($accounts->isEmpty()) {
            // Not an error. An installation whose merchants have not started onboarding is in an ordinary
            // state, and returning failure for it would make this command useless in a deploy check.
            $this->components->info('No merchant accounts exist yet. Run billing:merchant:onboard to create the first one.');

            return self::SUCCESS;
        }

        $rows = [];
        $blocked = 0;

        foreach ($accounts as $account) {
            $reference = $account->toReference();
            $receivable = $reference->isReceivable();

            if (! $receivable) {
                $blocked++;
            }

            $rows[] = [
                $account->merchant_type.' #'.$account->merchant_id,
                $account->account_reference,
                $this->mark($reference->chargesEnabled),
                $this->mark($reference->payoutsEnabled),
                $this->mark($reference->detailsSubmitted),
                $this->outstanding($manager, $account),
                // WHEN we last heard, beside WHAT we heard. The pair is the whole diagnosis: three `false`
                // with a stamp means the provider was asked and said no; three `false` with `never` means
                // nobody has ever told us anything, which is the shape a lost webhook leaves and is fixed
                // by `billing:merchant:refresh` rather than by waiting.
                $account->capabilities_refreshed_at?->toDateTimeString() ?? 'never',
            ];
        }

        $this->table(
            ['Merchant', 'Account', 'Charges', 'Payouts', 'Details', 'Waiting on', 'Heard'],
            $rows,
        );

        $this->newLine();

        if ($blocked === 0) {
            $this->components->info('Every account can receive money.');

            return self::SUCCESS;
        }

        // SUCCESS even with blocked accounts, and deliberately: a merchant part-way through the provider's
        // form is the normal state of an onboarding funnel, not a fault in this installation. A non-zero
        // exit here would make the command unusable in the deploy check where somebody would first reach
        // for it, and would train people to ignore it.
        $this->components->warn($blocked.' of '.count($rows).' account(s) cannot receive money yet. The provider raises those flags itself once its review passes; nothing here sets them.');

        return self::SUCCESS;
    }

    /**
     * The accounts to report on, narrowed by the options when they were given.
     *
     * Handed back as the Collection the query produced rather than as an array: the caller asks it whether
     * it is empty and how many there are, and a keyed array would have to be re-indexed to answer either
     * without saying anything truer.
     *
     * @return Collection<int, MerchantAccount>
     */
    private function accounts(): Collection
    {
        $query = MerchantAccount::query()->orderBy('merchant_type')->orderBy('merchant_id');

        $type = $this->option('type');
        $id = $this->option('id');

        if (is_string($type) && $type !== '') {
            $query->where('merchant_type', $type);
        }

        if (is_string($id) && $id !== '') {
            $query->where('merchant_id', $id);
        }

        return $query->get();
    }

    /**
     * What the provider is still waiting for on this account, as one readable cell.
     *
     * Failures here are reported IN THE CELL rather than thrown, because the flags in the same row are
     * already useful and a provider that is unreachable must not cost the whole table. An outage is a fact
     * about today, not about the merchant.
     */
    private function outstanding(BillingManager $manager, MerchantAccount $account): string
    {
        if ($this->option('no-requirements') === true) {
            return '<fg=gray>not asked</>';
        }

        $onboarding = $manager->marketplaceRails()->onboarding();

        if (! $onboarding instanceof ReportsOnboardingRequirements) {
            return '<fg=gray>driver cannot say</>';
        }

        $merchant = $account->merchant;

        if ($merchant === null) {
            // The row outlived the merchant — erasure leaves this behind on purpose so a provider event
            // about somebody who is gone still resolves to a row rather than to whoever now holds the id.
            return '<fg=gray>merchant is gone</>';
        }

        try {
            $outstanding = $onboarding->outstandingFor($merchant);
        } catch (Throwable $exception) {
            return '<fg=yellow>unreadable: '.$exception->getMessage().'</>';
        }

        if ($outstanding === null) {
            return '<fg=gray>no provider account</>';
        }

        if ($outstanding['disabled_reason'] !== null) {
            // Printed FIRST when present, because it is the one answer that no amount of paperwork changes.
            // A list of documents beside it would read as "do these and you are done", which it is not.
            return '<fg=red>'.$outstanding['disabled_reason'].'</>';
        }

        if ($outstanding['currently_due'] !== []) {
            return implode(', ', $outstanding['currently_due']);
        }

        if ($outstanding['eventually_due'] !== []) {
            // Not blocking today, and saying so matters: this is what turns into a hold after a threshold
            // is crossed, and it is the only warning anybody gets before that happens.
            return '<fg=yellow>later: '.implode(', ', $outstanding['eventually_due']).'</>';
        }

        return '<fg=green>nothing</>';
    }

    private function mark(bool $enabled): string
    {
        return $enabled ? '<fg=green>yes</>' : '<fg=red>no</>';
    }
}
