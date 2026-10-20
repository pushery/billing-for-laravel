<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Contracts\SuppliesSellerVatRegistrations;

/**
 * The shipped answer: the VAT number of the merchant's invoice party, where it belongs to the asked member state.
 *
 * A VAT number names its member state in its first two letters, Greece's as `EL`. A number from another member
 * state is not a registration in this one, so it answers null rather than lend it, and a document that needs the
 * registration is then refused rather than issued under the wrong number.
 */
final readonly class PartyVatRegistrations implements SuppliesSellerVatRegistrations
{
    public function __construct(private MerchantPartyResolver $parties) {}

    public function registrationIn(Model $merchant, string $country): ?string
    {
        $number = $this->parties->partyFor($merchant)->vatId;

        if ($number === null) {
            return null;
        }

        $number = strtoupper(trim($number));
        $prefix = strtoupper($country) === 'GR' ? 'EL' : strtoupper($country);

        return str_starts_with($number, $prefix) ? $number : null;
    }
}
