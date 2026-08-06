<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The onboarding of a driver that does not route money: there is none.
 *
 * It REFUSES rather than returning an empty account, and the difference is the point. An empty
 * MerchantAccountReference would be a merchant with an account id nobody can pay, carried onward until a
 * charge is addressed to it; the refusal names the actual problem — the driver has no receiving side —
 * at the moment somebody first asks for one.
 */
final readonly class NullMerchantOnboarding implements MerchantOnboarding
{
    public function __construct(private string $driver) {}

    public function createAccount(Model $merchant): MerchantAccountReference
    {
        throw MarketplaceUnsupported::driverCannotRoute($this->driver);
    }

    public function onboardingLink(Model $merchant, string $refreshUrl, string $returnUrl): ClientIntent
    {
        throw MarketplaceUnsupported::driverCannotRoute($this->driver);
    }
}
