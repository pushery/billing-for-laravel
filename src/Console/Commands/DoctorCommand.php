<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Drivers\Stripe\StripeServiceProvider;
use Stripe\StripeClient;
use Throwable;

/**
 * Checks that the Stripe API version the package is pinned to matches what the provider will actually render
 * webhook payloads in — because the pin alone is not enough.
 *
 * The package pins the version it SENDS on outbound calls (see StripeServiceProvider). But a webhook payload
 * is rendered in the version of the ENDPOINT that receives it (or, when the endpoint has none, the account's
 * default) — a setting that lives at Stripe, not in this codebase. So an endpoint pinned to an older version,
 * or left on the account default while the account drifts, delivers payloads in a shape the mapper was not
 * written for. The mapper reads fields defensively, so the failure is silent: a real billing event just
 * stops firing. This surfaces that drift as an operator- and CI-visible signal (a non-zero exit on mismatch).
 */
final class DoctorCommand extends Command
{
    protected $signature = 'billing:doctor';

    protected $description = 'Check that Stripe webhook endpoints render payloads in the pinned API version';

    public function handle(Repository $config, StripeClient $stripe): int
    {
        if (! (bool) $config->get('billing.enabled', true)) {
            $this->components->info('Billing is disabled; nothing to check.');

            return self::SUCCESS;
        }

        $pinned = $this->pinnedVersion($config);

        $this->components->info("The package is pinned to Stripe API version {$pinned}.");

        try {
            $endpoints = $stripe->webhookEndpoints->all(['limit' => 100]);
        } catch (Throwable $e) {
            // A diagnostic never fails the app because it could not reach the provider; it reports and stops.
            $this->components->warn('Could not read the Stripe webhook endpoints: '.$e->getMessage());

            return self::SUCCESS;
        }

        $drift = 0;
        $checked = 0;

        foreach ($endpoints->data as $endpoint) {
            $checked++;
            $version = is_string($endpoint->api_version) ? $endpoint->api_version : null;
            $url = $endpoint->url !== '' ? $endpoint->url : $endpoint->id;

            if ($version === null) {
                // No pinned version on the endpoint: its payloads follow the ACCOUNT's default version, which
                // can move under you. Pin it to match, or a later account-wide bump silently changes the shape.
                $this->components->warn("{$url} has no pinned version; it follows the account default. Pin it to {$pinned}.");
                $drift++;

                continue;
            }

            if ($version !== $pinned) {
                $this->components->error("{$url} renders payloads in {$version}, but the package expects {$pinned}.");
                $drift++;

                continue;
            }

            $this->components->info("{$url} matches.");
        }

        if ($checked === 0) {
            $this->components->warn('No webhook endpoints are configured at Stripe.');

            return self::SUCCESS;
        }

        if ($drift > 0) {
            $this->components->error("{$drift} of {$checked} webhook endpoint(s) do not match the pinned version.");

            return self::FAILURE;
        }

        $this->components->info("All {$checked} webhook endpoint(s) render payloads in the pinned version.");

        return self::SUCCESS;
    }

    /** The version the package pins, honouring an app override, else the tested default. */
    private function pinnedVersion(Repository $config): string
    {
        $version = $config->get('billing.stripe.api_version');

        return is_string($version) && $version !== '' ? $version : StripeServiceProvider::STRIPE_API_VERSION;
    }
}
