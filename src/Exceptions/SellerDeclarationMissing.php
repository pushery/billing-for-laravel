<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A seller's sale of goods the platform would supply itself cannot be classified, for want of a declaration it may rely on.
 *
 * The platform treated as supplying the goods (Art. 14a VAT Directive) relies on what the seller declared, and it is
 * protected only where it can show it did and neither knew nor could have known the declaration was wrong (Art. 5c
 * Implementing Regulation 282/2011). Without a declaration there is nothing to rely on, and after a signal that
 * contradicts it the platform knows better, so in both cases the sale waits for the seller to declare.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SellerDeclarationMissing extends RuntimeException
{
    /** A seller who has never declared. */
    public static function forSeller(string $seller): self
    {
        return new self(
            "Seller [{$seller}] has declared nothing the platform could rely on. A sale of goods the platform is treated as "
            .'supplying rests on what the seller declared about its establishment, its goods and where they are dispatched '
            .'from, so record that declaration with SellerDeclarations::record() before the sale.'
        );
    }

    /** A seller whose latest declaration a later signal contradicts. */
    public static function contradicted(string $seller, string $signal, CarbonImmutable $observedAt): self
    {
        return new self(
            "A signal [{$signal}] observed at {$observedAt->toIso8601String()} contradicts what seller [{$seller}] declared "
            .'last, so the platform can no longer rely on that declaration. The seller\'s next sale waits until the seller has '
            .'declared again.'
        );
    }
}
