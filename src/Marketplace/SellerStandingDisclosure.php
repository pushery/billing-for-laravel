<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\DescribesSellerStanding;
use Pushery\Billing\ValueObjects\SellerStandingConsequences;

/**
 * What a buyer has to be told about the seller of what they are buying, from the seller's recorded standing.
 *
 * Ask it where the buyer decides, at the checkout of a sale the platform arranges: whether the seller sells
 * as a business, and with it whether the buyer has consumer rights against them and receives an invoice. A
 * seller who declares a new standing changes all of it on the next sale, because all of it comes from this
 * one answer.
 */
final readonly class SellerStandingDisclosure
{
    public function __construct(
        private CreatorTaxStatusHold $standing,
        private DescribesSellerStanding $regime,
    ) {}

    public function for(Model $seller, ?CarbonImmutable $moment = null): SellerStandingConsequences
    {
        return $this->regime->consequencesOf($this->standing->statusFor($seller, $moment));
    }
}
