<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A stored payment method as the account hub renders it — a provider-neutral mirror of the card /
 * mandate on file. Drivers hydrate this from their own objects (a Stripe payment method, a Mollie
 * mandate) so the UI never branches on a provider shape.
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

    /** A short human label, e.g. "Visa •••• 4242" for a card, otherwise the method type. */
    public function label(): string
    {
        if ($this->brand !== null && $this->last4 !== null) {
            return "{$this->brand} •••• {$this->last4}";
        }

        return $this->type;
    }

    /**
     * The instant the card stops being valid: the LAST second of its expiry month (a card is good
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
