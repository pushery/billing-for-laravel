<?php

declare(strict_types=1);

namespace Pushery\Billing\PriceChanges;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Pushery\Billing\Enums\PriceChangeKind;
use Pushery\Billing\ValueObjects\Money;

/**
 * One declared change of a price, as `billing.price_changes.changes` declares it: the kind of price, the day it is
 * announced on, the day it takes effect, the new price of each tier it changes and what changes in the words the
 * announcement says it in.
 *
 * Days are midnight UTC. The notice is counted in full calendar months: the month an announcement falls into is begun
 * and never counts, so a change announced on 22 October with one month of notice takes effect on 1 December at the
 * earliest.
 */
final readonly class PriceChange
{
    /**
     * @param  array<string, Money>  $prices  the new base price of each tier a change of kind `plan` names, by tier key
     * @param  array<string, string>  $texts  what changes, by language, English among them
     * @param  array<string, array<string, Money>>  $meterPrices  the new price per package of each meter a change of kind
     *                                                            `metered` names, by tier key and meter key
     */
    public function __construct(
        public string $key,
        public PriceChangeKind $kind,
        public CarbonImmutable $announceOn,
        public CarbonImmutable $effectiveOn,
        public int $noticeMonths,
        private array $prices,
        private array $texts,
        private array $meterPrices = [],
    ) {}

    /** The new base price of the tier, or null where the change leaves the tier's base price as it is. */
    public function priceOf(string $tier): ?Money
    {
        return $this->prices[$tier] ?? null;
    }

    /** The new price per package of the tier's meter, or null where the change leaves the meter's price as it is. */
    public function meterPriceOf(string $tier, string $meter): ?Money
    {
        return $this->meterPrices[$tier][$meter] ?? null;
    }

    /**
     * The keys of the tiers the change gives a new price, for their base or for a meter.
     *
     * @return list<string>
     */
    public function tiers(): array
    {
        return array_values(array_unique([...array_keys($this->prices), ...array_keys($this->meterPrices)]));
    }

    /**
     * What changes, by language, English among them.
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        return $this->texts;
    }

    /**
     * The day the change takes effect for a subscription it is announced to at this moment: its own day, or the first
     * day the notice allows from that moment, whichever is later. An announcement that comes late moves the day for the
     * subscription it reaches late, and for no other.
     */
    public function effectiveOnAfter(DateTimeInterface $announcedAt): CarbonImmutable
    {
        $earliest = self::earliestAfter($announcedAt, $this->noticeMonths);

        return $earliest->greaterThan($this->effectiveOn) ? $earliest : $this->effectiveOn;
    }

    /** The first day a change announced at this moment may take effect after this many full months of notice. */
    public static function earliestAfter(DateTimeInterface $announcedAt, int $months): CarbonImmutable
    {
        return CarbonImmutable::instance($announcedAt)->utc()->startOfMonth()->addMonthsNoOverflow($months + 1);
    }
}
