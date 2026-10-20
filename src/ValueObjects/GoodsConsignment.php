<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\TaxationBasis;

/**
 * The facts about a sale of goods that decide whether the platform is treated as their supplier.
 *
 * A platform that facilitates a sale of goods through its interface is, in two cases, treated as having bought
 * and sold them itself (Art. 14a VAT Directive, in Germany § 3 Abs. 3a UStG): goods imported in a consignment of
 * an intrinsic value up to 150 euros, whoever sells them, and goods already in the Union, sold by a seller
 * established outside it to somebody who is not a business. Which of the two applies, and whether either does,
 * turns on facts no archetype carries: where the transport begins, what the consignment is worth and what kind
 * of goods it holds. The lane states them here, once per sale.
 *
 * ## Why the origin is a flag and not a country
 *
 * Imported is the legal fact, and a country code cannot say it. The Canary Islands are Spanish and outside the
 * Union's VAT territory, so a consignment from Tenerife to Madrid is imported, while goods held in a customs
 * warehouse inside the Union count as being in it. The lane knows where its stock is; a lookup by country code
 * would get both of those wrong.
 *
 * ## Why the value is stated in euros
 *
 * The threshold is measured on the intrinsic value: the price of the goods themselves, without transport and
 * insurance costs stated separately and without the taxes customs can ascertain. A value in another currency
 * converts at the rate customs applies, which this package does not know, so it is refused rather than
 * converted at a rate customs would not recognize.
 */
final readonly class GoodsConsignment
{
    /** The highest intrinsic value, in euro cents, an imported consignment may have and still be a deemed supply. */
    public const int IMPORT_THRESHOLD_MINOR = 150_00;

    public function __construct(
        /** Whether the transport begins outside the Union's VAT territory, so the goods enter it with this sale. */
        public bool $imported,
        /** The consignment's intrinsic value in euros. Required for an imported consignment. */
        public ?Money $intrinsicValue = null,
        /** A new vehicle, vessel or aircraft in the sense of Art. 2(2) VAT Directive. */
        public bool $newMeansOfTransport = false,
        /** Goods supplied after installation or assembly, by the seller or on the seller's behalf. */
        public bool $installedOrAssembled = false,
        /** Goods subject to excise duty: alcohol, tobacco and energy products. */
        public bool $subjectToExcise = false,
        /** The basis the sale was declared under, where the seller declared one. */
        public ?TaxationBasis $basis = null,
        /**
         * For goods already in the Union, the member state they are in when the transport begins, as an ISO 3166 code.
         *
         * It places the seller's supply to the platform and decides whether the platform's supply to the buyer is a
         * distance sale, so both documents of a deemed supply read it from here. Not read for an imported consignment,
         * whose transport begins outside the Union.
         */
        public ?string $goodsLocatedIn = null,
        /**
         * What the consignment holds, as the buyer's document names it.
         *
         * The records the platform keeps as the supplier of a deemed supply describe the goods and their quantity
         * (Art. 63c Implementing Regulation 282/2011), so a sale the platform makes itself needs it.
         */
        public ?string $description = null,
        /** How many of them the consignment holds. */
        public int $quantity = 1,
    ) {
        if ($description !== null && trim($description) === '') {
            throw new InvalidArgumentException('A consignment that describes its goods names them; an empty description names nothing.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException("A consignment holds at least one item; got {$quantity}.");
        }

        if ($goodsLocatedIn !== null && preg_match('/^[A-Z]{2}$/', $goodsLocatedIn) !== 1) {
            throw new InvalidArgumentException(
                "State where the goods are as a country of two capital letters of ISO 3166; got [{$goodsLocatedIn}]."
            );
        }

        if ($imported && ! $intrinsicValue instanceof Money) {
            throw new InvalidArgumentException(
                'An imported consignment needs its intrinsic value: the 150-euro threshold of Art. 14a(1) VAT '
                .'Directive is measured on it.'
            );
        }

        if ($intrinsicValue instanceof Money && $intrinsicValue->currency !== 'EUR') {
            throw new InvalidArgumentException(
                "State the intrinsic value of a consignment in euros; got {$intrinsicValue->currency}. It converts "
                .'at the rate customs applies, which this package does not know.'
            );
        }

        if ($intrinsicValue instanceof Money && $intrinsicValue->isNegative()) {
            throw new InvalidArgumentException(
                "A consignment cannot be worth less than nothing; got {$intrinsicValue->minorUnits} EUR cents."
            );
        }
    }

    /** Whether the consignment is imported and worth no more than the 150 euros Art. 14a(1) reaches. */
    public function importedWithinTheThreshold(): bool
    {
        return $this->imported
            && $this->intrinsicValue instanceof Money
            && $this->intrinsicValue->minorUnits <= self::IMPORT_THRESHOLD_MINOR;
    }

    /**
     * The consignment as the row of a sale keeps it.
     *
     * @return array{imported: bool, intrinsic_value_minor: ?int, new_means_of_transport: bool, installed_or_assembled: bool, subject_to_excise: bool, basis: ?string, goods_located_in: ?string, description: ?string, quantity: int}
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'intrinsic_value_minor' => $this->intrinsicValue?->minorUnits,
            'new_means_of_transport' => $this->newMeansOfTransport,
            'installed_or_assembled' => $this->installedOrAssembled,
            'subject_to_excise' => $this->subjectToExcise,
            'basis' => $this->basis?->value,
            'goods_located_in' => $this->goodsLocatedIn,
            'description' => $this->description,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * The consignment a row of a sale kept, or one stated in configuration in the same shape.
     *
     * A key that is missing reads as the default the constructor gives it, and a key of the wrong type is refused
     * rather than read as something it does not say. The intrinsic value is in euro cents, as every value here is.
     *
     * @param  array<array-key, mixed>  $facts
     */
    public static function fromArray(array $facts): self
    {
        $value = $facts['intrinsic_value_minor'] ?? null;
        $basis = $facts['basis'] ?? null;
        $locatedIn = $facts['goods_located_in'] ?? null;
        $description = $facts['description'] ?? null;
        $quantity = $facts['quantity'] ?? 1;

        if (($value !== null && ! is_int($value)) || ($basis !== null && ! is_string($basis)) || ($locatedIn !== null && ! is_string($locatedIn))
            || ($description !== null && ! is_string($description)) || ! is_int($quantity)) {
            throw new InvalidArgumentException(
                'A consignment states its intrinsic value as whole euro cents, its basis as a taxation basis, where '
                .'the goods are as a country code, their description as text and their quantity as a whole number.'
            );
        }

        return new self(
            imported: self::flag($facts, 'imported'),
            intrinsicValue: $value === null ? null : Money::of($value, 'EUR'),
            newMeansOfTransport: self::flag($facts, 'new_means_of_transport'),
            installedOrAssembled: self::flag($facts, 'installed_or_assembled'),
            subjectToExcise: self::flag($facts, 'subject_to_excise'),
            basis: $basis === null ? null : TaxationBasis::from($basis),
            goodsLocatedIn: $locatedIn,
            description: $description,
            quantity: $quantity,
        );
    }

    /**
     * One of the yes-or-no facts, false where the key is absent.
     *
     * @param  array<array-key, mixed>  $facts
     */
    private static function flag(array $facts, string $key): bool
    {
        $value = $facts[$key] ?? false;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("A consignment states [{$key}] as true or false.");
        }

        return $value;
    }
}
