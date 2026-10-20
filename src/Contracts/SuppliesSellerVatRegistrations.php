<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's VAT registration in one member state.
 *
 * A seller established outside the Union whose goods are already in it is registered in the member state the goods
 * are in, and its supply to the platform that is treated as supplying the goods takes place there (Art. 31 and
 * Art. 272(1)(b) VAT Directive). The platform's self-billed invoice for that supply names the registration it was
 * made under (Art. 226(3)), which need not be the number the seller's invoice party carries: a seller can be
 * registered in several member states.
 *
 * The shipped binding answers from the merchant's invoice party, where its VAT number belongs to the asked member
 * state, and with null otherwise. An application that keeps a seller's registrations per member state binds its own.
 */
interface SuppliesSellerVatRegistrations
{
    /**
     * The seller's VAT identification number in this member state, or null where none is known.
     *
     * @param  string  $country  the member state, as an ISO 3166 code
     */
    public function registrationIn(Model $merchant, string $country): ?string;
}
