<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A provider-neutral snapshot of a credit note at the moment it was issued — the accounting document that
 * credits a finalized invoice when money goes back (a refund or a lost dispute). It carries everything the
 * package needs to persist a compliant record and render an EN 16931 credit note (type code 381) from it,
 * WITHOUT going back to the provider.
 *
 * The amounts are POSITIVE magnitudes: a credit note is not a negative invoice, it is a document whose
 * nature — not a sign — inverts the meaning. Its own number and provider id identify the credit note; the
 * `credits*` fields point at the invoice it credits, which is what makes it a credit note (BG-3 preceding
 * invoice reference) and sets the accounting direction. The buyer is the same buyer as the credited
 * invoice's — a credit note is issued to whoever the invoice was — and is filled from the frozen original
 * when the snapshot does not carry it. The lines and totals are the credit note's OWN, so a partial credit
 * (crediting one line, not the whole invoice) is represented faithfully.
 *
 * @phpstan-import-type BuyerSnapshot from InvoiceSnapshot
 * @phpstan-import-type LineSnapshot from InvoiceSnapshot
 */
final readonly class CreditNoteSnapshot
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
        public int $totalMinor,
        public int $subtotalMinor,
        public int $taxMinor,
        public ?int $issuedAt,
        public string $creditsProviderId,
        public ?string $creditsNumber = null,
        public array $buyer = [],
        public array $lines = [],
        // An intra-EU B2B reverse charge, carried so the credit note renders VAT category AE (not the
        // zero-rated Z a 0% rate would default to) — a credit note must match the tax treatment of the
        // invoice it credits. Authoritatively re-derived from the credited invoice when that row is known
        // (see PersistCreditNote); this snapshot value is the fallback for a locally-unknown original.
        public bool $reverseCharge = false,
    ) {}
}
