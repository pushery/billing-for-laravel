<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use LogicException;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\MeterPriceFacts;

/**
 * Verifies that what the package believes about metered billing is what the PROVIDER actually has.
 *
 * Two classes of silent failure, both invisible until an invoice looks wrong:
 *
 * 1. The meter does not exist (a typo, a meter created only in test mode, a meter later archived). Usage
 *    reported into nothing fails on the far side, and the miss surfaces — if ever — as a suspiciously small
 *    invoice a month later.
 *
 * 2. The PRICE disagrees with the config. This one matters more than it looks: the free allowance lives in
 *    the provider's price (a graduated first tier costing nothing up to it), and the `included` value in
 *    config only drives the gauge the customer sees. If they disagree, nothing breaks — the customer is just
 *    given a different number of free units than the interface promised them.
 *
 * Fails (non-zero exit) on any mismatch, so a deploy check catches the drift instead of a customer.
 */
final class CheckMetersCommand extends Command
{
    protected $signature = 'billing:meters:check';

    protected $description = 'Verify every configured usage meter and its price against the provider.';

    public function handle(MeterCatalog $catalog, MeterInspector $inspector): int
    {
        $components = $catalog->billableComponents();

        if ($components === []) {
            $this->components->info('No metered tiers are configured; nothing to check.');

            return self::SUCCESS;
        }

        $active = $inspector->activeMeterEventNames();
        $problems = 0;

        foreach ($components as $component) {
            $problems += $this->checkComponent($component, $active, $inspector);
        }

        if ($problems > 0) {
            return self::FAILURE;
        }

        $this->components->info(count($components).' metered component(s) verified against the provider.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $active
     * @return int the number of problems found on this component
     */
    private function checkComponent(MeteredComponent $component, array $active, MeterInspector $inspector): int
    {
        // A billable component always has both — that is what makes it billable — so these are guards the
        // types demand of a nullable field, not branches the config can actually take.
        $meter = $component->providerMeter ?? throw new LogicException("Billable meter '{$component->key}' has no provider meter.");
        $price = $component->providerPrice ?? throw new LogicException("Billable meter '{$component->key}' has no provider price.");

        $problems = 0;

        if (! in_array($meter, $active, true)) {
            $this->components->error("Meter '{$meter}' ({$component->key}) is not an active meter at the provider — its usage will not be billed.");
            $problems++;
        }

        $facts = $inspector->priceFacts($price);

        if (! $facts instanceof MeterPriceFacts) {
            $this->components->error("Price '{$price}' ({$component->key}) does not exist at the provider.");

            return $problems + 1;
        }

        if ($facts->meterEventName !== $meter) {
            $this->components->error(
                "Price '{$price}' ({$component->key}) is backed by meter '".($facts->meterEventName ?? 'none')."', but the tier reports usage to '{$meter}' — the usage and the price are wired to different meters."
            );
            $problems++;
        }

        $currency = $component->unitPrice?->currency;

        if ($currency !== null && $facts->currency !== null && $facts->currency !== $currency) {
            $this->components->error("Price '{$price}' ({$component->key}) is in {$facts->currency}, but the tier displays {$currency}.");
            $problems++;
        }

        // THE ONE THAT HIDES. The allowance is the price's; `included` only drives the gauge.
        if ($component->included !== null && $facts->firstTierUpTo !== null && $facts->firstTierUpTo !== $component->included) {
            $this->components->error(
                "Price '{$price}' ({$component->key}) gives away {$facts->firstTierUpTo} free unit(s), but the tier promises {$component->included} — the customer is billed on a different allowance than the one they are shown."
            );
            $problems++;
        }

        return $problems;
    }
}
