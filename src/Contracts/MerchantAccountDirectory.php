<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The receiving-side twin of {@see CustomerDirectory}: it resolves a provider merchant-account
 * reference carried on a webhook back to the local merchant, and looks up the stored account for a
 * merchant the application already holds.
 *
 * Both directions are needed and neither substitutes for the other. A webhook arrives with an account
 * id and no local identity; a checkout starts with a local merchant and needs the account id. Without
 * the first, an inbound event cannot be attributed; without the second, a routed payment cannot be
 * addressed.
 */
interface MerchantAccountDirectory
{
    /** The merchant behind a provider account reference, or null when none is on file. */
    public function merchantForReference(string $accountReference): ?Model;

    /** The stored provider account for a merchant, or null when the merchant has not onboarded. */
    public function accountFor(Model $merchant): ?MerchantAccountReference;
}
