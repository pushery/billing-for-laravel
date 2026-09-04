<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use RuntimeException;

/**
 * A merchant's invoice party was asked for, but no resolver knows how to read it.
 *
 * A self-billed document names the merchant as the seller, so it cannot be issued without the merchant's
 * legal name and address — which live in the consuming application's own schema, not in this package. The
 * shipped resolver fails closed here rather than issue a document with no seller. Bind a
 * {@see MerchantPartyResolver} that reads your merchants before self-billing.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MerchantPartyUnavailable extends RuntimeException
{
    public static function forMerchant(Model $merchant): self
    {
        return new self(sprintf(
            'No merchant party resolver is bound, so the identity of a [%s] merchant cannot be read for a '
            .'self-billed document. A self-billed document names the merchant as seller and needs their legal '
            .'name and registered address, which live in your application, not this package. Bind a '
            .'MerchantPartyResolver before self-billing.',
            $merchant->getMorphClass(),
        ));
    }
}
