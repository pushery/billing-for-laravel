<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\InvoiceStatus;

/**
 * A provider-neutral snapshot of an invoice at the moment it was finalized — everything the package needs
 * to persist a compliant, immutable record and render an EN 16931 / XRechnung document or a DATEV booking
 * from it later, WITHOUT going back to the provider.
 *
 * It is frozen on purpose: the buyer's name and address, the line items and the tax split are captured as
 * they were on the invoice, because a valid invoice must carry them (§14 UStG) and must not change after
 * issue. The number is the PROVIDER'S invoice number — Stripe already assigns a gapless, unique one, and
 * minting a second of our own would give one invoice two numbers.
 *
 * @phpstan-type BuyerSnapshot array{name?: string, address?: string, postcode?: string, city?: string, country?: string, vat_id?: string, email?: string, reference?: string}
 * @phpstan-type LineSnapshot array{description?: string, quantity?: int|float, unit?: string, unit_price_minor?: int, net_minor?: int, tax_rate?: int|float}
 */
final readonly class InvoiceSnapshot
{
    /**
     * @param  BuyerSnapshot  $buyer
     * @param  list<LineSnapshot>  $lines
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public string $customerReference,
        public ?string $number,
        public string $currency,
        public InvoiceStatus $status,
        public int $totalMinor,
        public int $subtotalMinor,
        public int $taxMinor,
        public ?int $issuedAt,
        public array $buyer = [],
        public array $lines = [],
        // An intra-EU B2B reverse-charge supply: the buyer accounts for the VAT, the seller charges none.
        // The renderer needs this to emit VAT category AE with an exemption reason, not a zero-rated Z.
        public bool $reverseCharge = false,
        /**
         * How much of the customer's PROVIDER-held balance this invoice consumed, in minor units.
         *
         * Zero on a driver that holds no such balance, and zero on an invoice that consumed none — the two
         * are the same answer here on purpose: what a reader does with it is spend it down, and there is
         * nothing to spend in either case. The distinction that does matter, whether the provider CAN hold
         * a balance, is already the difference between a real CreditSync and the null one.
         *
         * Never negative. An invoice can also ADD to the balance, and a reader treating that as a debit
         * would take credit away at the moment the customer gained it.
         */
        public int $creditAppliedMinor = 0,
    ) {}
}
