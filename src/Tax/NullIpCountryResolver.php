<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\IpCountryResolver;

/**
 * The shipped default: no country from an address, because the package carries no geolocation data.
 *
 * It is a real answer rather than a placeholder. Shipping a service would mean shipping a database, keeping
 * it current, and making every consumer carry that whether they need it or not; guessing would mean putting
 * a country on a tax document on the strength of nothing. So the default says "this signal has nothing to
 * say", and a consumer who wants it binds their own.
 *
 * It also never sees an address it could keep: the argument is used for nothing at all.
 */
final readonly class NullIpCountryResolver implements IpCountryResolver
{
    public function countryFor(string $ipAddress): ?string
    {
        return null;
    }
}
