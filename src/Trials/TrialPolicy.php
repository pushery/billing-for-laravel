<?php

declare(strict_types=1);

namespace Pushery\Billing\Trials;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;

/**
 * The single resolver for the trial policy — length, kind ({@see TrialMode}) and whether a card is
 * required — read from config with per-tier overrides. It is the one place trial config is interpreted,
 * so no code path hardcodes a trial rule.
 *
 * Resolution order for every knob: the per-tier override at `billing.tiers.<key>.trial.<knob>`, then the
 * global default at `billing.trial.<knob>`. The package default is NO trial (length 0), project-overridable.
 *
 * The end date is computed from a start date so the trial clock is a stored timestamp, never a live
 * provider call (consistent with the column-authoritative subscription model). Zero — or any
 * non-positive/malformed length — means no trial, and the end date is then null so callers don't
 * fabricate one.
 */
final readonly class TrialPolicy
{
    public function __construct(private Repository $config) {}

    /** The trial length in days for a tier (0 = no trial). Non-positive or malformed lengths are 0. */
    public function days(?string $tierKey = null): int
    {
        // Numeric strings are accepted too: env() delivers a string, and a published config may hand-write
        // one — so a length written as "14" must not silently mean "no trial".
        $value = $this->perTier($tierKey, 'days') ?? $this->config->get('billing.trial.days');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * Which kind of trial applies. An explicit `mode` (per-tier or global) wins; otherwise it is derived:
     * a configured `generic_tier` implies a generic trial, a positive length alone implies a subscription
     * trial, and nothing configured is None. Deriving Generic from `generic_tier` is what keeps a
     * generic-trial app from ALSO adding a subscription trial at checkout (a double trial).
     */
    public function mode(?string $tierKey = null): TrialMode
    {
        $configured = $this->perTier($tierKey, 'mode') ?? $this->config->get('billing.trial.mode');

        if (is_string($configured) && ($mode = TrialMode::tryFrom($configured)) instanceof TrialMode) {
            return $mode;
        }

        if ($this->genericTier() !== null) {
            return TrialMode::Generic;
        }

        return $this->days($tierKey) > 0 ? TrialMode::Subscription : TrialMode::None;
    }

    /**
     * Whether a subscription trial requires a card up front. Only an explicit `false` (per-tier or global)
     * lets the owner trial without one; the default requires it.
     */
    public function requiresPaymentMethod(?string $tierKey = null): bool
    {
        $value = $this->perTier($tierKey, 'requires_payment_method')
            ?? $this->config->get('billing.trial.requires_payment_method', true);

        return $value !== false;
    }

    /** The tier a GENERIC trial unlocks, or null when the app configures none (disabling generic trials). */
    public function genericTier(): ?string
    {
        $tier = $this->config->get('billing.trial.generic_tier');

        return is_string($tier) && $tier !== '' ? $tier : null;
    }

    /** Whether a trial of any kind is configured for a tier (mode is not None and the length is positive). */
    public function enabled(?string $tierKey = null): bool
    {
        return $this->mode($tierKey) !== TrialMode::None && $this->days($tierKey) > 0;
    }

    /** Whether the checkout should attach a SUBSCRIPTION trial for a tier (mode subscription + positive length). */
    public function subscriptionTrialEnabled(?string $tierKey = null): bool
    {
        return $this->mode($tierKey) === TrialMode::Subscription && $this->days($tierKey) > 0;
    }

    public function endsAt(DateTimeInterface $from, ?string $tierKey = null): ?DateTimeImmutable
    {
        $days = $this->days($tierKey);

        if ($days === 0) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($from)->add(new DateInterval("P{$days}D"));
    }

    /** A per-tier trial override at `billing.tiers.<key>.trial.<knob>`, or null when there is none. */
    private function perTier(?string $tierKey, string $knob): mixed
    {
        if ($tierKey === null || $tierKey === '') {
            return null;
        }

        // Index the tiers map by the LITERAL tier key rather than a dotted config path, so a key that
        // itself contains a dot resolves to its own array instead of being split into nested keys.
        $tiers = $this->config->get('billing.tiers');
        $tier = is_array($tiers) ? ($tiers[$tierKey] ?? null) : null;
        $trial = is_array($tier) ? ($tier['trial'] ?? null) : null;

        return is_array($trial) ? ($trial[$knob] ?? null) : null;
    }
}
