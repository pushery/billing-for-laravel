<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\ValueObjects\MeterPriceFacts;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Reads what Stripe ACTUALLY has: which meters are active, and what a metered price really says.
 *
 * `billing:meters:check` compares the configured `provider_meter` against the active meters, so a tier
 * pointing at a meter that was never created (or was archived) is caught before a month of usage goes
 * unbilled. It also compares the configured allowance against the PRICE — because the allowance lives in the
 * price (a graduated first tier costing nothing), and `included` in config only drives the gauge. If they
 * disagree the customer is quietly given a different number of free units than the interface promised.
 *
 * Only ACTIVE meters count: an archived meter no longer rates usage, so a tier still pointing at one is just
 * as broken as a tier pointing at nothing.
 */
final class StripeMeterInspector implements MeterInspector
{
    /** Meters are few — one per billed dimension — so a single page covers any real catalog. */
    private const int PAGE = 100;

    /** @var ?array<string, string> meter id => event name, fetched once per process */
    private ?array $meterNamesById = null;

    public function __construct(private readonly StripeClient $stripe) {}

    /** @return list<string> */
    public function activeMeterEventNames(): array
    {
        return array_values($this->meters());
    }

    public function priceFacts(string $providerPriceId): ?MeterPriceFacts
    {
        try {
            // `tiers` is not returned unless asked for — without the expand the graduated allowance is
            // invisible and the check would silently pass a price it never actually read.
            $price = $this->stripe->prices->retrieve($providerPriceId, ['expand' => ['tiers']]);
        } catch (InvalidRequestException) {
            return null; // no such price at the provider
        }

        $recurring = $price->recurring ?? null;
        $meterId = is_object($recurring) ? ($recurring->meter ?? null) : null;

        $currency = $price->currency ?? null;

        return new MeterPriceFacts(
            meterEventName: is_string($meterId) ? ($this->meters()[$meterId] ?? null) : null,
            currency: is_string($currency) ? strtoupper($currency) : null,
            firstTierUpTo: $this->firstTierUpTo($price->tiers ?? null),
        );
    }

    /**
     * The `up_to` of the graduated FIRST tier — the units the provider really gives away free. `inf` (a
     * single-tier price that is free forever) is not an allowance, it is a bug in the price, and reads as
     * null rather than as a number the check would happily match.
     */
    private function firstTierUpTo(mixed $tiers): ?int
    {
        $first = is_array($tiers) ? ($tiers[0] ?? null) : null;

        if (! is_object($first)) {
            return null;
        }

        $upTo = $first->up_to ?? null;

        return is_int($upTo) ? $upTo : null;
    }

    /**
     * Stripe's ACTIVE meters as id => event name.
     *
     * The map is the point: a price names the meter by ID (`recurring.meter`), while the package's config
     * names it by EVENT NAME (`provider_meter`) — the same identifier the usage reports carry. Comparing the
     * two needs both sides of the map.
     *
     * @return array<string, string>
     */
    private function meters(): array
    {
        if ($this->meterNamesById !== null) {
            return $this->meterNamesById;
        }

        $map = [];

        foreach ($this->stripe->billing->meters->all(['status' => 'active', 'limit' => self::PAGE])->data as $meter) {
            $id = $meter->id ?? null;
            $eventName = $meter->event_name ?? null;

            if (is_string($id) && is_string($eventName) && $eventName !== '') {
                $map[$id] = $eventName;
            }
        }

        return $this->meterNamesById = $map;
    }
}
