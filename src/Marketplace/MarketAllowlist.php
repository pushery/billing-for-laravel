<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Exceptions\MarketNotOpen;

/**
 * The countries an operator has said they are registered in — and a refusal for every other one.
 *
 * This is a gate that has to close BEFORE the money moves. A sale into a country where nobody is registered
 * cannot be repaired by any document issued afterwards: the tax has arisen, the registration has not, and
 * the only remedies are retroactive registration or a voluntary disclosure. Every other guard in this
 * package can afford to run at document time; this one cannot.
 *
 * Two behaviors that look contradictory and are not:
 *
 * - As a FEATURE it is opt-in. With no list configured there is no gate and nothing changes, because a gate
 *   that defaulted to closed would stop every existing consumer at their next sale. That is not a guard, it
 *   is an outage.
 * - WITHIN the feature it is fail-closed. Once a list exists, anything not explicitly open is refused —
 *   including a country the evidence could not resolve. "We could not tell where they are" is not a reason
 *   to sell somewhere unknown; it is the clearest reason not to.
 *
 * The package never verifies that a registration exists. Nothing here could. It only enforces that a sale
 * does not happen where the operator has not claimed one.
 */
final readonly class MarketAllowlist
{
    public function __construct(private Repository $config) {}

    /** Whether the operator has configured market control at all. */
    public function isEnforced(): bool
    {
        return $this->table() !== null;
    }

    /**
     * The state of one market.
     *
     * An unlisted country is `Blocked` rather than open. A list that named only the countries to refuse
     * would be a denylist, and a denylist cannot express a country nobody has thought about yet — which is
     * every country, right up until the first sale into it.
     */
    public function stateOf(string $country): MarketAccess
    {
        $table = $this->table();

        if ($table === null) {
            return MarketAccess::Open;
        }

        $value = $table[strtoupper($country)] ?? null;

        if (! is_string($value)) {
            return MarketAccess::Blocked;
        }

        return MarketAccess::tryFrom($value) ?? MarketAccess::Blocked;
    }

    /**
     * Refuse a sale into a market that is not open.
     *
     * @param  ?string  $country  the resolved buyer country, or null when the evidence could not resolve one
     *
     * @throws MarketNotOpen
     */
    public function assertOpen(?string $country): void
    {
        if (! $this->isEnforced()) {
            return;
        }

        if ($country === null || $country === '') {
            throw MarketNotOpen::unresolvedCountry();
        }

        $state = $this->stateOf($country);

        if (! $state->permitsSale()) {
            throw new MarketNotOpen(strtoupper($country), $state);
        }
    }

    /**
     * Every market the operator has opened.
     *
     * @return list<string>
     */
    public function openMarkets(): array
    {
        $table = $this->table();

        if ($table === null) {
            return [];
        }

        $open = [];

        foreach ($table as $country => $state) {
            // A numeric key is a list entry, not a country code — a consumer who wrote the markets as a
            // plain list of codes has said nothing about their state, and unstated is refused.
            if (is_string($country) && $state === MarketAccess::Open->value) {
                $open[] = strtoupper($country);
            }
        }

        return $open;
    }

    /**
     * Every country the operator has named, whatever standing they gave it.
     *
     * Distinct from {@see self::openMarkets()} on purpose: a market deliberately held closed is a decision
     * somebody made, and a record of decisions that only listed the open ones would lose exactly the entries
     * explaining why a country produced no sales.
     *
     * @return list<string>
     */
    public function declaredMarkets(): array
    {
        $table = $this->table();

        if ($table === null) {
            return [];
        }

        $declared = [];

        foreach (array_keys($table) as $country) {
            if (is_string($country)) {
                $declared[] = strtoupper($country);
            }
        }

        return $declared;
    }

    /**
     * Refuse to boot with a market opened that the tax rates cannot price.
     *
     * The combination is the dangerous one precisely because neither half looks wrong: the market is open,
     * the calculator returns a number, and the number is zero. Checked at boot rather than at the sale, so
     * it surfaces on a deploy instead of on somebody's invoice.
     *
     * Only checked against a LOCAL rate table. When the rates come from the provider there is nothing here
     * to compare against, and pretending otherwise would be a guard that reports on a subject it cannot
     * see. The limit is stated rather than hidden.
     *
     * @param  callable(string): bool  $knowsRate
     */
    public function assertEveryOpenMarketIsPriced(callable $knowsRate): void
    {
        $unpriced = array_values(array_filter($this->openMarkets(), static fn (string $c): bool => ! $knowsRate($c)));

        if ($unpriced !== []) {
            $markets = implode(', ', $unpriced);
            $pronoun = count($unpriced) === 1 ? 'it' : 'them';

            throw InvalidBillingConfig::forKey(
                'billing.tax_markets',
                "opens {$markets} but the tax rates know no rate for {$pronoun}. An open market with no rate is the silent zero-percent path: the sale goes through, the calculator answers, and the answer is nothing",
            );
        }
    }

    /**
     * The configured table, or null when the operator has not configured one.
     *
     * @return ?array<array-key, mixed>
     */
    private function table(): ?array
    {
        $value = $this->config->get('billing.tax_markets');

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw InvalidBillingConfig::forKey(
                'billing.tax_markets',
                'must be a map of ISO country code to market state, or absent; a value that cannot be read as a map would leave every market unlisted and therefore refused',
            );
        }

        return $value;
    }
}
