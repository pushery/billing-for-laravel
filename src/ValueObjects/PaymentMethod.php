<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Pushery\Billing\Support\PaymentMethodNames;

/**
 * A stored payment method as the account hub renders it — a provider-neutral mirror of the card /
 * mandate on file. Drivers hydrate this from their own objects (a Stripe payment method or another provider's object) so the UI never branches on a provider shape.
 */
final readonly class PaymentMethod
{
    public function __construct(
        public string $id,
        public string $type,
        public bool $isDefault = false,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expMonth = null,
        public ?int $expYear = null,
    ) {}

    /**
     * A short human label: the brand, or without one the kind of method, by the name a customer reads, and the
     * last four digits where the provider gave them. "Visa •••• 4242", "SEPA Direct Debit •••• 3000".
     */
    public function label(): string
    {
        $name = $this->brand !== null ? PaymentMethodNames::brand($this->brand) : PaymentMethodNames::kind($this->type);

        return $this->last4 !== null ? "{$name} •••• {$this->last4}" : $name;
    }

    /**
     * The instant the card stops being valid: the last second of its expiry month (a card is good
     * through the end of the month printed on it). Null when the method carries no expiry (a non-card
     * mandate, or a provider that omitted it).
     */
    public function expiresAt(): ?Carbon
    {
        // Out-of-range month is corrupt provider data — treat it as "no expiry" rather than overflowing
        // into a neighboring year (Carbon::create is lenient and would).
        if ($this->expMonth === null || $this->expYear === null || $this->expMonth < 1 || $this->expMonth > 12) {
            return null;
        }

        return Carbon::create($this->expYear, $this->expMonth, 1)?->endOfMonth();
    }

    /** Whether the card's expiry month has already passed. A method with no expiry never "expires". */
    public function hasExpired(?DateTimeInterface $now = null): bool
    {
        $expiresAt = $this->expiresAt();
        $reference = $now instanceof DateTimeInterface ? Carbon::instance($now) : Carbon::now();

        return $expiresAt instanceof Carbon && $expiresAt < $reference;
    }

    /**
     * Whether the card expires within the next $days (and has not already expired). This is the window a
     * warning fires in — expiry is the biggest source of involuntary churn.
     */
    public function isExpiringWithin(int $days, ?DateTimeInterface $now = null): bool
    {
        $expiresAt = $this->expiresAt();

        if (! $expiresAt instanceof Carbon) {
            return false;
        }

        $reference = $now instanceof DateTimeInterface ? Carbon::instance($now) : Carbon::now();

        return $expiresAt >= $reference && $expiresAt <= $reference->addDays($days);
    }
}
