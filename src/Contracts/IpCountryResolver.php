<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Turns a network address into a country code — and gives back nothing else.
 *
 * The narrowness IS the privacy design. A resolver that returned a richer object, or that took a request
 * and read the address itself, would give the address somewhere to travel: into a model attribute, a cache
 * key, a log line, a queued payload, an exception context. Each of those is a place nobody thinks of as
 * storage until an access request asks what is held about somebody.
 *
 * So the address goes in as an argument and a two-letter code comes out. There is no accessor for what was
 * asked, and nothing downstream is ever handed the address to begin with.
 *
 * The package ships NO geolocation service and no database. Without an implementation bound there is simply
 * no signal from this direction — which is a missing input, not a failure: other signals answer the same
 * question, and one fewer of them is a weaker answer rather than a broken one.
 */
interface IpCountryResolver
{
    /**
     * The ISO 3166-1 alpha-2 country for an address, or null when it cannot be determined.
     *
     * Null is an ordinary answer. A private address, an anonymizing proxy, a database that has never heard
     * of a range — all of them mean "this signal has nothing to say", and a resolver that guessed instead
     * would put a country on a document on the strength of nothing.
     */
    public function countryFor(string $ipAddress): ?string;
}
