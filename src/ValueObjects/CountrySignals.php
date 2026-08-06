<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\SignalSource;

/**
 * What the three sources say about where a buyer is.
 *
 * They exist only at the moment of the sale. The payment country comes from the instrument being used and
 * the IP country from a connection that is open right then; neither can be reconstructed afterwards, and
 * the raw IP is deliberately discarded as soon as it has been turned into a country. So a sale that did not
 * record these has no evidence of where it happened and no way to obtain any later.
 *
 * Each is nullable because absence is ordinary rather than exceptional — a consumer may not resolve IPs at
 * all, and a payment method may not carry a country. Absence is not disagreement, and the two must never be
 * conflated: two signals that agree and one missing is a stronger position than two that contradict.
 */
final readonly class CountrySignals
{
    public function __construct(
        /** What the buyer said about themselves. */
        public ?string $declared = null,
        /** Where the payment instrument is issued. */
        public ?string $payment = null,
        /** Where the connection appeared to be, already resolved to a country and nothing finer. */
        public ?string $ip = null,
        /**
         * What each of those same three sources said about the SUBDIVISION, where one is carried.
         *
         * Trailing and optional because a subdivision is not part of settling a country and never has been:
         * every existing caller keeps working and writes nothing new. It travels beside the countries rather
         * than inside them so a subdivision is always read from the source that named the country — see
         * {@see SubdivisionSignals} for why that is the only reading that means anything.
         */
        public ?SubdivisionSignals $subdivisions = null,
    ) {}

    /**
     * Which sources named this country, in the evidence's own order.
     *
     * Asked so a resolved subdivision can come from a source that actually named the resolved country. A
     * subdivision from a source that named a DIFFERENT country describes a different place, and picking one
     * anyway is how a nexus counter fills up with states nobody sold in.
     *
     * @return list<SignalSource>
     */
    public function sourcesNaming(string $country): array
    {
        $wanted = strtoupper($country);

        return array_values(array_filter(
            [SignalSource::Declared, SignalSource::Payment, SignalSource::Ip],
            fn (SignalSource $source): bool => $this->normalized(match ($source) {
                SignalSource::Declared => $this->declared,
                SignalSource::Payment => $this->payment,
                SignalSource::Ip => $this->ip,
            }) === $wanted,
        ));
    }

    /**
     * The subdivision the sources that named this country agree on, or null.
     *
     * Agreement is required rather than first-wins. Two sources that both put the sale in the US while
     * naming different states have not established a state, and choosing one would be a guess recorded as
     * evidence — which is exactly what the country side refuses to do and for the same reason.
     */
    public function subdivisionFor(string $country): ?string
    {
        if (! $this->subdivisions instanceof SubdivisionSignals) {
            return null;
        }

        $named = array_values(array_filter(array_map(
            $this->subdivisions->from(...),
            $this->sourcesNaming($country),
        )));

        return count(array_unique($named)) === 1 ? $named[0] : null;
    }

    /**
     * Every country named, one entry per source that named it.
     *
     * Deliberately not deduplicated: the evidence standard counts SOURCES, not distinct answers. Three
     * sources agreeing is the strongest position there is, and a deduplicated count would report it as the
     * weakest — one — and refuse the sale.
     *
     * @return list<string>
     */
    public function spoken(): array
    {
        $codes = array_filter(
            [$this->declared, $this->payment, $this->ip],
            static fn (?string $c): bool => is_string($c) && $c !== '',
        );

        return array_values(array_map(strtoupper(...), $codes));
    }

    /**
     * The distinct countries named.
     *
     * @return list<string>
     */
    public function distinct(): array
    {
        return array_values(array_unique($this->spoken()));
    }

    /** How many sources named a country — what a two-evidence rule is measured against. */
    public function count(): int
    {
        return count($this->spoken());
    }

    /** Whether every source that spoke said the same thing. */
    public function agree(): bool
    {
        return count($this->distinct()) === 1;
    }

    /** One signal, upper-cased, or null. */
    public function normalized(?string $code): ?string
    {
        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }
}
