<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * The add-on catalog and the anti-price-injection guarantee: a one-time purchase submits an add-on KEY,
 * never a price, and the server resolves the price from this allowlist — the same rule the tier catalog
 * follows. It is extracted to an interface so the catalog can be resolved and, in a marketplace, rebound the
 * way the merchant catalog rebinds the tier and plan catalogs — a single seam, mirrored rather than
 * reinvented.
 *
 * Scope: the CATALOG is platform-owned, and that is the whole of what this interface decides. A creator
 * selling their own one-time items has no catalog of their own here — the keys, the labels and the prices
 * come from the platform's configuration, exactly as the per-merchant tier and plan catalogs stop at the
 * catalog.
 *
 * This used to say the money path was missing too, and that stopped being true. `StripeOneTimeCharge` builds
 * `application_fee_amount` and `transfer_data.destination` and attaches them to the add-on checkout session
 * whenever a merchant resolves, so a platform-cataloged add-on is already routed to a creator's connected
 * account. What is open is whose CATALOG the item comes from, not whether the money can reach them.
 */
interface AddonCatalog
{
    /** @return list<string> the configured add-on keys, in order. */
    public function all(): array;

    public function exists(string $key): bool;

    public function label(string $key): string;

    public function priceFor(string $key): ?Money;

    public function providerPriceFor(string $key): ?string;

    public function grantsFor(string $key): ?UnitGrant;
}
