<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\CardPresentPayments;
use Pushery\Billing\Contracts\PairsReadersByTheirCode;
use Pushery\Billing\Enums\InPersonSaleStatus;
use Pushery\Billing\Exceptions\ReaderUnavailable;
use Pushery\Billing\Models\InPersonSaleRecord;
use Pushery\Billing\Tax\SaleTaxDecision;
use Pushery\Billing\ValueObjects\InPersonCollection;
use Pushery\Billing\ValueObjects\InPersonSale;
use Pushery\Billing\ValueObjects\TaxContext;
use Pushery\Billing\ValueObjects\TerminalReader;
use Stripe\StripeClient;
use Stripe\Terminal\Location;
use Stripe\Terminal\Reader;

/**
 * Card payments in person, through Stripe Terminal's server-driven integration.
 *
 * The server puts a payment on a smart reader, and the reader takes the card. The package needs no code on the
 * reader or in a browser for that, and it is also why an offline reader is refused rather than queued: the server
 * can only reach a reader that is online.
 *
 * The payment is created for `card_present` alone, so the reader offers nothing else to pay with. The sale and the
 * tax decided for it are kept in the package's own table before the reader is asked; the metadata only marks the
 * payment as a counter sale, so its confirmation is routed to that row.
 */
final readonly class StripeCardPresentPayments implements CardPresentPayments, PairsReadersByTheirCode
{
    /** The metadata key that marks a payment as a sale at the counter. The confirmation reads it. */
    public const string SALE_MARKER = 'in_person_sale';

    public function __construct(
        private StripeClient $stripe,
        private SaleTaxDecision $tax,
    ) {}

    public function pairReader(string $registrationCode, string $location, ?string $label = null): TerminalReader
    {
        $reader = $this->stripe->terminal->readers->create(array_filter([
            'registration_code' => $registrationCode,
            'location' => $location,
            'label' => $label,
        ], static fn (?string $value): bool => $value !== null));

        // The reader comes back with its location as an id. Its country is read from the location, because the
        // country is what every sale on the reader is placed by.
        return $this->readerFrom($reader, $this->stripe->terminal->locations->retrieve($location));
    }

    public function reader(string $reader): TerminalReader
    {
        $found = $this->stripe->terminal->readers->retrieve($reader, ['expand' => ['location']]);

        return $this->readerFrom($found, $found->location instanceof Location ? $found->location : null);
    }

    public function collect(string $reader, InPersonSale $sale): InPersonCollection
    {
        $terminal = $this->reader($reader);

        if (! $terminal->online) {
            throw ReaderUnavailable::offline($reader);
        }

        if ($terminal->busy) {
            throw ReaderUnavailable::busy($reader);
        }

        if ($terminal->country === null) {
            throw ReaderUnavailable::withoutCountry($reader);
        }

        $soldAt = $terminal->country;

        // Decided before the payment exists, so a sale the tax model refuses never reaches the reader. An anonymous
        // buyer is placed at the counter: the place of goods handed over there does not depend on who buys them.
        $tax = $this->tax->decideOnGross(
            $sale->sold,
            $sale->gross,
            $sale->buyer ?? new TaxContext(countryCode: $soldAt),
            soldAlongside: $sale->soldAlongside,
            soldAt: $soldAt,
        );

        $intent = $this->stripe->paymentIntents->create(
            [
                'amount' => $sale->gross->minorUnits,
                'currency' => strtolower($sale->gross->currency),
                'payment_method_types' => ['card_present'],
                'capture_method' => 'automatic',
                'metadata' => array_filter([
                    self::SALE_MARKER => '1',
                    'sold_at' => $soldAt,
                    'reference' => $sale->reference,
                ], static fn (?string $value): bool => $value !== null),
            ],
            $sale->reference === null ? [] : ['idempotency_key' => 'in-person-sale:'.$sale->reference],
        );

        // Kept before the reader is asked, so a confirmation can never arrive for a sale the package has no row for.
        // A retried sale reaches the same payment through its idempotency key, and the same row through this key.
        InPersonSaleRecord::model()::query()->firstOrCreate(
            ['provider' => 'stripe', 'payment_reference' => $intent->id],
            [
                'reader' => $reader,
                'sold_at' => $soldAt,
                'description' => $sale->description,
                'tax_archetype' => $sale->sold,
                'sold_alongside_archetype' => $sale->soldAlongside,
                'currency' => $sale->gross->currency,
                'gross_minor' => $sale->gross->minorUnits,
                'tax_minor' => $tax->tax->minorUnits,
                'tax_rate_bps' => $tax->rateBps,
                'tax_rate_category' => $tax->rateCategory,
                'place_of_supply_rule' => $tax->placeRule(),
                'tax_exemption_reason' => $tax->exemption,
                'reference' => $sale->reference,
                'status' => InPersonSaleStatus::Pending,
            ],
        );

        $this->stripe->terminal->readers->processPaymentIntent($reader, ['payment_intent' => $intent->id]);

        return new InPersonCollection(
            paymentReference: $intent->id,
            reader: $reader,
            soldAt: $soldAt,
            gross: $sale->gross,
            tax: $tax,
        );
    }

    public function cancel(string $reader): void
    {
        $this->stripe->terminal->readers->cancelAction($reader);
    }

    private function readerFrom(Reader $reader, ?Location $location): TerminalReader
    {
        $country = $location?->address->country ?? null;
        $locationId = $reader->location instanceof Location ? $reader->location->id : $reader->location;

        return new TerminalReader(
            id: $reader->id,
            label: $reader->label ?? null,
            location: is_string($locationId) ? $locationId : null,
            country: is_string($country) && $country !== '' ? strtoupper($country) : null,
            online: ($reader->status ?? null) === Reader::STATUS_ONLINE,
            busy: ($reader->action->status ?? null) === 'in_progress',
            deviceType: (string) ($reader->device_type ?? ''),
        );
    }
}
