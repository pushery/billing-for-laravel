<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\MerchantRelationshipEnded;
use Pushery\Billing\Support\BillingManager;

/**
 * Start a merchant's onboarding: create their connected account and print the link they open.
 *
 * ## Why this command exists at all
 *
 * Both halves were built and neither had a way in. `StripeMerchantOnboarding::createAccount()` and
 * `::onboardingLink()` have shipped since the marketplace lane landed, reachable through
 * `BillingManager::marketplaceRails()`, and the package's twenty-eight artisan commands touched none of
 * it — so connecting a creator meant writing a tinker script against the rails. A capability nothing can
 * reach is the defect this package records elsewhere as a finding; this is one of its own.
 *
 * ## It is idempotent, and that is a property of the account rather than of this command
 *
 * A second run creates no second account: the driver finds the local row first, because Stripe has no
 * notion of our merchants and could not answer "does this one already have an account". What a second run
 * does produce is a fresh LINK, and that is not a workaround — Stripe's account links are single-use and
 * short-lived by design, so re-running this is the normal way to give somebody a link that still works.
 */
final class MerchantOnboardCommand extends Command
{
    protected $signature = 'billing:merchant:onboard
        {type : the merchant\'s morph alias or class name}
        {id : its key}
        {--return= : where the provider sends the merchant when they finish}
        {--refresh= : where the provider sends them if the link expired before they did}';

    protected $description = 'Create a merchant connected account and print the onboarding link';

    public function handle(BillingManager $manager): int
    {
        $merchant = $this->resolveMerchant();

        if (! $merchant instanceof Model) {
            return self::FAILURE;
        }

        try {
            $rails = $manager->marketplaceRails();
        } catch (MarketplaceUnsupported $exception) {
            // The message this exception already carries says which of the two it is — the marketplace is
            // switched off, or the configured driver cannot route money. Re-wording it here would be a
            // second sentence for the same state, drifting from the first the moment either changes.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $return = $this->urlOption('return');
        $refresh = $this->urlOption('refresh');

        try {
            $intent = $rails->onboarding()->onboardingLink($merchant, $refresh, $return);
        } catch (MerchantRelationshipEnded $exception) {
            // The state the old behavior hid. Onboarding a merchant whose relationship has ended used to
            // print a link to an account the provider no longer releases funds through, and exit 0 — the
            // operator did what the code told them to and got a success they could not act on.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $account = $this->text($intent->payload, 'account', 'unknown');
        // A sentence rather than an empty line. A provider that answered no link at all would otherwise
        // print a blank where the link goes, and a blank reads as a display problem instead of as the
        // provider having returned nothing — which is the one of the two that needs acting on.
        $url = $this->text($intent->payload, 'url', '(the provider returned no link)');
        $expires = $this->text($intent->payload, 'expires_at', 'an unstated time');

        $this->components->info('Connected account: '.$account);
        $this->newLine();
        $this->line($url);
        $this->newLine();

        // Printed because it is the difference between "the link did not work" and "the link expired",
        // and only one of those is a reason to run this command again.
        $this->components->warn('Single-use, and it expires at '.$expires.'. Re-run this command for a fresh one.');

        // Said out loud, every time, because it is the question this command exists to answer. `charges_enabled`
        // is not a switch anywhere: the provider raises it itself once its own review passes, and no API
        // and no dashboard can set it. Until then the account exists and cannot receive money.
        $this->components->info('The account cannot receive money until the provider finishes its review. Nothing here or in the dashboard sets that — run billing:merchant:status to see what it is still waiting for.');

        return self::SUCCESS;
    }

    /**
     * The merchant behind the two arguments, or null with an error printed.
     *
     * TWO arguments, and it is the schema that decides that rather than a preference: a merchant is stored
     * polymorphically (`merchant_type` + `merchant_id`), so this package has no single merchant class to
     * look a bare key up in. Asking for the type is the honest version of a question that has two halves —
     * and it accepts the morph ALIAS as well as the class, because an installation with a morph map has
     * already decided that the alias is the name its rows go by.
     */
    private function resolveMerchant(): ?Model
    {
        // Both are required, non-array arguments, so the console layer has already guaranteed strings —
        // a guard here would be a branch no run can enter, which is a different defect from a missing one.
        $type = $this->argument('type');
        $id = $this->argument('id');

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true)) {
            $this->components->error($type.' is neither a morph alias nor an Eloquent model class.');

            return null;
        }

        $model = new $class;
        $merchant = $model->newQuery()->find($id);

        if (! $merchant instanceof Model) {
            $this->components->error('No '.$class.' with key '.$id.'.');

            return null;
        }

        return $merchant;
    }

    /**
     * One string out of a provider payload, or the stated stand-in.
     *
     * A `ClientIntent` payload is provider-shaped and therefore genuinely mixed, so every read of it is a
     * place where a changed shape would otherwise print `Array` at somebody. The stand-in says the field
     * was absent, which is a fact worth seeing rather than an empty gap.
     *
     * @param  array<string, mixed>  $payload
     */
    private function text(array $payload, string $key, string $absent): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $absent;
    }

    /**
     * A URL for the provider to send the merchant back to.
     *
     * Falls back to the application URL rather than refusing, because the two return paths are a detail of
     * the operator's own screens and an onboarding link is useful before those screens exist. What it does
     * NOT do is invent a path — the app root is somewhere real.
     */
    private function urlOption(string $name): string
    {
        $value = $this->option($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $this->laravel->make('config')->get('app.url');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'http://localhost';
    }
}
