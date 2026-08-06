<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * Which money flow this installation uses with its provider.
 *
 * ONE READER, because there were three and they disagreed on both axes. `billing.marketplace.charge_type`
 * was read in the routing resolver, in the hosted checkout and in the one-time charge, and the third read it
 * differently in each direction:
 *
 *  - `ChargeType::from()` rather than `tryFrom()`, so a typo raised a raw `ValueError` at a place that takes
 *    money, while the other two shrugged and carried on;
 *  - a fallback of `SeparateTransfer` rather than `Destination`.
 *
 * All three docblocks claimed to fall back to "the shipped default". Exactly one was telling the truth: the
 * shipped default IS `separate_transfer`, and the two that fell back to `Destination` were silently choosing
 * the other lane — the one that moves the merchant of record. That is not a smaller mistake than the crash;
 * it is the quieter one.
 *
 * ## An unreadable value refuses, and does not fall back
 *
 * The fallback exists for an ABSENT key — a consumer whose published config predates it. A key that is
 * present and unreadable is a different situation: somebody typed something and meant it. Guessing a lane
 * there picks who the merchant of record is on their behalf, so it raises instead, naming the key.
 */
final readonly class ConfiguredChargeType
{
    public function __construct(private Repository $config) {}

    /**
     * The configured lane.
     *
     * @throws InvalidBillingConfig when the key is present but names no lane
     */
    public function get(): ChargeType
    {
        $configured = $this->config->get('billing.marketplace.charge_type');

        // Absent, not merely unreadable: the shipped default, which the config file also states. It refuses
        // rather than charges today, deliberately — the separate-transfer shape needs a second provider call
        // that is not built yet, and taking the whole payment while never paying the merchant is the one
        // outcome worse than a refusal.
        if ($configured === null) {
            return ChargeType::SeparateTransfer;
        }

        $lane = is_string($configured) ? ChargeType::tryFrom($configured) : null;

        if (! $lane instanceof ChargeType) {
            throw InvalidBillingConfig::forKey(
                'billing.marketplace.charge_type',
                'must name a charge type; a value nobody can read would otherwise decide which lane the money '
                .'takes, and with it who the merchant of record is',
            );
        }

        return $lane;
    }
}
