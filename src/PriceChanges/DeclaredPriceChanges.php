<?php

declare(strict_types=1);

namespace Pushery\Billing\PriceChanges;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\PriceChangeKind;
use Pushery\Billing\Support\KeyedConfig;
use Pushery\Billing\ValueObjects\Money;

/**
 * The price changes `billing.price_changes.changes` declares, each read against every rule it has to keep.
 *
 * A change that breaks a rule is refused as a whole and names every rule it breaks, before anything is announced: a notice
 * shorter than its kind needs, a tier the catalog does not price, a meter the tier does not have, a price that is not a
 * positive amount, a day that is not a day, a text with no English. A refused change is announced to nobody and changes no
 * price.
 */
final readonly class DeclaredPriceChanges
{
    public function __construct(private Repository $config) {}

    /**
     * The declared changes that break no rule.
     *
     * @return list<PriceChange>
     */
    public function all(): array
    {
        $changes = [];

        foreach ($this->declared() as $key => $declared) {
            $read = $this->read($declared, $key);

            if ($read instanceof PriceChange) {
                $changes[] = $read;
            }
        }

        return $changes;
    }

    /**
     * Every rule each refused change breaks, by the change's key.
     *
     * @return array<string, list<string>>
     */
    public function refusals(): array
    {
        $refusals = [];

        foreach ($this->declared() as $key => $declared) {
            $read = $this->read($declared, $key);

            if (is_array($read)) {
                $refusals[$key] = $read;
            }
        }

        return $refusals;
    }

    /** @return array<string, mixed> */
    private function declared(): array
    {
        $declared = $this->config->get('billing.price_changes.changes', []);
        $changes = [];

        foreach (is_array($declared) ? $declared : [] as $key => $change) {
            $changes[(string) $key] = $change;
        }

        return $changes;
    }

    /**
     * The change, or every rule it breaks.
     *
     * @return PriceChange|list<string>
     */
    private function read(mixed $declared, string $key): PriceChange|array
    {
        $change = is_array($declared) ? $declared : [];
        $problems = [];

        $kind = PriceChangeKind::tryFrom(is_string($change['kind'] ?? null) ? $change['kind'] : '');

        if (! $kind instanceof PriceChangeKind) {
            $problems[] = 'kind must be one of: '.implode(', ', array_map(static fn (PriceChangeKind $case): string => $case->value, PriceChangeKind::cases()));
        }

        $announceOn = $this->day($change['announce_on'] ?? null);
        $effectiveOn = $this->day($change['effective_on'] ?? null);

        if (! $announceOn instanceof CarbonImmutable) {
            $problems[] = 'announce_on must be a day, written as YYYY-MM-DD';
        }

        if (! $effectiveOn instanceof CarbonImmutable) {
            $problems[] = 'effective_on must be a day, written as YYYY-MM-DD';
        }

        $months = $kind instanceof PriceChangeKind ? $this->config->get('billing.price_changes.notice_months.'.$kind->value) : null;

        if ($kind instanceof PriceChangeKind && (! is_int($months) || $months < 0)) {
            $problems[] = "billing.price_changes.notice_months.{$kind->value} must be a number of months";
        }

        if ($announceOn instanceof CarbonImmutable && $effectiveOn instanceof CarbonImmutable && is_int($months) && $months >= 0) {
            $earliest = PriceChange::earliestAfter($announceOn, $months);

            if ($effectiveOn->lessThan($earliest)) {
                $problems[] = "effective_on {$effectiveOn->toDateString()} is earlier than {$months} full months of notice allow from "
                    ."announce_on {$announceOn->toDateString()}; the first day they allow is {$earliest->toDateString()}";
            }
        }

        // A change of a meter's price names its prices by tier and meter, every other kind by tier.
        $prices = $kind === PriceChangeKind::Metered ? [] : $this->prices($change['prices'] ?? null, $problems);
        $meterPrices = $kind === PriceChangeKind::Metered ? $this->meterPrices($change['prices'] ?? null, $problems) : [];
        $texts = $this->texts($change['text'] ?? null, $problems);

        if ($problems !== [] || ! $kind instanceof PriceChangeKind || ! $announceOn instanceof CarbonImmutable || ! $effectiveOn instanceof CarbonImmutable || ! is_int($months)) {
            return array_map(static fn (string $problem): string => "billing.price_changes.changes.{$key}: {$problem}", $problems);
        }

        return new PriceChange($key, $kind, $announceOn, $effectiveOn, $months, $prices, $texts, $meterPrices);
    }

    /**
     * The new price of each tier, in the currency the tier is priced in.
     *
     * @param  list<string>  $problems
     *
     * @param-out  list<string>  $problems
     *
     * @return array<string, Money>
     */
    private function prices(mixed $declared, array &$problems): array
    {
        if (! is_array($declared) || $declared === []) {
            $problems[] = 'prices must name at least one tier';

            return [];
        }

        $prices = [];

        foreach ($declared as $tier => $minor) {
            $tier = (string) $tier;
            $current = KeyedConfig::setting($this->config, 'billing.tiers', $tier, 'price_display');
            $currency = is_array($current) && is_string($current['currency'] ?? null) ? $current['currency'] : null;

            if ($currency === null) {
                $problems[] = "tier '{$tier}' is not a tier the catalog gives a price";

                continue;
            }

            if (! is_int($minor) || $minor < 1) {
                $problems[] = "the price of tier '{$tier}' must be a positive amount in minor units";

                continue;
            }

            $prices[$tier] = Money::of($minor, $currency);
        }

        return $prices;
    }

    /**
     * The new price per package of each meter, by tier, in the currency the catalog prices the meter in.
     *
     * @param  list<string>  $problems
     *
     * @param-out  list<string>  $problems
     *
     * @return array<string, array<string, Money>>
     */
    private function meterPrices(mixed $declared, array &$problems): array
    {
        if (! is_array($declared) || $declared === []) {
            $problems[] = 'prices must name at least one tier';

            return [];
        }

        $prices = [];

        foreach ($declared as $tier => $meters) {
            $tier = (string) $tier;
            $metered = KeyedConfig::setting($this->config, 'billing.tiers', $tier, 'metered');

            if (! is_array($meters) || $meters === []) {
                $problems[] = "the prices of tier '{$tier}' must name its meters, each with its price per package";

                continue;
            }

            foreach ($meters as $meter => $minor) {
                $meter = (string) $meter;
                $definition = is_array($metered) ? ($metered[$meter] ?? null) : null;
                $unitPrice = is_array($definition) ? ($definition['unit_price'] ?? null) : null;
                $currency = is_array($unitPrice) && is_string($unitPrice['currency'] ?? null) ? $unitPrice['currency'] : null;

                if ($currency === null) {
                    $problems[] = "tier '{$tier}' has no meter '{$meter}' the catalog gives a price per package";

                    continue;
                }

                if (! is_int($minor) || $minor < 1) {
                    $problems[] = "the price of meter '{$meter}' of tier '{$tier}' must be a positive amount in minor units";

                    continue;
                }

                $prices[$tier][$meter] = Money::of($minor, $currency);
            }
        }

        return $prices;
    }

    /**
     * What changes, by language.
     *
     * @param  list<string>  $problems
     *
     * @param-out  list<string>  $problems
     *
     * @return array<string, string>
     */
    private function texts(mixed $declared, array &$problems): array
    {
        $texts = [];

        foreach (is_array($declared) ? $declared : [] as $language => $text) {
            if (is_string($text) && trim($text) !== '') {
                $texts[(string) $language] = $text;
            }
        }

        if (! isset($texts['en'])) {
            $problems[] = 'text must say what changes in English, under en';
        }

        return $texts;
    }

    /** The day a value names, as midnight UTC, or null where it names none or one the calendar does not have. */
    private function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, 'UTC');
    }
}
