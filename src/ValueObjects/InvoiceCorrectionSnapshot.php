<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Exceptions\InvalidInvoiceCorrection;

/**
 * A provider-neutral snapshot of an invoice correction at the moment it was issued — the accounting
 * document that corrects a finalized invoice when money goes back or an earlier invoice was wrong. It
 * carries everything the package needs to persist a compliant record and render an EN 16931 correcting
 * document from it, WITHOUT going back to the provider.
 *
 * This is deliberately NOT called a "credit note": that term is reserved for the self-billing document
 * (§ 14 Abs. 2 S. 5 UStG, type code 389), which is a different thing entirely. A correction is one of two
 * roles ({@see InvoiceCorrectionKind}): a Cancellation (Storno, type code 381) or an Amendment
 * (Rechnungsberichtigung, type code 384). The role — not a boolean — is what an e-invoice writer selects
 * the type code on.
 *
 * The amounts are POSITIVE magnitudes: a correction is not a negative invoice, it is a document whose
 * nature inverts the meaning. A negative amount is refused at construction rather than passed through. Its
 * own number and provider id identify the correction; the `credits*` fields point at the invoice it
 * corrects (BG-3 preceding-invoice reference) and set the accounting direction — and for an Amendment that
 * reference is MANDATORY, so an amendment built without one is refused here rather than rendered as an
 * invalid 384. The buyer is the same buyer as the corrected invoice's and is filled from the frozen
 * original when the snapshot does not carry it. The lines and totals are the correction's OWN, so a partial
 * correction (one line, not the whole invoice) is represented faithfully.
 *
 * @phpstan-import-type BuyerSnapshot from InvoiceSnapshot
 * @phpstan-import-type LineSnapshot from InvoiceSnapshot
 */
final readonly class InvoiceCorrectionSnapshot
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
        // An intra-EU B2B reverse charge, carried so the correction renders VAT category AE (not the
        // zero-rated Z a 0% rate would default to) — a correction must match the tax treatment of the
        // invoice it corrects. Authoritatively re-derived from the corrected invoice when that row is known
        // (see PersistInvoiceCorrection); this snapshot value is the fallback for a locally-unknown original.
        public bool $reverseCharge = false,
        // The correcting document's role. Defaults to Cancellation (type code 381), the only role that
        // exists today; an Amendment (384) additionally requires an origin reference, enforced below.
        public InvoiceCorrectionKind $kind = InvoiceCorrectionKind::Cancellation,
    ) {
        foreach (['totalMinor' => $totalMinor, 'subtotalMinor' => $subtotalMinor, 'taxMinor' => $taxMinor] as $field => $value) {
            if ($value < 0) {
                throw InvalidInvoiceCorrection::negativeAmount($field, $value);
            }
        }

        // An amendment (384) must carry a usable reference to the invoice it corrects (BG-3): the credited
        // invoice's own number when known, else its provider id — so at least one must be present. A
        // cancellation (381) has no such hard requirement.
        if ($kind === InvoiceCorrectionKind::Amendment && $creditsProviderId === '' && ($creditsNumber === null || $creditsNumber === '')) {
            throw InvalidInvoiceCorrection::amendmentWithoutReference();
        }
    }
}
