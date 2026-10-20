<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Models\TaxIdVerification;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * Where the platform's sale to a buyer stands on the buyer's document, and whether the cross-border consumer return
 * declares it, decided where that document is written.
 *
 * The return reads one flag on each document and nothing else, so a document issued without it is a sale the return
 * never sees. The flag follows the placement of the sale, the same answer {@see PlaceOfSupplyResolver} gives every
 * other issuing path: a buyer who is not a business, a supply the product places at the buyer, a buyer in another
 * member state than the platform, and that member state inside the union.
 *
 * What the buyer is comes from the one fact a business treatment may rest on, a tax id a register confirmed for that
 * owner ({@see StandingTaxIdVerification}). A business that never proved its id is a consumer for this purpose, as it
 * is for the place of supply, so its sale is declared in the return.
 *
 * Below the distance-sale threshold (Art. 59c VAT Directive) such a sale stays taxed where the platform is, so its
 * document states that place and no return of the destination declares it. The threshold is the installation's
 * {@see DistanceSaleThresholdMonitor}, which watches nothing while the operator's waiver stands.
 */
final readonly class OneStopShopDeclaration
{
    public function __construct(
        private PlaceOfSupplyResolver $places,
        private DistanceSaleThresholdMonitor $thresholds,
    ) {}

    /**
     * The place of supply the document states and whether the return declares the sale.
     *
     * The place is the product's own rule, moved to the platform only by the threshold. A sale the return does not
     * declare keeps the product's rule as it is.
     *
     * @param  ?PlaceOfSupplyRule  $rule  where the product places the supply, or null where nothing settled it
     * @param  ?string  $destinationCountry  where the buyer is, as an ISO 3166 code, or null where nobody said
     * @param  Money  $net  the sale's net, which counts towards the threshold
     * @return array{?PlaceOfSupplyRule, bool}
     */
    public function placed(?PlaceOfSupplyRule $rule, ?string $destinationCountry, Model $buyer, Money $net, CarbonImmutable $soldOn): array
    {
        if (! $this->declares($rule, $destinationCountry, $buyer)) {
            return [$rule, false];
        }

        return $this->thresholds->keepsAtTheSeller($soldOn->year, $net->currency, $net->minorUnits) ? [PlaceOfSupplyRule::Domestic, false] : [$rule, true];
    }

    /** Whether the placement of the sale belongs in the cross-border consumer return, before the threshold is asked. */
    private function declares(?PlaceOfSupplyRule $rule, ?string $destinationCountry, Model $buyer): bool
    {
        if (! $rule instanceof PlaceOfSupplyRule || $destinationCountry === null || trim($destinationCountry) === '') {
            return false;
        }

        $key = $buyer->getKey();
        $verified = is_int($key) || is_string($key) ? new StandingTaxIdVerification()->of($buyer->getMorphClass(), $key) : null;

        $context = new TaxContext(
            countryCode: strtoupper(trim($destinationCountry)),
            vatId: $verified?->value,
            business: $verified instanceof TaxIdVerification,
            vatIdValid: $verified instanceof TaxIdVerification,
        );

        return $this->places->place($rule, $context)->reportableUnderOneStopShop;
    }
}
